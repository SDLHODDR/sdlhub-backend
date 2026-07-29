<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* =====================================================
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* =====================================================
       GET EMPLOYEE ID
    ===================================================== */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    ");

    $empId = $employee['ID'] ?? null;

    if (empty($empId)) {
        apiResponse(false, "Employee profile not found.");
    }

    /* =====================================================
       FINANCIAL YEAR
    ===================================================== */

    $fy = trim($_GET['fy'] ?? '');

    if (empty($fy)) {

        $acctPeriod = singRec("
            SELECT CODE
            FROM EPT_BCS_ACCT_PERIOD
            WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
        ");

        $fy = $acctPeriod['CODE'] ?? '';

        if (empty($fy)) {
            apiResponse(false, "Financial year not found.");
        }
    }

    /* =====================================================
       TOTAL ALLOWANCE
    ===================================================== */

    $totalAllowance = singRec("
        SELECT NVL(SUM(AMOUNT),0) AS AMOUNT
        FROM EPT_BCS_ALLW_DEDN_ITAX
        WHERE EMP_CODE = '$empCode'
        AND FIN_YEAR = '$fy'
        AND AMOUNT >= 0
    ");

    /* =====================================================
       OTHER INCOME
    ===================================================== */

    $otherIncome = singRec("
        SELECT NVL(SUM(AMOUNT),0) AS AMOUNT
        FROM EPT_BCS_ITAX_OTHER_INCOME
        WHERE EMP_ID = '$empId'
        AND FY = '$fy'
    ");

    /*
    =====================================================
    TAX CALCULATION
    =====================================================
    */

    $grossIncome = (float)$totalAllowance['AMOUNT']
                 + (float)$otherIncome['AMOUNT'];

    $standardDeduction = 75000;

    $netTaxableIncome = max(
        0,
        $grossIncome - $standardDeduction
    );

    $incomeTax = 0;
    $rebate = 0;

    if ($netTaxableIncome <= 400000) {
        $incomeTax = 0;
    } elseif ($netTaxableIncome <= 800000) {
        $incomeTax = ($netTaxableIncome - 400000) * 0.05;
    } elseif ($netTaxableIncome <= 1200000) {
        $incomeTax = 20000 + (($netTaxableIncome - 800000) * 0.10);
    } elseif ($netTaxableIncome <= 1600000) {
        $incomeTax = 60000 + (($netTaxableIncome - 1200000) * 0.15);
    } elseif ($netTaxableIncome <= 2000000) {
        $incomeTax = 120000 + (($netTaxableIncome - 1600000) * 0.20);
    } elseif ($netTaxableIncome <= 2400000) {
        $incomeTax = 200000 + (($netTaxableIncome - 2000000) * 0.25);
    } else {
        $incomeTax = 300000 + (($netTaxableIncome - 2400000) * 0.30);
    }

    if ($netTaxableIncome <= 1200000) {
        $rebate = min($incomeTax, 60000);
    }

    $taxAfterRebate = $incomeTax - $rebate;
    $cess = $taxAfterRebate * 0.04;
    $finalTax = $taxAfterRebate + $cess;

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(
        true,
        "Tax summary fetched successfully.",
        [
            "financial_year" => $fy,
            "gross_income" => round($grossIncome, 2),
            "standard_deduction" => $standardDeduction,
            "net_taxable_income" => round($netTaxableIncome, 2),
            "income_tax" => round($incomeTax, 2),
            "rebate" => round($rebate, 2),
            "cess" => round($cess, 2),
            "final_tax" => round($finalTax, 2)
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);
    apiResponse(false, "Unable to calculate tax summary.", null, 500);
}