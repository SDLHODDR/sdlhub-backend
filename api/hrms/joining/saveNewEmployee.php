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

/** Next free code in the company's series; the series is locked until commit. */
function joiningNextEmployeeCode($companyId)
{
    $series = JOINING_CODE_SERIES[(string)$companyId] ?? null;
    if (!$series) {
        throw new JoiningRequestException('Employee codes are not configured for this company.', 422);
    }
    joiningExecute("SELECT COMP_ID FROM HR_COMPANY WHERE COMP_ID IN ("
        . implode(',', array_map('intval', $series['companies'])) . ") ORDER BY COMP_ID FOR UPDATE",
        [], 'Could not reserve an employee code.');

    $prefix = $series['prefix'];
    $last = singRec("SELECT MAX(EMP_CODE) AS LAST_CODE FROM HR_EMPLOYEE_INFO
        WHERE REGEXP_LIKE(EMP_CODE, '^{$prefix}[0-9]{4}$')");
    $next = !empty($last['LAST_CODE']) ? (int)$last['LAST_CODE'] + 1 : (int)($prefix . '0001');

    // Never reuse a code that already exists in EPP or the central login.
    while (true) {
        $code = str_pad((string)$next, 5, '0', STR_PAD_LEFT);
        $used = singRec("SELECT
                (SELECT COUNT(*) FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code)
              + (SELECT COUNT(*) FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :code)
              + (SELECT COUNT(*) FROM HR_BCS_USERS WHERE EMP_CODE = :code OR CODE = :code)
              + (SELECT COUNT(*) FROM SDLUSERS.SDL_USERS WHERE EMP_CODE = :code OR LOGIN_CODE = :code) AS CNT
            FROM DUAL", [':code' => $code]);
        if ((int)($used['CNT'] ?? 0) === 0) {
            return $code;
        }
        $next++;
    }
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $text = static fn($key) => trim((string)($input[$key] ?? ''));
    $upper = static fn($key) => strtoupper(trim((string)($input[$key] ?? '')));

    $values = [
        'title' => $text('title'),
        'fname' => $upper('fname'),
        'mname' => $upper('mname'),
        'lname' => $upper('lname'),
        'dob' => joiningIsoDate($input['dob'] ?? '', 'Date of birth'),
        'address' => $text('address'),
        'city' => $text('city'),
        'state' => $text('state'),
        'country' => $text('country'),
        'pincode' => $text('pincode'),
        'permnt_address' => $text('permnt_address'),
        'permnt_city' => $text('permnt_city'),
        'permnt_state' => $text('permnt_state'),
        'permnt_country' => $text('permnt_country'),
        'permnt_pincode' => $text('permnt_pincode'),
        'phone' => $text('phone'),
        'cell' => $text('cell'),
        'tel_no_emerg' => $text('tel_no_emerg'),
        'per_email' => strtolower($text('per_email')),
        'com_email' => strtolower($text('com_email')),
        'doj' => joiningIsoDate($input['doj'] ?? '', 'Date of joining'),
        'gender' => $text('gender'),
        'blood_grp' => $text('blood_grp'),
    ];
    $companyId = (string)(int)($input['comp_id'] ?? 0);

    $required = [
        'title' => 'Title', 'address' => 'Current address', 'city' => 'Current city', 'state' => 'Current state',
        'country' => 'Current country', 'pincode' => 'Current pincode', 'permnt_address' => 'Permanent address',
        'permnt_city' => 'Permanent city', 'permnt_state' => 'Permanent state', 'permnt_country' => 'Permanent country',
        'permnt_pincode' => 'Permanent pincode', 'cell' => 'Mobile number', 'per_email' => 'Personal email',
        'gender' => 'Gender',
    ];
    foreach ($required as $key => $label) {
        if ($values[$key] === '') {
            throw new JoiningRequestException("{$label} is required.", 422);
        }
    }
    // EPP columns limit names to 25 characters.
    foreach (['fname' => 'First name', 'mname' => 'Middle name', 'lname' => 'Last name'] as $key => $label) {
        if (!preg_match("/^[A-Z][A-Z.' ]{0,24}$|^\.$/", $values[$key])) {
            throw new JoiningRequestException("{$label} is required and can only contain letters, spaces, . and ' (maximum 25 characters).", 422);
        }
    }
    foreach (['pincode' => 'Current pincode', 'permnt_pincode' => 'Permanent pincode'] as $key => $label) {
        if (!preg_match('/^[0-9]{1,6}$/', $values[$key])) {
            throw new JoiningRequestException("{$label} must be up to 6 digits.", 422);
        }
    }
    if (!preg_match('/^[0-9]{10}$/', $values['cell'])) {
        throw new JoiningRequestException('Mobile number must be 10 digits.', 422);
    }
    foreach (['phone' => 'Telephone', 'tel_no_emerg' => 'Emergency contact'] as $key => $label) {
        if ($values[$key] !== '' && !preg_match('/^[0-9]{1,11}$/', $values[$key])) {
            throw new JoiningRequestException("{$label} must be up to 11 digits.", 422);
        }
    }
    if (!filter_var($values['per_email'], FILTER_VALIDATE_EMAIL)) {
        throw new JoiningRequestException('Please enter a valid personal email.', 422);
    }
    if ($values['com_email'] !== '' && !filter_var($values['com_email'], FILTER_VALIDATE_EMAIL)) {
        throw new JoiningRequestException('Please enter a valid company email.', 422);
    }
    joiningAssertLength($values['per_email'], 50, 'Personal email');
    joiningAssertLength($values['com_email'], 50, 'Company email');
    joiningAssertLength($values['address'], 300, 'Current address');
    joiningAssertLength($values['permnt_address'], 300, 'Permanent address');
    joiningAssertLength($values['city'], 50, 'Current city');
    joiningAssertLength($values['permnt_city'], 50, 'Permanent city');
    if ($values['dob'] >= date('Y-m-d')) {
        throw new JoiningRequestException('Date of birth must be in the past.', 422);
    }
    if ($values['doj'] <= $values['dob']) {
        throw new JoiningRequestException('Date of joining must be after the date of birth.', 422);
    }

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
