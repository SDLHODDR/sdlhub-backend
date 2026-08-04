<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$empCode = $_SESSION["emp_code"] ?? null;

if (!$empCode) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

/*
|--------------------------------------------------------------------------
| RELEASE SESSION LOCK
|--------------------------------------------------------------------------
*/

session_write_close();

try {

    /*
    |--------------------------------------------------------------------------
    | GET BIRTHDAY GROUPS
    |--------------------------------------------------------------------------
    */

    $procGrp = singRec("
        SELECT VALUE
        FROM EPT_BCS_SYS_PARAMS
        WHERE KEY = 'BDAY_GRP'
    ");

    if (!$procGrp || empty($procGrp["VALUE"])) {
        apiResponse(true, "Success", []);
    }

    $groups = array_filter(
        array_map("trim", explode(",", $procGrp["VALUE"]))
    );

    if (empty($groups)) {
        apiResponse(true, "Success", []);
    }

    /*
    |--------------------------------------------------------------------------
    | GROUP STRING
    |--------------------------------------------------------------------------
    */

    $groupStr = "'" . implode(
        "','",
        array_map("addslashes", $groups)
    ) . "'";

    /*
    |--------------------------------------------------------------------------
    | DATE RANGE
    |--------------------------------------------------------------------------
    */

    $today = date("md");
    $next7 = date("md", strtotime("+7 days"));

    if ($today <= $next7) {

        $dateCondition = "
            TO_NUMBER(TO_CHAR(be.birth_date, 'MMDD'))
            BETWEEN $today AND $next7
        ";

    } else {

        $dateCondition = "
            TO_NUMBER(TO_CHAR(be.birth_date, 'MMDD')) >= $today
            OR TO_NUMBER(TO_CHAR(be.birth_date, 'MMDD')) <= $next7
        ";
    }

    /*
    |--------------------------------------------------------------------------
    | MAIN QUERY
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            be.emp_code,
            (be.emp_fname || ' ' || SUBSTR(be.emp_lname, 1, 1)) AS emp_name,
            be.birth_date,
            TO_CHAR(be.birth_date, 'DD-Mon') AS bmonth,
            eum.message
        FROM ept_bcs_employee be

        LEFT JOIN ept_user_messages eum
            ON be.emp_code = eum.created_for
            AND eum.created_by = '$empCode'
            AND TO_CHAR(eum.created_on, 'YYYY') = TO_CHAR(SYSDATE, 'YYYY')

        WHERE
            be.status = 'A'
            AND be.proc_group IN ($groupStr)
            AND ($dateCondition)

        ORDER BY
            TO_DATE(
                TO_CHAR(be.birth_date, 'DD-Mon'),
                'DD-Mon'
            )
    ";

    /*
    |--------------------------------------------------------------------------
    | FETCH DATA
    |--------------------------------------------------------------------------
    */

    $rows = multiRec($sql);

    /*
    |--------------------------------------------------------------------------
    | GROUP DATA
    |--------------------------------------------------------------------------
    */

    $result = [];

    foreach ($rows as $row) {

        $key = $row["BMONTH"];

        if (!isset($result[$key])) {
            $result[$key] = [];
        }

        $result[$key][] = [
            "emp_code" => $row["EMP_CODE"],
            "name" => ucwords(
                strtolower($row["EMP_NAME"])
            ),
            "birth_date" => $row["BIRTH_DATE"],
            "message" => $row["MESSAGE"],
            "profile_image" => getProfileUrl(
                $row["EMP_CODE"]
            )
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Upcoming birthdays fetched successfully",
        $result
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(
        false,
        "Unable to fetch upcoming birthdays.",
        null,
        500
    );

} finally {

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}

/*
|--------------------------------------------------------------------------
| PROFILE IMAGE
|--------------------------------------------------------------------------
*/

function getProfileUrl($empCode)
{
    $publicPath = realpath(
        __DIR__ . "/../../../../public"
    );

    if (!$publicPath) {
        return null;
    }

    $relativePath = "/assets/img/profiles/{$empCode}.jpg";

    $absolutePath = $publicPath . $relativePath;

    return file_exists($absolutePath)
        ? $relativePath
        : null;
}