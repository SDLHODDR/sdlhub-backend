<?php
/*
 * POST { user_task_id } - Authorize a new employee, task 45 (legacy AuthorizeEmp):
 * employee, EPP employee, EPP login and central login become A, task 45 closes
 * and the joining detail tasks are generated.
 */
require_once __DIR__ . '/../../joining/joiningCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $userTaskId = (int)($input['user_task_id'] ?? 0);
    if ($userTaskId <= 0) {
        throw new JoiningRequestException('A valid task is required.', 422);
    }
    loadHrmsAccess($empCode);
    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'", [
        ':id' => (string)$userTaskId,
        ':task_id' => (string)JOINING_TASK_EMP_AUTH,
    ]);
    if (empty($task['ID'])) {
        throw new JoiningRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $empCode)) {
        throw new JoiningRequestException('You are not authorized to act on this task.', 403);
    }

    $employee = joiningLoadEmployee($task['TRAN_CODE']);
    if ($employee['STATUS'] !== 'T') {
        throw new JoiningRequestException('This employee is not awaiting authorization.', 409);
    }
    $office = joiningEmployeeOffice($employee['EMP_CODE']);

    $code = $employee['EMP_CODE'];
    joiningExecute("UPDATE HR_EMPLOYEE_INFO SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code AND STATUS = 'T'",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not activate the employee.');
    joiningExecute("UPDATE HR_BCS_EMPLOYEE SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not activate the EPP employee record.');
    joiningExecute("UPDATE HR_BCS_USERS SET STATUS = 'A', CHG_USER = :chg_by, CHG_DATE = SYSDATE WHERE EMP_CODE = :emp_code",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not activate the EPP login.');
    joiningExecute("UPDATE SDLUSERS.SDL_USERS SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not activate the SDL login.');

    taskUpdateHR('C', '', $task['ID'], null, null);

    $name = ucwords(strtolower(joiningEmployeeName($employee)));
    $company = ($office['ORG_COMPANY'] ?? '') ?: $employee['COMP_ID'];
    $division = ($office['ORG_DIVSN_ID'] ?? '') ?: ($office['DIVSN_ID'] ?? '');
    foreach (JOINING_DETAIL_TASKS as $taskGroup => $label) {
        joiningGenerateTask($taskGroup, $code, "{$label} -   {$code} - {$name}", $empCode, $company, $division, JOINING_HR_DEPT_ID);
    }

    return ['Employee authorized. Joining detail tasks generated.', ['emp_code' => $code]];
}, 'authorizeNewEmployee.php');
