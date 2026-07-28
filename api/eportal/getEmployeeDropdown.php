<?php

ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json");

try {

    /*
    =========================================
    SESSION VALIDATION
    =========================================
    */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (!$empCode) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /*
    =========================================
    FETCH EMPLOYEES
    =========================================
    */

    $sql = "
        SELECT 
            EMP_CODE,
            (EMP_CODE || ' - ' || EMP_FNAME || ' ' || EMP_LNAME) AS EMP_NAME
        FROM EPT_BCS_EMPLOYEE
        WHERE STATUS = 'A'
        ORDER BY EMP_NAME
    ";
    $employees = multiRec($sql);

    /*
    =========================================
    SUCCESS RESPONSE
    =========================================
    */
    apiResponse(true, "Employees fetched successfully", $employees, 200);
}

catch (Throwable $e) {
    /*
    =========================================
    ERROR LOGGING
    =========================================
    */

    logOracleError($e);
    apiResponse(false, "Unable to fetch employees", null, 500);
}
