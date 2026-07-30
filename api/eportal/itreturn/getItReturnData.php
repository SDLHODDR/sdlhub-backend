<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/*  
=====================================================
GET INCOME DATA API
Fetches:
1. Gross Salary
2. Other Income
3. Distinct Deductions Sections
=====================================================
*/

try {

    /* =====================================================
       METHOD VALIDATION
    ===================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

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
       CURRENT FINANCIAL YEAR
    ===================================================== */

    $acctPeriod = singRec("
        SELECT CODE
        FROM EPT_BCS_ACCT_PERIOD
        WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
    ");

    $financialYear = $acctPeriod['CODE'] ?? '';

    if (empty($financialYear)) {
        apiResponse(false, "Financial year not found.");
    }

    /* =====================================================
       GROSS SALARY
    ===================================================== */

    $grossSalary = multiRec("
        SELECT
            b.SH_DESCR,
            a.AMOUNT,
            b.SEQ_NO
        FROM EPT_BCS_ALLW_DEDN_ITAX a
        INNER JOIN EPT_BCS_ALLW_DEDN b
            ON a.AD_CODE = b.AD_CODE
        WHERE a.EMP_CODE = '$empCode'
        AND a.FIN_YEAR = '$financialYear'
        ORDER BY b.SEQ_NO
    ");

    /* =====================================================
       OTHER INCOME
    ===================================================== */

    $otherIncome = multiRec("
        SELECT
            a.ITAX_ID,
            a.ITAX_DESC,
            b.AMOUNT,
            b.AGREEMENT_ATTACH,
            b.FY,
            '$empCode' EMP_CODE
        FROM EPT_BCS_ITAX_HEADS a

        LEFT JOIN EPT_BCS_ITAX_OTHER_INCOME b
            ON a.ITAX_ID = b.HEAD_ID
            AND b.EMP_ID = '$empId'
            AND b.FY = '$financialYear'

        WHERE a.ITAX_ID IN (
            SELECT HEAD
            FROM EPT_BCS_ITAX_SETUP
            WHERE SUB_SECTION = 'OTHER INCOME'
        )

        ORDER BY a.ITAX_ID
    ");

    /* =====================================================
       DEDUCTION SECTIONS
    ===================================================== */

    $deductionSections = singDymention(
        multiRec("
            SELECT DISTINCT SUB_SECTION
            FROM EPT_BCS_ITAX_SETUP
            WHERE SUB_SECTION NOT IN (
                'OTHER INCOME',
                'OTHER SECTIONS'
            )
            ORDER BY SUB_SECTION
        ")
    );

    /* =====================================================
       EMPLOYEE REGIME
    ===================================================== */

    $regimeData = singRec("
        SELECT REGIME
        FROM EPT_BCS_ITAX_EMP_REGIME
        WHERE EMP_ID = '$empId'
        AND FY = '$financialYear'
    ");

    $regime = $regimeData['REGIME'] ?? '';

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(
        true,
        "Income data fetched successfully.",
        [
            "financial_year" => $financialYear,
            "gross_salary" => $grossSalary ?: [],
            "other_income" => $otherIncome ?: [],
            "deduction_sections" => $deductionSections ?: [],
            "regime" => $regime
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);
    apiResponse(false, "Unable to fetch income data.", null, 500);
}