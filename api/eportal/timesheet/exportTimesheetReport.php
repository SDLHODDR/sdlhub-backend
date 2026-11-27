<?php
/*
 * Daily Timesheet Report as an Excel file (reporting managers only).
 * Request: { from, to, empCode? }  - same filters as getTimesheetReport.php
 */

require_once __DIR__ . "/tsCommon.php";
require_once __DIR__ . "/../../../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

try {
    tsRequireManager($empCode);
    [$from, $to, $filterEmp] = tsReportFilters($data ?? []);

    $rows = tsReportRows($empCode, $from, $to, $filterEmp);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Timesheet Report');

    $headers = [
        'Date', 'Employee Name', 'Project', 'Category', 'Task', 'Description', 'Remarks', 'Task Status', 'Submitted On',
    ];
    $lastCol = 'I';
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFE0B2');
    $sheet->freezePane('A2');

    $line = 2;
    foreach ($rows as $r) {
        $values = [
            date('d-M-Y', strtotime($r['TS_DATE'])),
            $r['EMP_NAME'] ?: $r['EMP_CODE'],
            $r['PROJ_DESC'] ?? '',
            $r['CAT_DESC'],
            $r['TASK_TITLE'],
            // Formatted text as plain text: one line per paragraph, "• " / "1. " for list items.
            tsHtmlText(tsCleanHtml($r['TASK_DESC'] ?? '')),
            tsHtmlText(tsCleanHtml($r['REMARKS'] ?? '')),
            TS_TASK_STATUS[$r['TASK_STATUS']] ?? $r['TASK_STATUS'],
            // Submitted time only for submitted days (a reopened Draft keeps its old time).
            $r['DAY_STATUS'] === 'S' ? ($r['SUBMITTED_ON'] ?? '') : '',
        ];
        foreach ($values as $i => $value) {
            // Stored as text so "=..." typed in a task is not run as a formula.
            $sheet->setCellValueExplicit([$i + 1, $line], (string)$value, DataType::TYPE_STRING);
        }
        $line++;
    }

    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    // Long text columns (Task, Description, Remarks): fixed width, wrapped.
    foreach (['E' => 40, 'F' => 60, 'G' => 40] as $col => $width) {
        $sheet->getColumnDimension($col)->setAutoSize(false)->setWidth($width);
        $sheet->getStyle("{$col}2:{$col}" . max(2, $line - 1))->getAlignment()->setWrapText(true);
    }
    $sheet->getStyle("A1:{$lastCol}" . max(1, $line - 1))->getAlignment()->setVertical('top');

    $fileName = $from === $to ? "Timesheet_Report_{$from}.xlsx" : "Timesheet_Report_{$from}_to_{$to}.xlsx";

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$fileName}\"");
    header('Cache-Control: max-age=0');

    (new Xlsx($spreadsheet))->save('php://output');
    exit;
} catch (Throwable $e) {
    tsFail($e, "Unable to export the timesheet report.");
}
