<?php
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

require_once __DIR__ . "/../../../vendor/autoload.php";

$con = db_eportal();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {

    $input = json_decode(file_get_contents("php://input"), true);

    $financialYear = trim($input['financial_year'] ?? '');
    $empCode       = trim($input['emp_code'] ?? '');
    $downloadType  = trim($input['download_type'] ?? '');
    $fromDate      = trim($input['from_date'] ?? '');
    $toDate        = trim($input['to_date'] ?? '');

    $where = " WHERE 1=1 ";

    if (!empty($financialYear)) {
        $where .= " AND FINANCIAL_YEAR = :financial_year ";
    }

    if (!empty($empCode)) {
        $where .= " AND EMP_CODE = :emp_code ";
    }

    if (!empty($downloadType)) {
        $where .= " AND DOWNLOAD_TYPE = :download_type ";
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
                'DD-MON-YYYY HH24:MI:SS'
            ) DOWNLOAD_TIME

        FROM EPT_ITR_DOWNLOAD_LOG

        $where

        ORDER BY DOWNLOAD_TIME DESC
    ";

    $stmt = oci_parse($con, $sql);

    if (!empty($financialYear)) {
        oci_bind_by_name(
            $stmt,
            ":financial_year",
            $financialYear
        );
    }

    if (!empty($empCode)) {
        oci_bind_by_name(
            $stmt,
            ":emp_code",
            $empCode
        );
    }

    if (!empty($downloadType)) {
        oci_bind_by_name(
            $stmt,
            ":download_type",
            $downloadType
        );
    }

    if (!empty($fromDate)) {
        oci_bind_by_name(
            $stmt,
            ":from_date",
            $fromDate
        );
    }

    if (!empty($toDate)) {
        oci_bind_by_name(
            $stmt,
            ":to_date",
            $toDate
        );
    }

    oci_execute($stmt);

    $spreadsheet = new Spreadsheet();

    $sheet = $spreadsheet->getActiveSheet();

    $headers = [
        'Employee Code',
        'Download Type',
        'Target Employee',
        'Financial Year',
        'File Name',
        'File Size MB',
        'IP Address',
        'Browser',
        'Status',
        'Remarks',
        'Download Time'
    ];

    $col = 'A';

    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $col++;
    }

    $rowNo = 2;

    while ($row = oci_fetch_assoc($stmt)) {

        $sheet->setCellValue('A' . $rowNo, $row['EMP_CODE']);
        $sheet->setCellValue('B' . $rowNo, $row['DOWNLOAD_TYPE']);
        $sheet->setCellValue('C' . $rowNo, $row['TARGET_EMP_CODE']);
        $sheet->setCellValue('D' . $rowNo, $row['FINANCIAL_YEAR']);
        $sheet->setCellValue('E' . $rowNo, $row['FILE_NAME']);
        $sheet->setCellValue('F' . $rowNo, $row['FILE_SIZE_MB']);
        $sheet->setCellValue('G' . $rowNo, $row['IP_ADDRESS']);
        $sheet->setCellValue('H' . $rowNo, $row['BROWSER_NAME']);
        $sheet->setCellValue('I' . $rowNo, $row['STATUS']);
        $sheet->setCellValue('J' . $rowNo, $row['REMARKS']);
        $sheet->setCellValue('K' . $rowNo, $row['DOWNLOAD_TIME']);

        $rowNo++;
    }

    $fileName =
        "ITR_Download_Report_" .
        date('Ymd_His') .
        ".xlsx";

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $fileName .
        '"'
    );

    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);

    $writer->save('php://output');

    exit;
}
catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}