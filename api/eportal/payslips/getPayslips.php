<?php

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";

header("Content-Type: application/json");

try {

    /* =====================================================
       REQUEST METHOD
    ===================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method", null, 405);
    }

    /* =====================================================
       SESSION
    ===================================================== */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    $empCode = trim($_SESSION["emp_code"]);

    /* Release session lock */
    session_write_close();

    /* =====================================================
       LAST 6 MONTHS
    ===================================================== */

    $months = multiRec("
        SELECT
            TO_CHAR(ADD_MONTHS(TRUNC(SYSDATE,'MM'),-LEVEL),'MM') MONTH,
            TO_CHAR(ADD_MONTHS(TRUNC(SYSDATE,'MM'),-LEVEL),'YYYY') YEAR
        FROM dual
        CONNECT BY LEVEL <= 6
    ");

    /* =====================================================
       EMPLOYEE DETAILS
    ===================================================== */

    $employee = singRec("
        SELECT
            EMP_CODE,
            PROC_GROUP,
            PAY_SITE
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '{$empCode}'
    ");

    if (empty($employee)) {
        apiResponse(false, "Employee not found", null, 404);
    }

    $paySlips = [];

    /* =====================================================
       LOOP MONTHS
    ===================================================== */

    foreach ($months as $row) {

        $month = $row["MONTH"];
        $year  = $row["YEAR"];
        $prdCode = $year . $month;

        $exists = singRec("
            SELECT 1
            FROM EPT_BCS_PAYROLL_VOUCHER
            WHERE CONFIRMED='Y'
            AND TRAN_TYPE='PAY'
            AND PRD_CODE='{$prdCode}'
            AND EMP_CODE='{$empCode}'
        ");

        if (empty($exists)) {
            continue;
        }

        /* ===============================================
           Remote Payslip URL
        =============================================== */

        $remoteUrl =
            "https://epp.sdlindia.com/reports/pdfReport/payslip.php?"
            . "SITE_CODE=" . urlencode($employee["PAY_SITE"])
            . "&PRD_CODE=" . urlencode($prdCode)
            . "&PROC_GRP_FROM=" . urlencode($employee["PROC_GROUP"])
            . "&PROC_GRP_TO=" . urlencode($employee["PROC_GROUP"])
            . "&EMP_CODE=" . urlencode($empCode)
            . "&empportaldownload=1";

        /* ===============================================
           Proxy URL
        =============================================== */

        $fileName = $year . "-" . $month . "-Payslip.pdf";

        $downloadUrl =
            rtrim($_ENV["API_ROOT_PATH"], "/")
            . "/eportal/payslips/Payslip.pdf.php/"
            . urlencode($fileName)
            . "?url=" . urlencode($remoteUrl)
            . "&filename=" . urlencode($fileName);

        $paySlips[] = [
            "month"       => $month,
            "year"        => $year,
            "monthName"   => date(
                "F",
                mktime(0, 0, 0, (int)$month, 1, (int)$year)
            ),
            "prdCode"     => $prdCode,
            "downloadUrl" => $downloadUrl
        ];
    }

    /* =====================================================
       RESPONSE
    ===================================================== */

    apiResponse(true, "Payslips fetched successfully", $paySlips);

} catch (Exception $e) {

    apiResponse(false, $e->getMessage(), null, 500);
}
