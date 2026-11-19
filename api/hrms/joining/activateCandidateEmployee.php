<?php
/*
 * POST { user_task_id } - Activate the employee created for a recruited candidate
 * (Employee Code Generation, task 3). Legacy employee_info_sv.php?ActiveUser:
 * needs Basic, Office, Tenure, Bank and App Access; then the employee, EPP
 * employee and logins become active, the candidate becomes Employed (E), task 3
 * closes and these tasks are generated:
 *   15 Employee Induction  - one per induction area set for the JD and organogram (HR_JD_INDUCTION)
 *    4 Joining Activity    - one per reference department of the JD (HR_JD_REF_DEPT)
 *   14 Employee Verification - when the employee has experience references to verify
 *      (legacy has the screen but never generated the task)
 */
require_once __DIR__ . '/joiningCandidate.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = joiningLoadCandidateTask($input['user_task_id'] ?? 0, $empCode);
    $application = $task['APPLICATION'];
    $code = trim((string)($application['EMP_CODE'] ?? ''));
    if ($code === '') {
        throw new JoiningRequestException('Generate the employee code first.', 409);
    }
    if ($application['STATUS'] !== 'T') {
        throw new JoiningRequestException('This candidate is not waiting for activation.', 409);
    }
    $employee = joiningLoadEmployee($code);
    if (!in_array($employee['STATUS'], ['N', 'T'], true)) {
        throw new JoiningRequestException('This employee is already active.', 409);
    }

    $missing = joiningActivationMissing($code);
    if ($missing) {
        throw new JoiningRequestException('Missing details: ' . implode(', ', $missing) . '.', 422);
    }

    $binds = [':chg_by' => $empCode, ':emp_code' => $code];
    joiningExecute("UPDATE HR_EMPLOYEE_INFO SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        $binds, 'Could not activate the employee.');
    joiningExecute("UPDATE HR_BCS_EMPLOYEE SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        $binds, 'Could not activate the EPP employee record.');
    joiningExecute("UPDATE HR_BCS_USERS SET STATUS = 'A', CHG_USER = :chg_by, CHG_DATE = SYSDATE WHERE EMP_CODE = :emp_code",
        $binds, 'Could not activate the EPP login.');
    joiningExecute("UPDATE SDLUSERS.SDL_USERS SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        $binds, 'Could not activate the SDL login.');

    joiningExecute("UPDATE HR_CAN_APPLICATION_DETAILS SET STATUS = 'E', CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
        [':chg_by' => $empCode, ':id' => (string)$application['ID']], 'Could not update the candidate status.');
    joiningCandidateLog($application, 'E', $task['ID'], $empCode);
    taskUpdateHR('C', '', $task['ID'], null, null);

    $name = ucwords(strtolower(joiningEmployeeName($employee)));
    $position = $application['DESIGNATION'];
    $generated = [];

    foreach (joiningRecords("SELECT DISTINCT O.COMPANY, O.DIVSN_ID, O.DEPT_ID
        FROM HR_JD_INDUCTION I INNER JOIN HR_ORGANOGRAM O ON O.ID = I.ORG_ID
        WHERE I.JD_ID = :jd_id AND I.ORG_ID = :org_id", [
        ':jd_id' => (string)$application['JD_ID'], ':org_id' => (string)$application['ORG_ID'],
    ]) as $induction) {
        joiningGenerateTask('EMP_INDUCT', $code, "Employee Induction of {$name} for the position of {$position}",
            $empCode, $induction['COMPANY'], $induction['DIVSN_ID'], $induction['DEPT_ID']);
        $generated['Employee Induction'] = true;
    }

    foreach (joiningRecords("SELECT DISTINCT DEPT_ID FROM HR_JD_REF_DEPT WHERE JD_ID = :jd_id",
        [':jd_id' => (string)$application['JD_ID']]) as $dept) {
        joiningGenerateTask('DEPT_REQ', $code, "Joining activity for {$name} for the position of {$position}",
            $empCode, $application['COMPANY'], $application['DIVSN_ID'], $dept['DEPT_ID']);
        $generated['Joining Activity'] = true;
    }

    $references = joiningRecord("SELECT COUNT(*) AS CNT FROM HR_EMP_REFERENCE WHERE EMP_CODE = :emp_code", [':emp_code' => $code]);
    if ((int)($references['CNT'] ?? 0) > 0) {
        joiningGenerateTask('EMP_VERIFY', $code, "Employee Verification of {$name} for the position of {$position}",
            $empCode, $application['COMPANY'], $application['DIVSN_ID'], JOINING_HR_DEPT_ID);
        $generated['Employee Verification'] = true;
    }

    $message = "Employee {$code} activated.";
    if ($generated) {
        $message .= ' Tasks generated: ' . implode(', ', array_keys($generated)) . '.';
    }
    return [$message, ['emp_code' => $code]];
}, 'activateCandidateEmployee.php');
