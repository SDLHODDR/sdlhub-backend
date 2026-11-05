<?php
/*
 * POST { user_task_id, action: 'save'|'submit', rows: [{ dept_act_id, status: 'Y'|'N'|'X', remarks }] }
 * Exit Activity (19), one task per department. Legacy exit_act.php / dept_access_req_sv.php:
 * save stores the HR_EMP_DEPT_ACTIVITY rows; submit (allowed only when no activity is
 * 'N') also closes the department's task - its Open/Closed status is the clearance
 * status shown on Deactivate Employee. No further task is created either way.
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    $action = strtolower(trim((string)($input['action'] ?? 'save')));
    $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new ExitRequestException('Invalid action.', 422);
    }

    [$task, $separation] = exitLoadTask(EXIT_TASK_ACTIVITY, $input['user_task_id'] ?? 0, $empCode);
    $activities = exitDepartmentActivities($task['DEPT_ID'], $separation['ID']);
    if (!$activities) {
        throw new ExitRequestException('No exit activities are mapped for this department.', 422);
    }

    $submitted = [];
    foreach ($rows as $row) {
        $submitted[(string)($row['dept_act_id'] ?? '')] = [
            'status' => strtoupper(trim((string)($row['status'] ?? ''))),
            'remarks' => trim((string)($row['remarks'] ?? '')),
        ];
    }

    foreach ($activities as $activity) {
        $row = $submitted[$activity['id']] ?? null;
        if (!$row || !in_array($row['status'], ['Y', 'N', 'X'], true) || $row['remarks'] === '') {
            throw new ExitRequestException("Status and remarks are required for: {$activity['activity']}", 422);
        }
        exitAssertLength($row['remarks'], 200, "Remarks for {$activity['activity']}");
        if ($action === 'submit' && $row['status'] === 'N') {
            throw new ExitRequestException('All activities must be Yes or Not Applicable before submitting.', 422);
        }

        exitExecute("DELETE FROM HR_EMP_DEPT_ACTIVITY
            WHERE DEPT_ACT_ID = :act_id AND DEPT_ID = :dept_id AND REF_ID = :ref_id", [
            ':act_id' => $activity['id'],
            ':dept_id' => $activity['deptId'],
            ':ref_id' => $separation['ID'],
        ], 'Could not save the exit activities.');
        exitExecute("INSERT INTO HR_EMP_DEPT_ACTIVITY
                (ID, EMP_CODE, ASON_DATE, DEPT_ACT_ID, DEPT_ID, DISP_SEQ, ACT_TYPE, STATUS, REMARKS, ACT_DESC, CHG_ON, CHG_BY, REF_ID)
            VALUES (NULL, :emp_code, SYSDATE, :act_id, :dept_id, :disp_seq, 'E', :status, :remarks, :act_desc, SYSDATE, :chg_by, :ref_id)", [
            ':emp_code' => $separation['EMP_CODE'],
            ':act_id' => $activity['id'],
            ':dept_id' => $activity['deptId'],
            ':disp_seq' => $activity['dispSeq'],
            ':status' => $row['status'],
            ':remarks' => $row['remarks'],
            ':act_desc' => $activity['activity'],
            ':chg_by' => $empCode,
            ':ref_id' => $separation['ID'],
        ], 'Could not save the exit activities.');
    }

    if ($action === 'save') {
        return ['Exit activities saved successfully.', null];
    }
    exitCloseTask($task['ID']);
    return ['Departmental exit activity submitted.', null];
}, 'saveExitActivity.php');
