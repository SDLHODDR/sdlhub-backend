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
       METHOD VALIDATION
    ===================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* =====================================================
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION["emp_code"] ?? "";

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

    $empId = $employee["ID"] ?? null;

    if (empty($empId)) {
        apiResponse(false, "Employee profile not found.");
    }

    /* =====================================================
       CURRENT FINANCIAL YEAR
    ===================================================== */

    $accountPeriod = singRec("
        SELECT CODE, DESCR, FR_DATE, TO_DATE
        FROM EPT_BCS_ACCT_PERIOD
        WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
    ");

    $financialYear = $accountPeriod["CODE"] ?? "";

    if (empty($financialYear)) {
        apiResponse(false, "Financial year not found.");
    }

    /* =====================================================
       GET DISTINCT DEDUCTION SECTIONS
    ===================================================== */

    $sections = multiRec("
        SELECT DISTINCT TRIM(SUB_SECTION) AS SUB_SECTION
        FROM EPT_BCS_ITAX_SETUP
        WHERE UPPER(TRIM(SUB_SECTION)) NOT IN
        (
            'OTHER INCOME',
            'OTHER SECTIONS'
        )
        ORDER BY 1
    ");

    $allDeductions = [];

    foreach ($sections as $section) {

        $sectionName = $section["SUB_SECTION"];

        $records = multiRec("
            SELECT
                c.SUB_SECTION,
                a.ITAX_ID,
                a.ITAX_DESC,
                c.LIMIT,
                b.AMOUNT,
                b.ATTACHMENTS
            FROM EPT_BCS_ITAX_HEADS a

            LEFT JOIN EPT_BCS_ITAX_DEDUCTIONS b
                ON a.ITAX_ID = b.HEAD_ID
                AND b.EMP_ID = '$empId'
                AND b.FY = '$financialYear'

            INNER JOIN EPT_BCS_ITAX_SETUP c
                ON a.ITAX_ID = c.HEAD

            WHERE c.SUB_SECTION = '$sectionName'

            ORDER BY a.ITAX_DESC
        ");

        $allDeductions[] = [
            "section_name" => $sectionName,
            "records" => $records ?: []
        ];
    }

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(
        true,
        "Deductions fetched successfully.",
        [
            "financial_year" => $financialYear,
            "emp_code" => $empCode,
            "deductions" => $allDeductions
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);
    apiResponse(false, "Unable to fetch deductions.", null, 500);
}