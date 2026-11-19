<?php
/*
 * POST personal details.
 * Without emp_code: generates the employee code (legacy saveEmp) and creates
 * the employee (status N) with its EPP and login rows.
 * With emp_code: updates an employee that is still in status N.
 */
require_once __DIR__ . '/joiningCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $values = joiningPersonalValues($input);
    $text = static fn($key) => trim((string)($input[$key] ?? ''));
    $companyId = (string)(int)($input['comp_id'] ?? 0);

    loadHrmsAccess($empCode);
    $employeeCode = $text('emp_code');
    $binds = [];
    foreach ($values as $key => $value) {
        $binds[":{$key}"] = $value;
    }
    $binds[':chg_by'] = $empCode;

    if ($employeeCode !== '') {
        $employee = joiningLoadEmployee($employeeCode);
        joiningAssertCompanyScope($employee['COMP_ID']);
        if ($employee['STATUS'] !== 'N') {
            throw new JoiningRequestException('This employee has already been sent for authorization.', 409);
        }
        // The code series depends on the company, so it cannot change after generation.
        if ($companyId !== (string)$employee['COMP_ID']) {
            throw new JoiningRequestException('The company cannot be changed after the employee code is generated.', 422);
        }
        joiningExecute("UPDATE HR_EMPLOYEE_INFO SET
                TITLE = :title, FNAME = :fname, MNAME = :mname, LNAME = :lname, DOB = TO_DATE(:dob, 'YYYY-MM-DD'),
                ADDRESS = :address, CITY = :city, STATE = :state, COUNTRY = :country, PINCODE = :pincode,
                PERMNT_ADDRESS = :permnt_address, PERMNT_CITY = :permnt_city, PERMNT_STATE = :permnt_state,
                PERMNT_COUNTRY = :permnt_country, PERMNT_PINCODE = :permnt_pincode,
                PHONE = :phone, CELL = :cell, TEL_NO_EMERG = :tel_no_emerg, PER_EMAIL = :per_email, COM_EMAIL = :com_email,
                DOJ = TO_DATE(:doj, 'YYYY-MM-DD'), GENDER = :gender, BLOOD_GRP = :blood_grp,
                CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE EMP_CODE = :emp_code AND STATUS = 'N'", $binds + [':emp_code' => $employeeCode],
            'Could not save the employee.');
        joiningSyncEppAndLogins($employeeCode, $values, 'N', $empCode);
        return ['Employee details saved successfully.', ['emp_code' => $employeeCode]];
    }

    joiningAssertCompanyScope($companyId);
    $company = singRec("SELECT COMP_ID FROM HR_COMPANY WHERE COMP_ID = :id", [':id' => $companyId]);
    if (empty($company['COMP_ID'])) {
        throw new JoiningRequestException('Company is required.', 422);
    }
    $employeeCode = joiningNextEmployeeCode($companyId);
    joiningExecute("INSERT INTO HR_EMPLOYEE_INFO
            (EMP_CODE, TITLE, FNAME, MNAME, LNAME, DOB, ADDRESS, CITY, STATE, COUNTRY, PINCODE,
             PERMNT_ADDRESS, PERMNT_CITY, PERMNT_STATE, PERMNT_COUNTRY, PERMNT_PINCODE,
             PHONE, CELL, TEL_NO_EMERG, PER_EMAIL, COM_EMAIL, DOJ, GENDER, BLOOD_GRP, COMP_ID, STATUS, CHG_ON, CHG_BY)
        VALUES (:emp_code, :title, :fname, :mname, :lname, TO_DATE(:dob, 'YYYY-MM-DD'), :address, :city, :state, :country, :pincode,
             :permnt_address, :permnt_city, :permnt_state, :permnt_country, :permnt_pincode,
             :phone, :cell, :tel_no_emerg, :per_email, :com_email, TO_DATE(:doj, 'YYYY-MM-DD'), :gender, :blood_grp,
             :comp_id, 'N', SYSDATE, :chg_by)",
        $binds + [':emp_code' => $employeeCode, ':comp_id' => $companyId], 'Could not create the employee.');
    joiningSyncEppAndLogins($employeeCode, $values, 'N', $empCode);

    return ["Employee code {$employeeCode} generated successfully.", ['emp_code' => $employeeCode]];
}, 'saveNewEmployee.php');
