<?php
/*
 * Adds a task to the employee's timesheet day, or edits one ("id" given).
 * Creates the day (as Draft) on its first task.
 *
 * Sent as multipart/form-data:
 *   date, id?, categoryId, title, description, taskStatus, remarks, reportTo,
 *   document?        (file; replaces the task's current document)
 *   removeDocument?  ("1" = delete the current document)
 */

require_once __DIR__ . "/tsCommon.php";

$newDocument = null;   // file stored by this request (removed again if the save fails)

try {
    $input = !empty($_POST) ? $_POST : ($data ?? []);

    /* ---------- validate input ---------- */

    $date = tsRequestDate($input['date'] ?? '');
    $taskId = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : 0;
    $categoryId = (int)($input['categoryId'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $remarks = trim((string)($input['remarks'] ?? ''));
    $taskStatus = (string)($input['taskStatus'] ?? '');
    $reportTo = trim((string)($input['reportTo'] ?? ''));
    $removeDocument = ($input['removeDocument'] ?? '') === '1';
    $hasUpload = isset($_FILES['document']) && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $errors = [];
    if ($categoryId <= 0) $errors['categoryId'] = "Please select a category.";
    if ($title === '') $errors['title'] = "Please enter the task.";
    elseif (mb_strlen($title) > TS_TITLE_MAX) $errors['title'] = "Task must not exceed " . TS_TITLE_MAX . " characters.";
    if (mb_strlen($description) > TS_DESC_MAX) $errors['description'] = "Description must not exceed " . TS_DESC_MAX . " characters.";
    if (mb_strlen($remarks) > TS_REMARKS_MAX) $errors['remarks'] = "Remarks must not exceed " . TS_REMARKS_MAX . " characters.";
    if (!isset(TS_TASK_STATUS[$taskStatus])) $errors['taskStatus'] = "Please select the task status.";
    if ($reportTo === '') $errors['reportTo'] = "Please select the reporting manager.";

    if ($errors) {
        apiResponse(false, reset($errors), null, 400, $errors);
    }

    $lockReason = tsDateLockReason($date);
    if ($lockReason !== "") {
        apiResponse(false, $lockReason, null, 400);
    }

    $category = multiRec(
        "SELECT ID FROM EPT_TS_CATEGORY WHERE ID = :ID AND STATUS = 'A'",
        [':ID' => $categoryId]
    );
    if (!$category) {
        apiResponse(false, "Please select a valid category.", null, 400, ['categoryId' => "Please select a valid category."]);
    }

    // The manager must be one the employee is allowed to pick (own department / REPORT_TO).
    $allowedManagers = array_column(tsManagerOptions($empCode)['options'], 'value');
    if (!in_array($reportTo, $allowedManagers, true)) {
        $message = "Please select a reporting manager from your department.";
        apiResponse(false, $message, null, 400, ['reportTo' => $message]);
    }

    /* ---------- the day (create on first task) ---------- */

    $day = tsFindDay($empCode, $date);

    if ($day && tsIsFinalStatus($day['STATUS'])) {
        apiResponse(false, "This timesheet is already " . strtolower(TS_DAY_STATUS[$day['STATUS']]) . " and can no longer be changed.", null, 400);
    }

    if (!$day && $taskId > 0) {
        apiResponse(false, "Task not found.", null, 404);
    }

    /* ---------- the task must belong to this day when editing ---------- */

    $oldDocPath = '';
    if ($taskId > 0) {
        $own = multiRec(
            "SELECT ID, DOC_PATH FROM EPT_TIMESHEET_TASK WHERE ID = :ID AND TS_ID = :TS_ID",
            [':ID' => $taskId, ':TS_ID' => $day['ID']]
        );
        if (!$own) {
            apiResponse(false, "Task not found.", null, 404);
        }
        $oldDocPath = $own[0]['DOC_PATH'] ?? '';
    }

    /* ---------- store the uploaded document (validated first) ---------- */

    if ($hasUpload) {
        $newDocument = tsStoreDocument($_FILES['document']);
    }

    if (!$day) {
        try {
            tsExec(
                "INSERT INTO EPT_TIMESHEET (EMP_CODE, TS_DATE, STATUS, CREATED_BY, CREATED_ON)
                 VALUES (:EMP_CODE, TO_DATE(:TS_DATE, 'YYYY-MM-DD'), 'D', :EMP_CODE, SYSDATE)",
                [':EMP_CODE' => $empCode, ':TS_DATE' => $date]
            );
        } catch (RuntimeException $e) {
            // Another request created the same day first (unique key); use that one.
            if (strpos($e->getMessage(), 'ORA-00001') === false) throw $e;
        }
        $day = tsFindDay($empCode, $date);
    }

    $tsId = (int)$day['ID'];

    /* ---------- save ---------- */

    $binds = [
        ':CATEGORY_ID' => $categoryId,
        ':TASK_TITLE'  => $title,
        ':TASK_DESC'   => $description,
        ':TASK_STATUS' => $taskStatus,
        ':REMARKS'     => $remarks,
        ':REPORT_TO'   => $reportTo,
        ':EMP_CODE'    => $empCode,
    ];

    if ($taskId > 0) {
        $docSql = "";
        if ($newDocument) {
            $docSql = ", DOC_NAME = :DOC_NAME, DOC_PATH = :DOC_PATH";
            $binds += [':DOC_NAME' => $newDocument['name'], ':DOC_PATH' => $newDocument['path']];
        } elseif ($removeDocument) {
            $docSql = ", DOC_NAME = NULL, DOC_PATH = NULL";
        }

        tsExec(
            "UPDATE EPT_TIMESHEET_TASK
                SET CATEGORY_ID = :CATEGORY_ID, TASK_TITLE = :TASK_TITLE, TASK_DESC = :TASK_DESC,
                    TASK_STATUS = :TASK_STATUS, REMARKS = :REMARKS, REPORT_TO = :REPORT_TO,
                    CHG_BY = :EMP_CODE, CHG_ON = SYSDATE {$docSql}
              WHERE ID = :ID AND TS_ID = :TS_ID",
            $binds + [':ID' => $taskId, ':TS_ID' => $tsId]
        );
    } else {
        tsExec(
            "INSERT INTO EPT_TIMESHEET_TASK
                (TS_ID, CATEGORY_ID, TASK_TITLE, TASK_DESC, TASK_STATUS, REMARKS, REPORT_TO,
                 DOC_NAME, DOC_PATH, CREATED_BY, CREATED_ON)
             VALUES
                (:TS_ID, :CATEGORY_ID, :TASK_TITLE, :TASK_DESC, :TASK_STATUS, :REMARKS, :REPORT_TO,
                 :DOC_NAME, :DOC_PATH, :EMP_CODE, SYSDATE)",
            $binds + [
                ':TS_ID'    => $tsId,
                ':DOC_NAME' => $newDocument['name'] ?? null,
                ':DOC_PATH' => $newDocument['path'] ?? null,
            ]
        );
    }

    tsExec(
        "UPDATE EPT_TIMESHEET SET CHG_BY = :EMP_CODE, CHG_ON = SYSDATE WHERE ID = :TS_ID",
        [':EMP_CODE' => $empCode, ':TS_ID' => $tsId]
    );
    tsReopenDay($tsId, $empCode);

    tsCommit();

    // The old file is removed only once the new state is saved.
    if ($taskId > 0 && $oldDocPath && ($newDocument || $removeDocument)) {
        tsDeleteDocumentFile($oldDocPath);
    }

    $message = $taskId > 0 ? "Task updated successfully." : "Task added successfully.";
    if ($day['STATUS'] === 'S') {
        $message .= " Please submit the timesheet again before " . TS_CUTOFF_LABEL . ".";
    }
    apiResponse(true, $message);
} catch (Throwable $e) {
    if ($newDocument) {
        tsDeleteDocumentFile($newDocument['path']);
    }
    tsFail($e, "Unable to save the task.");
}
