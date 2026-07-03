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

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        echo json_encode([
            "status" => false,
            "message" => "Invalid request method"
        ]);
        exit;
    }
    
    // ================= SESSION VALIDATION =================

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

    /*
    =====================================================
    CURRENT FINANCIAL YEAR
    =====================================================
    */

    
    $bcs_acct_period = singRec("
        SELECT *
        FROM ept_bcs_acct_period 
        WHERE SYSDATE BETWEEN fr_date AND to_date
    ");

    if (
        empty($bcs_acct_period) ||
        !isset($bcs_acct_period['DESCR'])
    ) {
        echo json_encode([
            "status" => false,
            "message" => "Financial year not found in BCS_ACCT_PERIOD"
        ]);
        exit;
    }   
    //$financialYear = $bcs_acct_period['DESCR'];
    //$financialYear = '26-27';

    $financialYear = $bcs_acct_period['CODE'] ?? '';

    /*
    =====================================================
    GROSS SALARY
    =====================================================
    */

    $emp_gross = multiRec("
        SELECT
            b.sh_descr,
            a.amount,
            b.seq_no
        FROM ept_bcs_allw_dedn_itax a
        INNER JOIN ept_bcs_allw_dedn b
            ON a.ad_code = b.ad_code
        WHERE
            a.emp_code = '{$empCode}'
            AND a.fin_year = '{$financialYear}'
        ORDER BY 3
    ");

    /*
    =====================================================
    OTHER INCOME
    =====================================================
    */

    $other_income = multiRec("
        SELECT
            a.itax_id,
            a.itax_desc,
            b.amount,
            b.agreement_attach,
            b.fy,
            '{$empCode}' AS emp_code
        FROM ept_bcs_itax_heads a

        LEFT JOIN ept_bcs_itax_other_income b
            ON a.itax_id = b.head_id
            AND b.emp_id = '{$empId}'
            AND b.fy = '{$financialYear}'

        WHERE a.itax_id IN (
            SELECT head
            FROM ept_bcs_itax_setup
            WHERE sub_section = 'OTHER INCOME'
        )

        ORDER BY 1
    ");

    /*
    =====================================================
    DISTINCT DEDUCTIONS SECTIONS
    =====================================================
    */

    $distinct_dedn = singDymention(
        multiRec("
            SELECT DISTINCT sub_section
            FROM ept_bcs_itax_setup
            WHERE sub_section NOT IN (
                'OTHER INCOME',
                'OTHER SECTIONS'
            )
            ORDER BY 1
        ")
    );

    /* =====================================================
       GET EXISTING REGIME
    ===================================================== */

    $regimeData = singRec("
        SELECT regime
        FROM EPT_BCS_ITAX_EMP_REGIME
        WHERE EMP_ID = '{$empId}'
        AND FY = '{$financialYear}'
    ");

    $regime = '';
    if (!empty($regimeData) || isset($regimeData['REGIME'])) {
        $regime = $regimeData['REGIME'];
    }

    /*
    =====================================================
    RESPONSE
    =====================================================
    */

    echo json_encode([
        "status" => true,
        "message" => "Data fetched successfully",

        "data" => [
            "gross_salary" => $emp_gross ?: [],
            "other_income" => $other_income ?: [],
            "deduction_sections" => $distinct_dedn ?: [],
            "regime" => $regime ?: ""
        ]
    ]);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}