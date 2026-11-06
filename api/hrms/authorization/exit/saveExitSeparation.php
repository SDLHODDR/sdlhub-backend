<?php
/*
 * POST { user_task_id, action: 'authorize'|'reject', handover_to, relieving_date (YYYY-MM-DD), authorize_comments }
 * Separation Request (18). Legacy separation_sv.php?auth=true&flag=A|R:
 * authorize -> separation 'A' + Exit Interview task (HR); reject -> separation 'R'.
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $comments = trim((string)($input['authorize_comments'] ?? ''));
    $handoverTo = trim((string)($input['handover_to'] ?? ''));
    $relievingDate = trim((string)($input['relieving_date'] ?? ''));

    if (!in_array($action, ['authorize', 'reject'], true)) {
        throw new ExitRequestException('Invalid action.', 422);
    }
    if ($comments === '') {
        throw new ExitRequestException('Authorize comments are required.', 422);
    }
    exitAssertLength($comments, 500, 'Authorize comments');

    [$task, $separation] = exitLoadTask(EXIT_TASK_SEPARATION, $input['user_task_id'] ?? 0, $empCode);
    if ($separation['STATUS'] !== 'T') {
        throw new ExitRequestException('This separation request is not awaiting authorization.', 409);
    }

    if ($action === 'reject') {
        exitExecute("UPDATE HR_EMP_SEPARATION
            SET STATUS = 'R', AUTH_BY_EMP = :auth_by, AUTH_COMMENTS = :comments
            WHERE ID = :id", [
            ':auth_by' => $empCode,
            ':comments' => $comments,
            ':id' => $separation['ID'],
        ], 'Could not reject the separation request.');
        exitCloseTask($task['ID'], $comments);
        return ['Separation request rejected.', null];
    }

    if ($handoverTo === '' || !preg_match('/^[A-Za-z0-9]+$/', $handoverTo)) {
        throw new ExitRequestException('Handover employee is required.', 422);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $relievingDate);
    if (!$date || $date->format('Y-m-d') !== $relievingDate) {
        throw new ExitRequestException('A valid approved relieve date is required.', 422);
    }
    $handover = singRec("SELECT EMP_CODE FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code AND STATUS = 'A'", [':code' => $handoverTo]);
    if (empty($handover['EMP_CODE'])) {
        throw new ExitRequestException('The selected handover employee is not active.', 422);
    }

    exitExecute("UPDATE HR_EMP_SEPARATION
        SET STATUS = 'A', RELEIVING_DATE = TO_DATE(:relieving_date, 'YYYY-MM-DD'),
            AUTH_BY_EMP = :auth_by, AUTH_COMMENTS = :comments, HANDOVER_TO = :handover_to
        WHERE ID = :id", [
        ':relieving_date' => $relievingDate,
        ':auth_by' => $empCode,
        ':comments' => $comments,
        ':handover_to' => $handoverTo,
        ':id' => $separation['ID'],
    ], 'Could not authorize the separation request.');

    // Company / division from the employee's organogram on the expected relieving date (legacy).
    $office = exitEmployeeOffice($separation['EMP_CODE'], $separation['EXP_RELIV_DATE_ISO']);
    exitGenerateTask('EXIT_INT', $separation,
        'Exit Interview For ' . getEmpInfoByCodeHR($separation['EMP_CODE']) . ' - ' . $date->format('d-M-Y'),
        $empCode, $office['COMPANY'] ?? '', $office['ORG_DIVSN_ID'] ?? '', EXIT_HR_DEPT_ID);
    exitCloseTask($task['ID'], $comments);

    return ['Separation authorized and Exit Interview task generated.', null];
}, 'saveExitSeparation.php');
