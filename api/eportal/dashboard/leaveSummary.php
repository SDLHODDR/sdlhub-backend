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

    if (empty($employee["ID"])) {
        apiResponse(false, "Employee not found.", null, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | RELEASE SESSION LOCK
    |--------------------------------------------------------------------------
    */

    session_write_close();

    /*
    |--------------------------------------------------------------------------
    | LEAVE SUMMARY
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            LVE_CODE,
            NVL(CONS_DAYS, 0) AS CONS_DAYS,
            NVL(BAL_DAYS, 0) AS BAL_DAYS
        FROM EPT_BCS_LEAVE_BALANCE LB
        WHERE EMP_CODE = '".$empCode."'
        AND LVE_CODE <> 'LWP'
        AND (
            SELECT MAX(UPTO_DATE)
            FROM EPT_BCS_LEAVE_BALANCE
            WHERE EMP_CODE = '".$empCode."'
        ) BETWEEN LB.EFF_DATE AND (LB.UPTO_DATE + 1)
        ORDER BY LVE_CODE
    ";

    $rows = multiRec($sql);

    /*
    |--------------------------------------------------------------------------
    | FORMAT RESPONSE
    |--------------------------------------------------------------------------
    */

    $result = [];

    foreach ($rows as $row) {

        $result[] = [
            "type" => $row["LVE_CODE"],
            "consumed" => (float) $row["CONS_DAYS"],
            "balance" => (float) $row["BAL_DAYS"]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Leave summary fetched successfully.",
        $result
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
        "Unable to fetch leave summary.",
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