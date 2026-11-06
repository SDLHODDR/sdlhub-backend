<?php
/*
 * POST { emp_code } - Send for Authorization (legacy sendEmpAuth):
 * employee, EPP employee and EPP login move to T; task 45 EMP_AUTH is created.
 */
require_once __DIR__ . '/joiningCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    loadHrmsAccess($empCode);
    $employee = joiningLoadEmployee($input['emp_code'] ?? '');
    joiningAssertCompanyScope($employee['COMP_ID']);
    if ($employee['STATUS'] !== 'N') {
        throw new JoiningRequestException('This employee has already been sent for authorization.', 409);
    }
    $office = joiningEmployeeOffice($employee['EMP_CODE']);
    if (empty($office['ID'])) {
        throw new JoiningRequestException('Please save the office details before sending for authorization.', 422);
    }

    $code = $employee['EMP_CODE'];
    joiningExecute("UPDATE HR_EMPLOYEE_INFO SET STATUS = 'T', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code AND STATUS = 'N'",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not update the employee.');
    joiningExecute("UPDATE HR_BCS_EMPLOYEE SET STATUS = 'T', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not update the EPP employee record.');
    joiningExecute("UPDATE HR_BCS_USERS SET STATUS = 'T', CHG_USER = :chg_by, CHG_DATE = SYSDATE WHERE EMP_CODE = :emp_code",
        [':chg_by' => $empCode, ':emp_code' => $code], 'Could not update the EPP login.');

    // Employee's own company and division; department is Human Resource.
    joiningGenerateTask('EMP_AUTH', $code,
        'Employee Generation -   ' . $code . ' - ' . ucwords(strtolower(joiningEmployeeName($employee))),
        $empCode, $office['ORG_COMPANY'] ?: $employee['COMP_ID'], $office['ORG_DIVSN_ID'] ?: $office['DIVSN_ID'], JOINING_HR_DEPT_ID);

    return ['Employee sent for authorization.', ['emp_code' => $code]];
}, 'sendNewEmployeeForAuth.php');
