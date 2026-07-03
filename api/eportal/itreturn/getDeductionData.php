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
GET DEDUCTIONS DATA API
Fetches:
1. Distinct Deduction Sections
2. All deduction records section-wise
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
        SELECT CODE, DESCR, FR_DATE, TO_DATE
        FROM ept_bcs_acct_period 
        WHERE SYSDATE BETWEEN fr_date AND to_date
    "); //WHERE SYSDATE BETWEEN fr_date AND to_date

    $financialYear =
        $bcs_acct_period['CODE']
        ?? $bcs_acct_period['code']
        ?? '';

    if (empty($financialYear)) {
        echo json_encode([
            "status" => false,
            "message" => "Financial year not found"
        ]);
        exit;
    }

    /*
    =====================================================
    DISTINCT DEDUCTION SECTIONS
    =====================================================
    */

    $rows = multiRec("
        SELECT DISTINCT TRIM(sub_section) AS sub_section
        FROM ept_bcs_itax_setup
        WHERE UPPER(TRIM(sub_section)) NOT IN (
            'OTHER INCOME',
            'OTHER SECTIONS'
        )
        ORDER BY 1
    ");

    $distinct_dedn = [];

    foreach ($rows as $row) {
        $distinct_dedn[] = $row['SUB_SECTION'];
    }

    /*
    =====================================================
    FETCH DEDUCTIONS SECTION-WISE
    =====================================================
    */

    $allDeductions = [];

    foreach ($distinct_dedn as $ddn) {

        $deductions = multiRec("
            SELECT
                c.sub_section,
                a.itax_id,
                a.itax_desc,
                c.limit,
                b.amount,
                b.attachments
            FROM ept_bcs_itax_heads a

            LEFT JOIN ept_bcs_itax_deductions b
                ON a.itax_id = b.head_id
                AND b.emp_id = '{$empId}'
                AND b.fy = '{$financialYear}'

            INNER JOIN ept_bcs_itax_setup c
                ON a.itax_id = c.head

            WHERE a.itax_id IN (
                SELECT head
                FROM ept_bcs_itax_setup 
                WHERE sub_section = '{$ddn}'
            )
            ORDER BY 3
        ");
       
        $allDeductions[] = [
            "section_name" => $ddn,
            "records" => $deductions ?: []
        ];
    }

    /*
    =====================================================
    RESPONSE
    =====================================================
    */

    echo json_encode([
        "status" => true,
        "message" => "Deductions fetched successfully",
        "data" => $allDeductions,
        "financial_year" => $financialYear,
        "emp_code" => $empCode,

    ]);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}