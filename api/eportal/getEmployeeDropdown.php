<?php

ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../config/functions.php";

header("Content-Type: application/json");

$response = [
    "status" => false,
    "message" => "",
    "data" => []
];

try {
    /*
    =========================================
    SESSION VALIDATION
    =========================================
    */
    $empCode = $_SESSION['emp_code'] ?? '';

    if (!$empCode) {   
        apiResponse(false,"Unauthorized Access",null,401);
    }

    /*
    =========================================
    FETCH EMPLOYEES
    =========================================
    */
    $sql = "
        select EMP_CODE,
               (EMP_CODE || ' - ' || EMP_FNAME || ' ' || EMP_LNAME) as EMP_NAME
        from EPT_bcs_employee
        where status='A'
        order by 2
    ";
    $employees = multiRec($sql);
    $response["status"] = true;
    $response["data"] = $employees;
}
catch (Exception $e) {
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
exit;