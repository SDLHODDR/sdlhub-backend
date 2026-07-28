<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../itr/getEmployeeSummaryData.php";

/* =====================================================
   SESSION VALIDATION
===================================================== */

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

try {

    /* =====================================================
       FETCH EMPLOYEE SUMMARY
    ===================================================== */

    $result = getEmployeeSummaryData($empCode);

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(true, "Employee summary fetched successfully.",  $result);

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch employee summary.", null, 500);
}