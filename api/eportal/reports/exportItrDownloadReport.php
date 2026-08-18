<?php

ob_start();

ini_set("display_errors", 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

require_once __DIR__ . "/../../../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$con = db_eportal();

try {
    /*
    =========================================
    SESSION VALIDATION
    =========================================
    */

    $sessionEmpCode = $_SESSION["emp_code"] ?? "";

    if (!$sessionEmpCode) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /*
    =========================================
    REQUEST INPUT
    =========================================
    */

    $input = json_decode(file_get_contents("php://input"), true);

    $financialYear = trim($input["financial_year"] ?? "");
    $empCode = trim($input["emp_code"] ?? "");
    $downloadType = trim($input["download_type"] ?? "");
    $fromDate = trim($input["from_date"] ?? "");
    $toDate = trim($input["to_date"] ?? "");

    /*
    =========================================
    BUILD FILTER
    =========================================
    */

    $where = " WHERE 1=1 ";

    if (!empty($financialYear)) {
        $where .= "
            AND FINANCIAL_YEAR = :financial_year
        ";
    }

    if (!empty($empCode)) {
        $where .= "
            AND EMP_CODE = :emp_code
        ";
    }

    if (!empty($downloadType)) {
        $where .= "
            AND DOWNLOAD_TYPE = :download_type
        ";
    }

    if (!empty($fromDate)) {
        $where .= "
            AND TRUNC(DOWNLOAD_TIME) >=
            TO_DATE(:from_date,'YYYY-MM-DD')
        ";
    }

    if (!empty($toDate)) {
        $where .= "
            AND TRUNC(DOWNLOAD_TIME) <=
            TO_DATE(:to_date,'YYYY-MM-DD')
        ";
    }

    /*
    =========================================
    FETCH DATA
    =========================================
    */

    $sql = "

        SELECT
            EMP_CODE,
            DOWNLOAD_TYPE,
            TARGET_EMP_CODE,
            FINANCIAL_YEAR,
            FILE_NAME,
            FILE_SIZE_MB,
            IP_ADDRESS,
            BROWSER_NAME,
            STATUS,
            REMARKS,
            TO_CHAR(
                DOWNLOAD_TIME,
                'dd-Mon-yyyy HH24:MI:SS'
            ) DOWNLOAD_TIME
        FROM EPT_ITR_DOWNLOAD_LOG
        $where
        ORDER BY DOWNLOAD_TIME DESC
    ";

    $stmt = oci_parse($con, $sql);

    bindFilters(
        $stmt,
        $financialYear,
        $empCode,
        $downloadType,
        $fromDate,
        $toDate
    );

    oci_execute($stmt);

    /*
    =========================================
    CREATE EXCEL
    =========================================
    */

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = [
        "Employee Code",
        "Download Type",
        "Target Employee",
        "Financial Year",
        "File Name",
        "File Size MB",
        "IP Address",
        "Browser",
        "Status",
        "Remarks",
        "Download Time",
    ];

    foreach ($headers as $index => $header) {
        $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
    }

    $rowNo = 2;

    while ($row = oci_fetch_assoc($stmt)) {
        $sheet->fromArray(
            [
                $row["EMP_CODE"],
                $row["DOWNLOAD_TYPE"],
                $row["TARGET_EMP_CODE"],
                $row["FINANCIAL_YEAR"],
                $row["FILE_NAME"],
                $row["FILE_SIZE_MB"],
                $row["IP_ADDRESS"],
                $row["BROWSER_NAME"],
                $row["STATUS"],
                $row["REMARKS"],
                $row["DOWNLOAD_TIME"],
            ],
            null,
            "A" . $rowNo
        );

        $rowNo++;
    }

    /*
    =========================================
    DOWNLOAD FILE
    =========================================
    */

    $fileName = "ITR_Download_Report_" . date("Ymd_His") . ".xlsx";

    // Clear previous output
    if (ob_get_length()) {
        ob_end_clean();
    }

    header(
        "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    );

    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header("Cache-Control: max-age=0");
    $writer = new Xlsx($spreadsheet);
    $writer->save("php://output");
    exit;

} catch (Throwable $e) {
    logOracleError($e);

    // If headers are already sent, avoid JSON corruption
    if (!headers_sent()) {
        apiResponse(false, "Unable to export ITR download report", null, 500);
    }
    exit;
}
