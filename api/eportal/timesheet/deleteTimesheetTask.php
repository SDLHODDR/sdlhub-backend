<?php
/*
 * Deletes one task (and its document) from the employee's Draft timesheet day.
 * A day left with no tasks is removed too.
 *
 * Request: { "id": taskId }
 */

require_once __DIR__ . "/tsCommon.php";

try {
    $taskId = (int)($data['id'] ?? 0);

    if ($taskId <= 0) {
        apiResponse(false, "Task not found.", null, 400);
    }

    // The task must belong to this employee.
    $rows = multiRec(
        "SELECT H.ID AS TS_ID, H.STATUS, TO_CHAR(H.TS_DATE, 'YYYY-MM-DD') AS TS_DATE, T.DOC_PATH
           FROM EPT_TIMESHEET_TASK T
           JOIN EPT_TIMESHEET H ON H.ID = T.TS_ID
          WHERE T.ID = :ID AND H.EMP_CODE = :EMP_CODE",
        [':ID' => $taskId, ':EMP_CODE' => $empCode]
    );

    if (!$rows) {
        apiResponse(false, "Task not found.", null, 404);
    }

    $day = $rows[0];

    if (tsIsFinalStatus($day['STATUS'])) {
        apiResponse(false, "This timesheet is already " . strtolower(TS_DAY_STATUS[$day['STATUS']]) . " and can no longer be changed.", null, 400);
    }

    $lockReason = tsDateLockReason($day['TS_DATE']);
    if ($lockReason !== "") {
        apiResponse(false, $lockReason, null, 400);
    }

    tsExec("DELETE FROM EPT_TIMESHEET_TASK WHERE ID = :ID", [':ID' => $taskId]);

    $left = multiRec("SELECT COUNT(*) AS N FROM EPT_TIMESHEET_TASK WHERE TS_ID = :TS_ID", [':TS_ID' => $day['TS_ID']]);
    if ((int)($left[0]['N'] ?? 0) === 0) {
        // No tasks left: the day goes too (Draft or Submitted).
        tsExec("DELETE FROM EPT_TIMESHEET WHERE ID = :TS_ID AND STATUS IN ('D', 'S')", [':TS_ID' => $day['TS_ID']]);
    } else {
        tsReopenDay($day['TS_ID'], $empCode);
    }

    tsCommit();
    tsDeleteDocumentFile($day['DOC_PATH'] ?? '');

    $message = "Task deleted successfully.";
    if ($day['STATUS'] === 'S' && (int)($left[0]['N'] ?? 0) > 0) {
        $message .= " Please submit the timesheet again before " . TS_CUTOFF_LABEL . ".";
    }
    apiResponse(true, $message);
} catch (Throwable $e) {
    tsFail($e, "Unable to delete the task.");
}
