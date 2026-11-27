<?php
/*
 * Daily Timesheet Report (reporting managers only).
 * Request:  { from: "YYYY-MM-DD", to: "YYYY-MM-DD", empCode?: "" }   (default: today)
 * Response: { from, to, rows[], employees[] }
 *   rows: the manager's own tasks and the team's submitted tasks reported to them.
 */

require_once __DIR__ . "/tsCommon.php";

try {
    tsRequireManager($empCode);
    [$from, $to, $filterEmp] = tsReportFilters($data ?? []);

    $rows = tsReportRows($empCode, $from, $to, $filterEmp);

    apiResponse(true, "Report loaded.", [
        "from" => $from,
        "to"   => $to,
        "rows" => array_map(fn($r) => [
            "id"             => (int)$r['ID'],
            "date"           => $r['TS_DATE'],
            "empCode"        => $r['EMP_CODE'],
            "empName"        => $r['EMP_NAME'] ?: $r['EMP_CODE'],
            "isMine"         => $r['EMP_CODE'] === $empCode,
            "category"       => $r['CAT_DESC'],
            "project"        => $r['PROJ_DESC'] ?? "",
            "title"          => $r['TASK_TITLE'],
            "description"    => tsCleanHtml($r['TASK_DESC'] ?? ""),
            "descriptionText" => tsHtmlText(tsCleanHtml($r['TASK_DESC'] ?? "")),
            "remarks"        => tsCleanHtml($r['REMARKS'] ?? ""),
            "taskStatus"     => $r['TASK_STATUS'],
            "taskStatusText" => TS_TASK_STATUS[$r['TASK_STATUS']] ?? $r['TASK_STATUS'],
            "dayStatus"      => $r['DAY_STATUS'],
            "dayStatusText"  => TS_DAY_STATUS[$r['DAY_STATUS']] ?? $r['DAY_STATUS'],
            "submittedOn"    => $r['SUBMITTED_ON'] ?? "",
            "documentName"   => $r['DOC_NAME'] ?? "",
            "reportToName"   => $r['REPORT_TO_NAME'] ?? "",
        ], $rows),
        "employees" => array_map(fn($e) => [
            "value" => $e['EMP_CODE'],
            "label" => ($e['EMP_CODE'] === $empCode ? "Me - " : "") . trim($e['EMP_NAME']) . " (" . $e['EMP_CODE'] . ")",
        ], tsReportEmployees($empCode)),
    ]);
} catch (Throwable $e) {
    tsFail($e, "Unable to load the timesheet report.");
}
