<?php

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";

header('Content-Type: application/json');

if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = $_SESSION['emp_code'] ?? '';
$payslips = [];

$sql = "
SELECT 
    TO_CHAR(ADD_MONTHS(TRUNC(SYSDATE,'MM'), -LEVEL),'MM') AS MONTH,
    TO_CHAR(ADD_MONTHS(TRUNC(SYSDATE,'MM'), -LEVEL),'YYYY') AS YEAR
FROM dual
CONNECT BY LEVEL <= 6
";

$months = multiRec($sql);

$empDetails = singRec("
    SELECT EMP_CODE, PROC_GROUP, PAY_SITE
    FROM EPT_BCS_EMPLOYEE
    WHERE EMP_CODE = '$empCode'
");

foreach ($months as $row) {

    $month = $row['MONTH'];
    $year  = $row['YEAR'];
    $prdCode = $year . $month;

    $salSlipExist = singRec("
        SELECT 1
        FROM EPT_BCS_PAYROLL_VOUCHER
        WHERE confirmed = 'Y'
        AND tran_type = 'PAY'
        AND prd_code = '$prdCode'
        AND emp_code = '$empCode'
    ");

    if (!empty($salSlipExist)) {

        

        $remoteUrl = "https://epp.sdlindia.com/reports/pdfReport/payslip.php?"
            . "SITE_CODE=" . urlencode($empDetails['PAY_SITE'])
            . "&PRD_CODE=" . $prdCode
            . "&PROC_GRP_FROM=" . urlencode($empDetails['PROC_GROUP'])
            . "&PROC_GRP_TO=" . urlencode($empDetails['PROC_GROUP'])
            . "&EMP_CODE=" . urlencode($empCode)
            . "&empportaldownload=1";

        /*$proxyUrl = "http://localhost/sdlhub/backend/api/eportal/viewPayslip.php?url=" 
            . urlencode($remoteUrl); */

        $fileName = $year. "-" . $month . "-Payslip.pdf";
        $proxyUrl = $_ENV["API_ROOT_PATH"]."/eportal/payslips/Payslip.pdf.php/".$fileName."?"
        . "url=" . urlencode($remoteUrl)
        . "&filename=" . urlencode($fileName);

        $payslips[] = [
            "month" => $month,
            "year" => $year,
            "monthName" => date('F', mktime(0,0,0,$month,1,$year)),
            "prdCode" => $prdCode,
            "downloadUrl" => $proxyUrl
        ];
    }
}

echo json_encode([
    "status" => true,
    "data" => $payslips
]);

exit;