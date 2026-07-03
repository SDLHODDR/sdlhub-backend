<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../itr/getEmployeeSummaryData.php";

if (!isset($_SESSION['emp_code'])) {   
    apiResponse(false,"Unauthorized Access",null,401);
}

$result = getEmployeeSummaryData(
    $_SESSION['emp_code']
);

header("Content-Type: application/json");

echo json_encode($result);
exit;