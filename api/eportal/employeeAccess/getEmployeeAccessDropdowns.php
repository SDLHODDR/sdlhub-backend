<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/emp_func.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       COMPANY LIST
    =========================================== */

    $companyData = multiRec("
        SELECT
            COMP_ID,
            COMP_ID || ' - ' || COMP_DESC AS COMP_NAME
        FROM EPT_HR_COMPANY
        ORDER BY COMP_ID
    ");

    $companies = [];

    foreach ($companyData as $row) {
        $companies[] = [
            "value" => $row["COMP_ID"],
            "label" => $row["COMP_NAME"]
        ];
    }

    /* ===========================================
       DIVISION LIST
    =========================================== */

    $divisionData = multiRec("
        SELECT
            DIVSN_ID,
            DIVSN_ID || ' - ' || DIVSN_DESC AS DIVSN_NAME
        FROM EPT_HR_DIVISIONS
        ORDER BY DIVSN_DESC
    ");

    $divisions = [];

    foreach ($divisionData as $row) {
        $divisions[] = [
            "value" => $row["DIVSN_ID"],
            "label" => $row["DIVSN_NAME"]
        ];
    }

    /* ===========================================
       DEPARTMENT LIST
    =========================================== */

    $departmentData = multiRec("
        SELECT
            DEPT_ID,
            DEPT_ID || ' - ' || DEPT_DESC AS DEPT_NAME
        FROM EPT_HR_DEPARTMENT
        ORDER BY DEPT_DESC
    ");

    $departments = [];

    foreach ($departmentData as $row) {
        $departments[] = [
            "value" => $row["DEPT_ID"],
            "label" => $row["DEPT_NAME"]
        ];
    }

    /* ===========================================
       EMPLOYEE LIST
    =========================================== */

    $employeeData = multiRec("
        SELECT
            EMP_CODE,
            EMP_CODE || ' - ' || EMP_FNAME || ' ' || EMP_LNAME AS EMP_NAME
        FROM EPT_BCS_EMPLOYEE
        WHERE STATUS = 'A'
        ORDER BY EMP_NAME
    ");

    $employees = [];

    foreach ($employeeData as $row) {
        $employees[] = [
            "value" => $row["EMP_CODE"],
            "label" => $row["EMP_NAME"]
        ];
    }

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Dropdown data fetched successfully.",
        [
            "companies"  => $companies,
            "divisions"  => $divisions,
            "departments"=> $departments,
            "employees"  => $employees
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getEmployeeAccessDropdowns.php"
    );

    apiResponse(false, "Unable to fetch dropdown data.", null, 500);
}