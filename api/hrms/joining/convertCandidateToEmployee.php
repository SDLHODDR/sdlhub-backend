<?php
/*
 * POST { user_task_id, title, fname, mname, lname, dob, address ... (same fields as
 * saveNewEmployee) } - Employee Code Generation (task 3) for a recruited candidate.
 * Legacy convertCandidateToEmp.php: generates the code in the organogram company's
 * series and creates the employee (status N) with
 *   - office from the recruitment request (organogram + location, from the joining date),
 *   - tenure from the recommender's offer (employee type / period),
 *   - qualification, experience (+ references), family and Aadhaar from the candidate,
 *   - EPP employee and logins,
 * then generates the joining detail tasks. Task 3 stays open until the employee is
 * activated (activateCandidateEmployee.php).
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
    if (trim((string)($application['EMP_CODE'] ?? '')) !== '') {
        throw new JoiningRequestException("Employee code {$application['EMP_CODE']} has already been generated for this candidate.", 409);
    }
    if ($application['STATUS'] !== 'T') {
        throw new JoiningRequestException('This candidate is not waiting for an employee code.', 409);
    }

    $values = joiningPersonalValues($input);
    $companyId = (string)$application['COMPANY'];

    $code = joiningNextEmployeeCode($companyId);
    $binds = [];
    foreach ($values as $key => $value) {
        $binds[":{$key}"] = $value;
    }
    joiningExecute("INSERT INTO HR_EMPLOYEE_INFO
            (EMP_CODE, TITLE, FNAME, MNAME, LNAME, DOB, ADDRESS, CITY, STATE, COUNTRY, PINCODE,
             PERMNT_ADDRESS, PERMNT_CITY, PERMNT_STATE, PERMNT_COUNTRY, PERMNT_PINCODE,
             PHONE, CELL, TEL_NO_EMERG, PER_EMAIL, COM_EMAIL, DOJ, GENDER, BLOOD_GRP, COMP_ID, STATUS, CHG_ON, CHG_BY)
        VALUES (:emp_code, :title, :fname, :mname, :lname, TO_DATE(:dob, 'YYYY-MM-DD'), :address, :city, :state, :country, :pincode,
             :permnt_address, :permnt_city, :permnt_state, :permnt_country, :permnt_pincode,
             :phone, :cell, :tel_no_emerg, :per_email, :com_email, TO_DATE(:doj, 'YYYY-MM-DD'), :gender, :blood_grp,
             :comp_id, 'N', SYSDATE, :chg_by)",
        $binds + [':emp_code' => $code, ':comp_id' => $companyId, ':chg_by' => $empCode], 'Could not create the employee.');
    $employee = joiningLoadEmployee($code);
    $employeeId = (string)$employee['ID'];
    $scope = [':emp_id' => $employeeId, ':emp_code' => $code, ':chg_by' => $empCode];

    joiningExecute("UPDATE HR_CAN_APPLICATION_DETAILS SET EMP_CODE = :emp_code, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
        [':emp_code' => $code, ':chg_by' => $empCode, ':id' => (string)$application['ID']], 'Could not link the employee to the candidate.');

    // Office: the position was taken (fill count) when the joinee was submitted.
    $office = [
        'divsn_id' => (string)$application['DIVSN_ID'],
        'dept_id' => (string)$application['DEPT_ID'],
        'desi_id' => (string)$application['DESI_ID'],
        'org_id' => (string)$application['ORG_ID'],
        'org_loc_id' => (string)$application['ORG_LOC_ID'],
    ];
    joiningExecute("INSERT INTO HR_EMP_OFFICE_DET
            (EMP_ID, EMP_CODE, DIVSN_ID, DEPT_ID, DESI_ID, ORG_ID, ORG_LOC_ID, EFFEC_FROM, CHG_ON, CHG_BY)
        VALUES (:emp_id, :emp_code, :divsn_id, :dept_id, :desi_id, :org_id, :org_loc_id, TO_DATE(:doj, 'YYYY-MM-DD'), SYSDATE, :chg_by)",
        $scope + [
            ':divsn_id' => $office['divsn_id'], ':dept_id' => $office['dept_id'], ':desi_id' => $office['desi_id'],
            ':org_id' => $office['org_id'], ':org_loc_id' => $office['org_loc_id'], ':doj' => $values['doj'],
        ], 'Could not save the office details.');

    // Tenure from the offer: a period gives an end date, a permanent employee has none.
    $offer = joiningRecord("SELECT * FROM (SELECT ETYPE_ID, ETYPE_PERIOD FROM HR_INTRV_DATES
            WHERE CAN_ID = :can_id AND REQ_ID = :req_id AND TNT_JOIN_DATE IS NOT NULL ORDER BY ID DESC)
        WHERE ROWNUM = 1", [':can_id' => (string)$application['CAN_ID'], ':req_id' => (string)$application['REQ_ID']]);
    if (!empty($offer['ETYPE_ID'])) {
        $period = (int)($offer['ETYPE_PERIOD'] ?? 0);
        joiningExecute("INSERT INTO HR_EMP_TENURE (EMP_ID, EMP_CODE, ETYPE_ID, ETYPE_PERIOD, EFF_FROM, EFF_TO, CHG_ON, CHG_BY)
            VALUES (:emp_id, :emp_code, :etype_id, :period, TO_DATE(:doj, 'YYYY-MM-DD'),
                CASE WHEN :months > 0 THEN ADD_MONTHS(TO_DATE(:doj, 'YYYY-MM-DD'), :months) - 1 END, SYSDATE, :chg_by)",
            $scope + [
                ':etype_id' => (string)$offer['ETYPE_ID'], ':period' => $period > 0 ? (string)$period : '',
                ':doj' => $values['doj'], ':months' => (string)$period,
            ], 'Could not save the tenure details.');
    }

    $candidate = [':can_id' => (string)$application['CAN_ID']];
    joiningExecute("INSERT INTO HR_EMP_EDUCATION (EMP_ID, EMP_CODE, COURSE, INST_NAME, TO_DATE, PERGRADE, CHG_ON, CHG_BY)
        SELECT :emp_id, :emp_code, COURSE, INST_NAME, PASSING_DATE, SUBSTRB(REMARKS, 1, 20), SYSDATE, :chg_by
        FROM HR_CAN_EDUCATION WHERE CAN_ID = :can_id ORDER BY ID", $scope + $candidate, 'Could not copy the qualifications.');

    foreach (joiningRecords("SELECT ID, ORG_NAME, DESIG, FROM_DATE, TO_DATE, GROSS_SALARY, DUTY_NATURE, LEAVE_REASON,
            REF_REPORT_TO, REF_EMAIL, REF_DESIGNATION, REF_PHONE, REF_YEARS
        FROM HR_CAN_EXPERIENCE WHERE CAN_ID = :can_id ORDER BY ID", $candidate) as $experience) {
        joiningExecute("INSERT INTO HR_EMP_EXPERIENCE (EMP_ID, EMP_CODE, ORG_NAME, DESIG, FROM_DATE, TO_DATE, GROSS_SALARY,
                DUTY_NATURE, LEAVE_REASON, CHG_ON, CHG_BY)
            SELECT :emp_id, :emp_code, ORG_NAME, DESIG, FROM_DATE, TO_DATE, GROSS_SALARY, DUTY_NATURE, LEAVE_REASON, SYSDATE, :chg_by
            FROM HR_CAN_EXPERIENCE WHERE ID = :exp_id", $scope + [':exp_id' => (string)$experience['ID']],
            'Could not copy the experience.');
        $refName = trim((string)($experience['REF_REPORT_TO'] ?? ''));
        if ($refName === '' && trim((string)($experience['REF_PHONE'] ?? '')) === '') {
            continue;
        }
        // References are verified in Employee Verification (task 14).
        joiningExecute("INSERT INTO HR_EMP_REFERENCE (EXP_ID, EMP_CODE, REF_NAME, ADDRESS, POSITION, TEL, YEAR_KNOWN, STATUS, CHG_ON, CHG_BY)
            SELECT MAX(ID), :emp_code, SUBSTRB(:ref_name, 1, 50), SUBSTRB(:ref_email, 1, 100), SUBSTRB(:ref_desig, 1, 50),
                SUBSTRB(:ref_phone, 1, 15), SUBSTRB(:ref_years, 1, 20), 'N', SYSDATE, :chg_by
            FROM HR_EMP_EXPERIENCE WHERE EMP_CODE = :emp_code", [
            ':emp_code' => $code,
            ':ref_name' => $refName,
            ':ref_email' => (string)($experience['REF_EMAIL'] ?? ''),
            ':ref_desig' => (string)($experience['REF_DESIGNATION'] ?? ''),
            ':ref_phone' => (string)($experience['REF_PHONE'] ?? ''),
            ':ref_years' => (string)($experience['REF_YEARS'] ?? ''),
            ':chg_by' => $empCode,
        ], 'Could not copy the experience reference.');
    }

    joiningExecute("INSERT INTO HR_EMP_FAMILY_INFO (EMP_ID, EMP_CODE, FM_NAME, FM_RELATION, FM_CONTACT, AGE, FM_OCCUPATION,
            FM_DEP, DOB, CHG_ON, CHG_BY)
        SELECT :emp_id, :emp_code, FM_NAME, FM_RELATION, FM_CONTACT, AGE, INITCAP(FM_OCCUPATION), INITCAP(FM_DEP), DOB, SYSDATE, :chg_by
        FROM HR_CAN_FAMILY_INFO WHERE CAN_ID = :can_id ORDER BY ID", $scope + $candidate, 'Could not copy the family details.');

    joiningExecute("INSERT INTO HR_EMP_BASIC_INFO (EMP_ID, EMP_CODE, ADHAR_NO, CHG_ON, CHG_BY)
        SELECT :emp_id, :emp_code, AADHAR_NO, SYSDATE, :chg_by
        FROM HR_CANDIDATE_INFO WHERE ID = :can_id AND TRIM(AADHAR_NO) IS NOT NULL", $scope + $candidate,
        'Could not copy the Aadhaar number.');

    joiningSyncEppAndLogins($code, $values, 'N', $empCode);
    joiningUpdateEppOffice($code, $office, $empCode);

    $name = ucwords(strtolower(joiningEmployeeName($employee)));
    foreach (JOINING_DETAIL_TASKS as $taskGroup => $label) {
        joiningGenerateTask($taskGroup, $code, "{$label} -   {$code} - {$name}", $empCode,
            $companyId, $application['DIVSN_ID'], JOINING_HR_DEPT_ID);
    }

    // Legacy: the request's Send Suitable / Shortlist tasks end with the hire.
    joiningExecute("UPDATE HR_USER_TASKS SET STATUS = 'C', AUTH_ON = SYSDATE, AUTH_BY = :chg_by
        WHERE TRAN_CODE = :req_id AND TASK_ID IN (6, 7) AND STATUS = 'O'",
        [':chg_by' => $empCode, ':req_id' => (string)$application['REQ_ID']], 'Could not close the recruitment tasks.');

    return ["Employee code {$code} generated. Fill in the joining details, then activate the employee.",
        ['emp_code' => $code, 'user_task_id' => (string)$task['ID']]];
}, 'convertCandidateToEmployee.php');
