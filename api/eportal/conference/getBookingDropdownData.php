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
        apiResponse(false, "Invalid request method", null, 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION CHECK
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access", null, 401);
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
    | EMPLOYEES
    |--------------------------------------------------------------------------
    */

    $employees = multiRec("
        SELECT
            EMP_CODE,
            (
                EMP_CODE
                || ' - '
                || EMP_FNAME
                || ' '
                || EMP_LNAME
            ) AS EMP_NAME
        FROM EPT_BCS_EMPLOYEE
        WHERE STATUS = 'A'
        ORDER BY EMP_NAME
    ");

    /*
    |--------------------------------------------------------------------------
    | DIVISIONS
    |--------------------------------------------------------------------------
    */

    $divisions = multiRec("
        SELECT
            DIVSN_ID,
            DIVSN_DESC
        FROM EPT_HR_DIVISIONS
        ORDER BY DIVSN_DESC
    ");

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Dropdowns fetched successfully.",
        [
            "employees" => $employees,
            "divisions" => $divisions
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch dropdown data.", null, 500);

} finally {

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }

}