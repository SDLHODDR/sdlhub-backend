<?php
/* POST { task_id: 2, user_task_id, action: 'authorize'|'reject', remarks }
 *   HOD decision. Authorize sets 'A' and creates Assign Recruiter (5); reject sets 'R' and ends the request.
 * POST { task_id: 5, user_task_id, action: 'assign', assigned_to }
 *   Sets 'I' and creates Send Suitable Candidates (6) for the recruiter.
 * Legacy recruitment_sv.php?AcceptReqData / RejectReqData / AssignRecruiter. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunAction(function () use ($empCode) {
    $input = recruitmentInput();
    $workflowTaskId = (int)($input['task_id'] ?? 0);
    $userTaskId = (int)($input['user_task_id'] ?? 0);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $allowed = [RECRUITMENT_TASK_AUTHORIZE => ['authorize', 'reject'], RECRUITMENT_TASK_ASSIGN => ['assign']];
    if (!in_array($action, $allowed[$workflowTaskId] ?? [], true)) {
        throw new RecruitmentRequestException('Invalid action.', 422);
    }

    [, $request] = recruitmentLoadTask($workflowTaskId, $userTaskId, $empCode);
    $requestId = (string)$request['ID'];
    $position = $request['POSITION'] ?? '';

    if ($action === 'assign') {
        $recruiter = trim((string)($input['assigned_to'] ?? ''));
        if (!in_array($recruiter, array_column(recruitmentRecruiters(), 'EMP_CODE'), true)) {
            throw new RecruitmentRequestException('Please select a recruiter.', 422);
        }
        recruitmentCloseTask($userTaskId, $empCode);
        recruitmentExecute("UPDATE HR_RECRUITMENT SET STATUS = 'I', ASSIGNED_TO = :recruiter, CHG_ON = SYSDATE, CHG_BY = :changed_by
            WHERE ID = :id AND STATUS = 'A'",
            [':id' => $requestId, ':recruiter' => $recruiter, ':changed_by' => $empCode], 'Could not assign the recruiter.');
        generateTaskHR('SEND_SUIT', $requestId, 'REQ_ID#' . $requestId . ' Send Suitable Candidates for ' . $position,
            null, null, 'O', $recruiter, $empCode, $request['COMPANY'] ?? '', $request['DIVSN_ID'] ?? '', $request['DEPT_ID'] ?? '');
        return ['Recruiter has been assigned.', null];
    }

    $remarks = trim((string)($input['remarks'] ?? ''));
    if ($remarks === '') {
        throw new RecruitmentRequestException('Authorization remarks are required.', 422);
    }
    recruitmentAssertLength($remarks, RECRUITMENT_REMARK_MAX, 'Authorization remarks');
    recruitmentCloseTask($userTaskId, $empCode);

    if ($action === 'reject') {
        recruitmentExecute("UPDATE HR_RECRUITMENT SET STATUS = 'R', AUTH_REMARK = :remarks, CHG_ON = SYSDATE, CHG_BY = :changed_by
            WHERE ID = :id AND STATUS = 'T'",
            [':id' => $requestId, ':remarks' => $remarks, ':changed_by' => $empCode], 'Could not reject the request.');
        return ['Request rejected.', null];
    }

    recruitmentExecute("UPDATE HR_RECRUITMENT SET STATUS = 'A', AUTH_REMARK = :remarks, AUTH_ON = SYSDATE, AUTH_BY = :changed_by
        WHERE ID = :id AND STATUS = 'T'",
        [':id' => $requestId, ':remarks' => $remarks, ':changed_by' => $empCode], 'Could not authorize the request.');
    generateTaskHR('ASS_REQ', $requestId, 'REQ_ID#' . $requestId . ' : Assign Recruiter for ' . $position,
        null, null, 'O', null, $empCode, $request['COMPANY'] ?? '', $request['DIVSN_ID'] ?? '', $request['DEPT_ID'] ?? '');
    return ['Request authorized. A task has been generated for HR to assign a recruiter.', null];
}, 'processRecruitmentTask.php');
