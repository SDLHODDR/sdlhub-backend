<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__."/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";
require_once __DIR__."/../../config/emp_func.php";

header('Content-Type: application/json');

try {

    /* SESSION CHECK */

    $empCode = $_SESSION['emp_code'] ?? '';

    if(empty($empCode)){
        apiResponse(false,"Unauthorized access",null,401);
    }

    /* ------------------ COMPANY ------------------ */

    $companyData = multiRec("
        SELECT 
            COMP_ID,
            COMP_ID || ' - ' || COMP_DESC AS COMP_NAME
        FROM EPT_HR_COMPANY
        ORDER BY COMP_ID
    ");

    $companies = [];

    foreach($companyData as $row){
        $companies[] = [
            "value" => $row['COMP_ID'],
            "label" => $row['COMP_NAME']
        ];
    }

    /* ------------------ DIVISION ------------------ */

    $divisionData = multiRec("
        SELECT 
            DIVSN_ID,
            DIVSN_ID || ' - ' || DIVSN_DESC AS DIVSN_NAME
        FROM EPT_HR_DIVISIONS
        ORDER BY DIVSN_DESC
    ");

    $divisions = [];

    foreach($divisionData as $row){
        $divisions[] = [
            "value" => $row['DIVSN_ID'],
            "label" => $row['DIVSN_NAME']
        ];
    }

    /* ------------------ DEPARTMENT ------------------ */

    $deptData = multiRec("
        SELECT
            DEPT_ID,
            DEPT_ID || ' - ' || DEPT_DESC AS DEPT_NAME
        FROM EPT_HR_DEPARTMENT
        ORDER BY DEPT_DESC
    ");

    $departments = [];

    foreach($deptData as $row){
        $departments[] = [
            "value" => $row['DEPT_ID'],
            "label" => $row['DEPT_NAME']
        ];
    }

    /* ------------------ EMPLOYEE ------------------ */

    $employeeData =  multiRec("
        SELECT
            EMP_CODE,
            (EMP_CODE || ' - ' || EMP_FNAME || ' ' || EMP_LNAME) as EMP_NAME
        FROM EPT_bcs_employee
        WHERE status='A'
        ORDER BY 2
    ");

    $employees = [];

    foreach($employeeData as $row){
        $employees[] = [
            "value" => $row['EMP_CODE'],
            "label" => $row['EMP_NAME']
        ];
    }

    /* RESPONSE */
    echo json_encode([
        "status" => true,
        "companies" => $companies,
        "divisions" => $divisions,
        "departments" => $departments,
        "employees" => $employees
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
