<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /*
    |--------------------------------------------------------------------------
    | METHOD CHECK
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION CHECK
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access.", null, 401);
    }

    $empCode = trim($_SESSION["emp_code"]);

    /*
    |--------------------------------------------------------------------------
    | VERIFY EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '".$empCode."'
    ");

    $empId = $employee["ID"] ?? null;

    if (!$empId) {
        apiResponse(false, "Profile not found.", null, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | RELEASE SESSION LOCK
    |--------------------------------------------------------------------------
    */

    session_write_close();

    /*
    |--------------------------------------------------------------------------
    | CURRENT FINANCIAL YEAR
    |--------------------------------------------------------------------------
    */

    $year  = date("Y");
    $month = date("m");

    $fy = ($month >= 4)
        ? $year . "-" . substr($year + 1, -2)
        : ($year - 1) . "-" . substr($year, -2);

    /*
    |--------------------------------------------------------------------------
    | FETCH LATEST REMARK
    |--------------------------------------------------------------------------
    */

    $row = singRec("
        SELECT
            REMARKS,
            EPT_GET_EMP_NAME(ACTIONED_BY) AS ACCT_NAME
        FROM (
            SELECT
                REMARKS,
                ACTIONED_BY
            FROM EPT_BCS_ITAX_EMP_REGIME
            WHERE FY = '".$fy."'
            AND EMP_ID = '".$empId."'
            AND REMARKS IS NOT NULL
            ORDER BY ID DESC
        )
        WHERE ROWNUM = 1
    ");

    /*
    |--------------------------------------------------------------------------
    | FORMAT RESPONSE
    |--------------------------------------------------------------------------
    */

    $data = null;

    if (!empty($row)) {
        $data = [
            "remarks" => $row["REMARKS"],
            "by"       => $row["ACCT_NAME"]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Remarks fetched successfully.",
        $data
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    logOracleError($e);

    apiResponse(
        false,
        "Unable to fetch remarks.",
        null,
        500
    );

} finally {

    /*
    |--------------------------------------------------------------------------
    | CLOSE CONNECTION
    |--------------------------------------------------------------------------
    */

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }

}