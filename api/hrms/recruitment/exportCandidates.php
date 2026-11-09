<?php
/* GET ?format=xlsx|csv&start=&length=&search= -> the page of the Candidate List on screen, as a file. */
require_once __DIR__ . '/recruitmentCommon.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES]);
    session_write_close();
    $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
    $page = recruitmentCandidatePage($_GET['start'] ?? 0, $_GET['length'] ?? 10, $_GET['search'] ?? '');

    $header = ['#', 'Name', 'Mobile', 'Email', 'Date'];
    $rows = array_map(static fn($row) => [
        $row['rowNumber'], $row['name'], $row['mobile'], $row['email'], $row['date'],
    ], $page['rows']);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="HRMS.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    $sheet = new Spreadsheet();
    $active = $sheet->getActiveSheet();
    $active->fromArray($header, null, 'A1');
    // Text cells: mobile numbers must not turn into numbers or formulas.
    foreach ($rows as $index => $row) {
        foreach ($row as $column => $value) {
            $active->setCellValueExplicit([$column + 1, $index + 2], (string)$value,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }
    $active->getStyle('A1:E1')->getFont()->setBold(true);
    foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
        $active->getColumnDimension($column)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="HRMS.xlsx"');
    (new Xlsx($sheet))->save('php://output');
    exit;
} catch (RecruitmentRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'exportCandidates.php');
    apiResponse(false, 'Unable to export the candidates.', null, 500);
}
