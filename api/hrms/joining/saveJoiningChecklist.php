<?php
/*
 * POST { user_task_id, action: 'save'|'submit', rows: [{ id, status, remarks, date? }] }
 * Joining Activity (4), Employee Induction (15), Employee Verification (14).
 * Save keeps whatever is filled in. Submit saves too, then needs a status and
 * remarks on every row (and the induction date for 15) and closes the task.
 */
require_once __DIR__ . '/joiningChecklist.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = joiningLoadChecklistTask($input['user_task_id'] ?? 0, $empCode);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new JoiningRequestException('Invalid action.', 422);
    }
    $taskType = (string)$task['TASK_ID'];
    $statuses = JOINING_CHECKLIST_TASKS[$taskType]['statuses'];
    $employee = $task['EMPLOYEE'];
    $code = (string)$employee['EMP_CODE'];

    // Only the task's own rows can be written.
    $known = [];
    foreach (joiningChecklistRows($task) as $row) {
        $known[$row['id']] = $row;
    }
    $posted = [];
    foreach ((array)($input['rows'] ?? []) as $row) {
        $id = (string)($row['id'] ?? '');
        if (!isset($known[$id])) {
            continue;
        }
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if ($status !== '' && !isset($statuses[$status])) {
            throw new JoiningRequestException('Please select a valid status.', 422);
        }
        $remarks = trim(preg_replace('/\s+/', ' ', (string)($row['remarks'] ?? '')));
        joiningAssertLength($remarks, JOINING_CHECKLIST_REMARK_MAX, 'Remarks');
        $date = $taskType === '15' ? joiningIsoDate($row['date'] ?? '', 'Induction date', false) : '';
        $posted[$id] = ['status' => $status, 'remarks' => $remarks, 'date' => $date];
    }

    foreach ($posted as $id => $row) {
        $empty = $row['status'] === '' && $row['remarks'] === '' && $row['date'] === '';
        if ($taskType === '4') {
            joiningExecute("DELETE FROM HR_EMP_DEPT_ACTIVITY WHERE DEPT_ACT_ID = :act_id AND EMP_CODE = :emp_code AND DEPT_ID = :dept_id",
                [':act_id' => $id, ':emp_code' => $code, ':dept_id' => (string)$task['DEPT_ID']], 'Could not save the joining activity.');
            if ($empty) continue;
            joiningExecute("INSERT INTO HR_EMP_DEPT_ACTIVITY
                    (EMP_CODE, ASON_DATE, DEPT_ACT_ID, DEPT_ID, DISP_SEQ, ACT_TYPE, ACT_DESC, STATUS, REMARKS, CHG_ON, CHG_BY)
                SELECT :emp_code, SYSDATE, A.ID, A.DEPT_ID, A.DISP_SEQ, A.ACT_TYPE, A.ACT_DESC, :status, :remarks, SYSDATE, :chg_by
                FROM HR_DEPT_JOEX_ACTIVITY A WHERE A.ID = :act_id", [
                ':emp_code' => $code, ':status' => $row['status'], ':remarks' => $row['remarks'],
                ':chg_by' => $empCode, ':act_id' => $id,
            ], 'Could not save the joining activity.');
        } elseif ($taskType === '15') {
            joiningExecute("DELETE FROM HR_EMP_INDUCTION WHERE EMP_CODE = :emp_code AND INDUC_ID = :induc_id",
                [':emp_code' => $code, ':induc_id' => $id], 'Could not save the induction.');
            if ($empty) continue;
            joiningExecute("INSERT INTO HR_EMP_INDUCTION (EMP_ID, EMP_CODE, INDUC_ID, INDUC_BY, INDUC_ON, STATUS, REMARKS, CHG_ON, CHG_BY)
                VALUES (:emp_id, :emp_code, :induc_id, :chg_by, TO_DATE(:induc_on, 'YYYY-MM-DD'), :status, :remarks, SYSDATE, :chg_by)", [
                ':emp_id' => (string)$employee['ID'], ':emp_code' => $code, ':induc_id' => $id,
                ':induc_on' => $row['date'], ':status' => $row['status'], ':remarks' => $row['remarks'], ':chg_by' => $empCode,
            ], 'Could not save the induction.');
        } else {
            // Verification by the company (C), done internally (I), as legacy saveEmpRef.
            joiningExecute("UPDATE HR_EMP_REFERENCE
                SET STATUS = NVL(:status, 'N'), FERIFIC_TYPE = 'C', VERIFIC_MODE = 'I', VERIFIC_REMARKS = :remarks,
                    CHG_ON = SYSDATE, CHG_BY = :chg_by
                WHERE ID = :id AND EMP_CODE = :emp_code", [
                ':status' => $row['status'], ':remarks' => $row['remarks'], ':chg_by' => $empCode,
                ':id' => $id, ':emp_code' => $code,
            ], 'Could not save the verification.');
        }
    }

    if ($action === 'save') {
        return ['Saved successfully.', null];
    }

    $pending = 0;
    foreach (joiningChecklistRows($task) as $row) {
        if ($row['status'] === '' || trim($row['remarks']) === '' || ($taskType === '15' && ($row['date'] ?? '') === '')) {
            $pending++;
        }
    }
    if ($pending > 0) {
        $what = $taskType === '15' ? 'a date, status and remarks' : 'a status and remarks';
        throw new JoiningRequestException("Please enter {$what} for every row before submitting ({$pending} pending).", 422);
    }
    taskUpdateHR('C', '', $task['ID'], null, null);
    return [JOINING_CHECKLIST_TASKS[$taskType]['title'] . ' task submitted.', null];
}, 'saveJoiningChecklist.php');
