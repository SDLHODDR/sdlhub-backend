<?php
/*
 * Submits the employee's timesheet for today. It can still be changed until the
 * cut-off (6:00 PM); any change puts it back to Draft, to be submitted again.
 * Request: { "date": "YYYY-MM-DD" }
 */

require_once __DIR__ . "/tsCommon.php";

try {
    $date = tsRequestDate($data['date'] ?? '');
    $day = tsFindDay($empCode, $date);

    if (!$day) {
        apiResponse(false, "Please add at least one task before submitting.", null, 400);
    }

    if ($day['STATUS'] !== 'D') {
        $message = $day['STATUS'] === 'S'
            ? "This timesheet is already submitted. Any change you make puts it back to Draft so you can submit it again."
            : "This timesheet is already " . strtolower(TS_DAY_STATUS[$day['STATUS']] ?? 'submitted') . ".";
        apiResponse(false, $message, null, 400);
    }

    $lockReason = tsDateLockReason($date);
    if ($lockReason !== "") {
        apiResponse(false, $lockReason, null, 400);
    }

    $count = multiRec("SELECT COUNT(*) AS N FROM EPT_TIMESHEET_TASK WHERE TS_ID = :TS_ID", [':TS_ID' => $day['ID']]);
    if ((int)($count[0]['N'] ?? 0) === 0) {
        apiResponse(false, "Please add at least one task before submitting.", null, 400);
    }

    $updated = tsExec(
        "UPDATE EPT_TIMESHEET
            SET STATUS = 'S', SUBMITTED_ON = SYSDATE, CHG_BY = :EMP_CODE, CHG_ON = SYSDATE
          WHERE ID = :ID AND STATUS = 'D'",
        [':ID' => $day['ID'], ':EMP_CODE' => $empCode]
    );

    if ($updated !== 1) {
        tsRollback();
        apiResponse(false, "This timesheet was already submitted.", null, 409);
    }

    tsCommit();

    apiResponse(true, "Timesheet submitted successfully.");
} catch (Throwable $e) {
    tsFail($e, "Unable to submit the timesheet.");
}
