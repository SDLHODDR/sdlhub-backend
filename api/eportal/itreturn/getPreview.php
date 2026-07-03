<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ ."/../../config/utils.php";

header("Content-Type: application/json");

 if (!isset($_SESSION['emp_code'])) {   
    apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = $_SESSION['emp_code'] ?? '';

/* ---------------------------
GET EMP ID
---------------------------- */
$empId = singRec("
    SELECT ID 
    FROM EPT_BCS_EMPLOYEE 
    WHERE emp_code = '".$empCode."'
")['ID'] ?? null;

if (!$empId) {
    echo json_encode(['status' => false, 'message' => 'Profile Not Found']);
    exit;
}

$fy = $_REQUEST['fy'] ?? date('Y');

/*$total_allowance = singRec("SELECT NVL(SUM(amount),0) AS amount
FROM employee_salary
WHERE emp_id = '$empId'"); */

$total_allowance = singRec("SELECT NVL(SUM(amount),0) AS amount from ept_bcs_allw_dedn_itax a 
where a.emp_code='".$empCode."' and a.fin_year='25-26' and a.amount>=0");

//echo "total_allowance"; print_r($total_allowance); exit;


$other_income = singRec("SELECT NVL(SUM(amount),0) AS amount
FROM ept_bcs_itax_other_income
WHERE emp_id = '$empId' AND fy = '$fy'");

$gross_income = 50000; //(float)$total_allowance['AMOUNT'] + (float)$other_income['AMOUNT'];
$standard_deduction = 75000;

$net_taxable_income = max(0, $gross_income - $standard_deduction);

$income_tax = 0;
$rebate = 0;
$cess = 0;
$final_tax = 0;

if ($net_taxable_income <= 400000) {
    $income_tax = 0;
} elseif ($net_taxable_income <= 800000) {
    $income_tax = ($net_taxable_income - 400000) * 0.05;
} elseif ($net_taxable_income <= 1200000) {
    $income_tax = 20000 + (($net_taxable_income - 800000) * 0.10);
} elseif ($net_taxable_income <= 1600000) {
    $income_tax = 60000 + (($net_taxable_income - 1200000) * 0.15);
} elseif ($net_taxable_income <= 2000000) {
    $income_tax = 120000 + (($net_taxable_income - 1600000) * 0.20);
} elseif ($net_taxable_income <= 2400000) {
    $income_tax = 200000 + (($net_taxable_income - 2000000) * 0.25);
} else {
    $income_tax = 300000 + (($net_taxable_income - 2400000) * 0.30);
}

if ($net_taxable_income <= 1200000) {
    $rebate = min($income_tax, 60000);
}

$tax_after_rebate = $income_tax - $rebate;
$cess = ($tax_after_rebate * 4) / 100;
$final_tax = $tax_after_rebate + $cess;

echo json_encode([
    'success' => true,
    'data' => [
        'gross_income' => round($gross_income, 2),
        'standard_deduction' => $standard_deduction,
        'net_taxable_income' => round($net_taxable_income, 2),
        'income_tax' => round($income_tax, 2),
        'rebate' => round($rebate, 2),
        'cess' => round($cess, 2),
        'final_tax' => round($final_tax, 2)
    ]
]);

