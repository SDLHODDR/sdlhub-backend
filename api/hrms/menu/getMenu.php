<?php
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    apiResponse(false, "Invalid request method", null, 405);
}

/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$empCode = $_SESSION["emp_code"]
    ?? $_SESSION["EmpCode"]
    ?? null;

if (!$empCode) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

session_write_close();

try {

    /*
    |--------------------------------------------------------------------------
    | GET MENU + SUB MENU
    |--------------------------------------------------------------------------
    */

    $sql = "

        SELECT
            M.ID AS MENU_ID,
            M.LABEL AS MENU_LABEL,
            M.ICON AS MENU_ICON,
            M.SEQ AS MENU_SEQ,

            MS.ID AS SUB_MENU_ID,
            MS.LABEL AS SUB_MENU_LABEL,
            MS.PROG_URL,
            MS.SEQ AS SUB_MENU_SEQ

        FROM HR_MENU M

        INNER JOIN HR_MENU_SUB MS
            ON MS.MENU_ID = M.ID

        INNER JOIN HR_PROFILE_MENU PM
            ON PM.SUB_MENU_ID = MS.ID

        INNER JOIN HR_EMP_PROFILE EP
            ON EP.PROFILE_ID = PM.PROFILE_ID

        INNER JOIN HR_EMPLOYEE_INFO EI
            ON EI.EMP_CODE = EP.EMP_CODE

        WHERE
            EI.EMP_CODE = :emp_code

            AND EI.STATUS = 'A'

            AND SYSDATE BETWEEN
                EP.EFFEC_FROM
                AND NVL(
                    EP.EFFEC_TO,
                    DATE '3000-03-01'
                )

            AND MS.STATUS = 'A'

        ORDER BY
            M.SEQ,
            MS.SEQ

    ";

    $stmt = oci_parse(
        $sql___func___con,
        $sql
    );

    if (!$stmt) {

        $error = oci_error($sql___func___con);

        throw new Exception(
            $error["message"]
        );
    }

    oci_bind_by_name(
        $stmt,
        ":emp_code",
        $empCode
    );

    if (!oci_execute($stmt)) {

        $error = oci_error($stmt);

        throw new Exception(
            $error["message"]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD MENU TREE
    |--------------------------------------------------------------------------
    */

    $menus = [];

    while ($row = oci_fetch_assoc($stmt)) {

        $menuId = $row["MENU_ID"];

        if (!isset($menus[$menuId])) {

            $menus[$menuId] = [
                "id" => $menuId,
                "label" => $row["MENU_LABEL"],
                "icon" => trim(
                    $row["MENU_ICON"] ?? ""
                ),
                "children" => []
            ];
        }

        $menus[$menuId]["children"][] = [
            "id" => $row["SUB_MENU_ID"],
            "label" => $row["SUB_MENU_LABEL"],
            "url" => $row["PROG_URL"]
        ];
    }

    oci_free_statement($stmt);

    /*
    |--------------------------------------------------------------------------
    | RESET NUMERIC KEYS
    |--------------------------------------------------------------------------
    */

    $menus = array_values($menus);

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "HRMS menu fetched successfully",
        $menus
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(
        false,
        "Unable to fetch HRMS menu.",
        null,
        500
    );

} finally {

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}