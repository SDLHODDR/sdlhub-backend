<?php
/*
 * One day of the logged-in employee's timesheet.
 * Request:  { "date": "YYYY-MM-DD" }
 * Response: { date, editable, lockReason, day, tasks[], categories[] }
 */

require_once __DIR__ . "/tsCommon.php";

try {
    $date = tsRequestDate($data['date'] ?? '');
    $day = tsFindDay($empCode, $date);

    $tasks = [];
    if ($day) {
        $tasks = multiRec(
            "SELECT T.ID, T.CATEGORY_ID, C.CAT_DESC, T.TASK_TITLE, T.TASK_DESC,
                    T.TASK_STATUS, T.REMARKS, T.DOC_NAME, T.REPORT_TO,
                    TRIM(M.EMP_FNAME || ' ' || M.EMP_LNAME) AS REPORT_TO_NAME
               FROM EPT_TIMESHEET_TASK T
               JOIN EPT_TS_CATEGORY C ON C.ID = T.CATEGORY_ID
               LEFT JOIN EPT_BCS_EMPLOYEE M ON M.EMP_CODE = T.REPORT_TO
              WHERE T.TS_ID = :TS_ID
              ORDER BY T.ID",
            [':TS_ID' => $day['ID']]
        );
    }

    $categories = multiRec(
        "SELECT ID, CAT_DESC FROM EPT_TS_CATEGORY WHERE STATUS = 'A' ORDER BY SEQ, CAT_DESC"
    );

    $status = $day['STATUS'] ?? 'D';
    $lockReason = tsDateLockReason($date);
    if ($lockReason === "" && tsIsFinalStatus($status)) {
        $lockReason = "This timesheet is " . strtolower(TS_DAY_STATUS[$status]) . " and can no longer be changed.";
    }

    apiResponse(true, "Timesheet loaded.", [
        "date"       => $date,
        "editable"   => $lockReason === "",
        "lockReason" => $lockReason,
        "cutoff"     => TS_CUTOFF_LABEL,
        "day"        => $day ? [
            "id"          => (int)$day['ID'],
            "status"      => $status,
            "statusText"  => TS_DAY_STATUS[$status] ?? $status,
            "submittedOn" => $day['SUBMITTED_ON'],
            "authRemarks" => $day['AUTH_REMARKS'],
        ] : null,
        "tasks" => array_map(fn($t) => [
            "id"             => (int)$t['ID'],
            "categoryId"     => (int)$t['CATEGORY_ID'],
            "category"       => $t['CAT_DESC'],
            "title"          => $t['TASK_TITLE'],
            "description"    => $t['TASK_DESC'] ?? "",
            "taskStatus"     => $t['TASK_STATUS'],
            "taskStatusText" => TS_TASK_STATUS[$t['TASK_STATUS']] ?? $t['TASK_STATUS'],
            "remarks"        => $t['REMARKS'] ?? "",
            "documentName"   => $t['DOC_NAME'] ?? "",
            "reportTo"       => $t['REPORT_TO'] ?? "",
            "reportToName"   => $t['REPORT_TO_NAME'] ?? "",
        ], $tasks),
        "categories" => array_map(fn($c) => [
            "value" => (int)$c['ID'],
            "label" => $c['CAT_DESC'],
        ], $categories),
    ]);
} catch (Throwable $e) {
    tsFail($e, "Unable to load the timesheet.");
}
