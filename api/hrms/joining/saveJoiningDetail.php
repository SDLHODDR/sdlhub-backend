<?php
/*
 * POST { user_task_id, id?, values } - Save one tab of a joining detail task
 * (legacy employee_info_sv2.php: savebasDet, saveTenureDet, saveBankDet,
 * saveQualDet, saveExpDet, saveFamDet, saveKRA, saveAccess, saveOtherheads).
 * The tab comes from the task, so a task can only change its own tab.
 */
require_once __DIR__ . '/joiningDetailCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

// Text in names and descriptions; legacy allowed letters only, which rejected
// values like "B.Sc." and "St. Xavier's".
const JOINING_TEXT_PATTERN = "/^[\\p{L}0-9 .,'&()\\/+%-]+$/u";

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = joiningLoadDetailTask($input['user_task_id'] ?? 0, $empCode);
    $values = is_array($input['values'] ?? null) ? $input['values'] : [];
    $id = (int)($input['id'] ?? 0);
    $handlers = [
        'basic' => 'joiningSaveBasic',
        'tenure' => 'joiningSaveTenure',
        'bank' => 'joiningSaveBank',
        'qualification' => 'joiningSaveQualification',
        'experience' => 'joiningSaveExperience',
        'family' => 'joiningSaveFamily',
        'kra' => 'joiningSaveKra',
        'access' => 'joiningSaveAccess',
        'ctc' => 'joiningSaveCtc',
    ];
    $handlers[$task['SECTION']]($task['EMPLOYEE'], $values, $id, $empCode);
    return ['Saved successfully.', null];
}, 'saveJoiningDetail.php');

/** An edited row must belong to this employee. */
function joiningAssertOwnRow($table, $id, $employeeCode)
{
    $row = singRec("SELECT ID FROM {$table} WHERE ID = :id AND EMP_CODE = :emp_code",
        [':id' => (string)$id, ':emp_code' => $employeeCode]);
    if (empty($row['ID'])) {
        throw new JoiningRequestException('The record was not found.', 404);
    }
}

/** Insert or update one row; $columns holds column => bind, with dates as ISO strings in $dates. */
function joiningUpsert($table, $id, array $employee, array $columns, array $dates, $sessionEmp, $withEmpId = true)
{
    $binds = [':chg_by' => $sessionEmp];
    $names = [];
    $sqlValues = [];
    foreach ($columns as $column => $value) {
        $bind = ':f_' . strtolower($column);
        $binds[$bind] = $value;
        $names[] = $column;
        $sqlValues[] = in_array($column, $dates, true) ? "TO_DATE({$bind}, 'YYYY-MM-DD')" : $bind;
    }
    if ($id > 0) {
        joiningAssertOwnRow($table, $id, $employee['EMP_CODE']);
        $set = [];
        foreach ($names as $index => $column) {
            $set[] = "{$column} = {$sqlValues[$index]}";
        }
        $binds[':id'] = (string)$id;
        joiningExecute("UPDATE {$table} SET " . implode(', ', $set) . ", CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            $binds, 'Could not update the record.');
        return;
    }
    $names[] = 'EMP_CODE';
    $sqlValues[] = ':emp_code';
    $binds[':emp_code'] = $employee['EMP_CODE'];
    if ($withEmpId) {
        $names[] = 'EMP_ID';
        $sqlValues[] = ':emp_id';
        $binds[':emp_id'] = $employee['ID'];
    }
    joiningExecute("INSERT INTO {$table} (" . implode(', ', $names) . ", CHG_BY, CHG_ON)
        VALUES (" . implode(', ', $sqlValues) . ", :chg_by, SYSDATE)", $binds, 'Could not save the record.');
}

function joiningSaveBasic(array $employee, array $values, $id, $sessionEmp)
{
    $code = $employee['EMP_CODE'];
    $aadhaar = joiningText($values, 'ADHAR_NO', 'Aadhaar number', 12, true);
    joiningAssertAadhaar($aadhaar);
    $pan = strtoupper(joiningText($values, 'PAN_NO', 'PAN', 10));
    if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
        throw new JoiningRequestException('Invalid PAN number.', 422);
    }
    $identifier = '/^[A-Za-z0-9\/ -]+$/';
    $license = joiningText($values, 'DRIV_LICE_NO', 'Driving licence number', 50, false, $identifier);
    $passport = joiningText($values, 'PASSPORT_NO', 'Passport number', 50, false, $identifier);
    $esi = joiningText($values, 'ESI_NO', 'ESI number', 20, false, $identifier);
    $nationality = joiningText($values, 'NATIONALITY', 'Nationality', 5, true);
    joiningAssertMaster($nationality, "SELECT 1 AS X FROM HR_NATIONALITY WHERE NATN_ID = :value", 'nationality');
    $marital = joiningText($values, 'M_STATUS', 'Marital status', 1);
    if ($marital !== '') joiningAssertMaster($marital, "SELECT 1 AS X FROM HR_MARITAL WHERE MARI_ID = :value", 'marital status');
    $doa = joiningIsoDate($values['DOA'] ?? '', 'Date of anniversary', false);
    $language = joiningText($values, 'MOTHER_LANG', 'Mother language', 50, false, JOINING_TEXT_PATTERN);
    $religion = joiningText($values, 'RELIGION', 'Religion', 15);
    if ($religion !== '') joiningAssertMaster($religion, "SELECT 1 AS X FROM HR_RELIGION WHERE RELI_ID = :value", 'religion');
    $pf = joiningText($values, 'PF_NO', 'PF number', 20, false, $identifier);
    $fpf = joiningText($values, 'FPF_NO', 'FPF number', 20, false, $identifier);
    $nominee = joiningText($values, 'PF_NOMINEE', 'PF nominee', 100, false, JOINING_TEXT_PATTERN);
    $member = joiningText($values, 'MEMBER_ID', 'Member ID', 50, false, $identifier);
    $uan = joiningText($values, 'UAN_NO', 'UAN number', 50, false, $identifier);
    $retireAge = joiningText($values, 'RETIRE_AGE', 'Retirement age', 2);
    if ($retireAge !== '' && !in_array($retireAge, ['55', '58', '60'], true)) {
        throw new JoiningRequestException('Please select a valid retirement age.', 422);
    }
    $citizen = joiningText($values, 'CITIZEN_NO', 'Citizen number', 25, false, $identifier);

    // Identity numbers cannot repeat between active employees (legacy uniqueCheck).
    foreach (['ADHAR_NO' => [$aadhaar, 'Aadhaar number'], 'PAN_NO' => [$pan, 'PAN'],
              'PASSPORT_NO' => [$passport, 'Passport number'], 'PF_NO' => [$pf, 'PF number']] as $column => [$value, $label]) {
        if ($value === '') continue;
        $duplicate = singRec("SELECT B.ID FROM HR_EMP_BASIC_INFO B
            INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = B.EMP_CODE
            WHERE UPPER(TRIM(B.{$column})) = :value AND B.EMP_CODE <> :emp_code AND E.STATUS = 'A' AND ROWNUM = 1",
            [':value' => strtoupper($value), ':emp_code' => $code]);
        if (!empty($duplicate['ID'])) {
            throw new JoiningRequestException("This {$label} already belongs to another active employee.", 409);
        }
    }

    $binds = [
        ':adhar_no' => $aadhaar, ':pan_no' => $pan, ':driv_lice_no' => $license, ':passport_no' => $passport,
        ':esi_no' => $esi, ':nationality' => $nationality, ':m_status' => $marital, ':doa' => $doa,
        ':mother_lang' => $language, ':religion' => $religion, ':pf_no' => $pf, ':pf_nominee' => $nominee,
        ':uan_no' => $uan, ':member_id' => $member, ':fpf_no' => $fpf, ':retire_age' => $retireAge,
        ':citizen_no' => $citizen, ':chg_by' => $sessionEmp, ':emp_code' => $code,
    ];
    $gratuity = "(SELECT ADD_MONTHS(DOJ, 60) FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code)";
    $existing = singRec("SELECT ID FROM HR_EMP_BASIC_INFO WHERE EMP_CODE = :emp_code AND ROWNUM = 1", [':emp_code' => $code]);
    if (!empty($existing['ID'])) {
        joiningExecute("UPDATE HR_EMP_BASIC_INFO SET ADHAR_NO = :adhar_no, PAN_NO = :pan_no, DRIV_LICE_NO = :driv_lice_no,
                PASSPORT_NO = :passport_no, ESI_NO = :esi_no, NATIONALITY = :nationality, M_STATUS = :m_status,
                DOA = TO_DATE(:doa, 'YYYY-MM-DD'), MOTHER_LANG = :mother_lang, RELIGION = :religion, PF_NO = :pf_no,
                PF_NOMINEE = :pf_nominee, UAN_NO = :uan_no, MEMBER_ID = :member_id, FPF_NO = :fpf_no,
                RETIRE_AGE = :retire_age, GRATUITY_DATE = {$gratuity}, CITIZEN_NO = :citizen_no,
                CHG_BY = :chg_by, CHG_ON = SYSDATE
            WHERE EMP_CODE = :emp_code", $binds, 'Could not update the basic details.');
    } else {
        $binds[':emp_id'] = $employee['ID'];
        joiningExecute("INSERT INTO HR_EMP_BASIC_INFO (EMP_ID, EMP_CODE, ADHAR_NO, PAN_NO, DRIV_LICE_NO, PASSPORT_NO, ESI_NO,
                NATIONALITY, M_STATUS, DOA, MOTHER_LANG, RELIGION, PF_NO, PF_NOMINEE, UAN_NO, MEMBER_ID, FPF_NO,
                RETIRE_AGE, GRATUITY_DATE, CITIZEN_NO, CHG_BY, CHG_ON)
            VALUES (:emp_id, :emp_code, :adhar_no, :pan_no, :driv_lice_no, :passport_no, :esi_no,
                :nationality, :m_status, TO_DATE(:doa, 'YYYY-MM-DD'), :mother_lang, :religion, :pf_no, :pf_nominee,
                :uan_no, :member_id, :fpf_no, :retire_age, {$gratuity}, :citizen_no, :chg_by, SYSDATE)",
            $binds, 'Could not save the basic details.');
    }

    joiningExecute("UPDATE HR_BCS_EMPLOYEE SET AADHAR_NO = :adhar_no, IT_NO = :pan_no, ESIC_NO = :esi_no,
            NATIONALITY = :nationality, M_STATUS = :m_status, RELIGION = :religion, PF_NO = :pf_no,
            PF_NOMINEE = :pf_nominee, UAN_NO = :uan_no, MEMBER_ID = :member_id, FPF_NO = :fpf_no,
            RETIRE_AGE = :retire_age, GRATUITY_DATE = {$gratuity}, CITIZEN_ID = :citizen_no,
            CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code", array_diff_key($binds, array_flip([':driv_lice_no', ':passport_no', ':doa', ':mother_lang', ':emp_id'])),
        'Could not update the EPP employee record.');
}

function joiningSaveTenure(array $employee, array $values, $id, $sessionEmp)
{
    $code = $employee['EMP_CODE'];
    $type = joiningText($values, 'ETYPE_ID', 'Employee type', 1, true);
    joiningAssertMaster($type, "SELECT 1 AS X FROM HR_EMP_TYPE WHERE ETYPE_ID = :value", 'employee type');
    $period = joiningText($values, 'ETYPE_PERIOD', 'Period', 4, false, '/^[0-9]+$/', 'Period must be a number of months.');
    $from = joiningIsoDate($values['EFF_FROM'] ?? '', 'Effective from');
    $to = joiningIsoDate($values['EFF_TO'] ?? '', 'Effective to', false);
    joiningAssertDateOrder($from, $to, 'Effective from', 'Effective to');
    if ($from < $employee['DOJ_ISO']) {
        throw new JoiningRequestException('Effective from cannot be before the date of joining.', 422);
    }
    $overlap = singRec("SELECT ID FROM HR_EMP_TENURE
        WHERE EMP_CODE = :emp_code AND ID <> :id
          AND EFF_FROM <= NVL(TO_DATE(:eff_to, 'YYYY-MM-DD'), DATE '3000-03-01')
          AND NVL(EFF_TO, DATE '3000-03-01') >= TO_DATE(:eff_from, 'YYYY-MM-DD')
          AND ROWNUM = 1", [':emp_code' => $code, ':id' => (string)$id, ':eff_to' => $to, ':eff_from' => $from]);
    if (!empty($overlap['ID'])) {
        throw new JoiningRequestException('This period overlaps a saved tenure record.', 409);
    }
    joiningUpsert('HR_EMP_TENURE', $id, $employee,
        ['ETYPE_ID' => $type, 'ETYPE_PERIOD' => $period, 'EFF_FROM' => $from, 'EFF_TO' => $to],
        ['EFF_FROM', 'EFF_TO'], $sessionEmp);
    joiningExecute("UPDATE HR_BCS_EMPLOYEE SET EMP_TYPE = :emp_type, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        [':emp_type' => $type, ':chg_by' => $sessionEmp, ':emp_code' => $code], 'Could not update the EPP employee record.');
}

function joiningSaveBank(array $employee, array $values, $id, $sessionEmp)
{
    $country = singRec("SELECT COUNTRY FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employee['EMP_CODE']]);
    $ifscLength = in_array(strtoupper((string)($country['COUNTRY'] ?? '')), ['NP', 'NEP'], true) ? 3 : 11;
    $name = strtoupper(joiningText($values, 'BANK_NAME', 'Bank name', 100, true, JOINING_TEXT_PATTERN));
    $branch = strtoupper(joiningText($values, 'BANK_BRANCH', 'Bank branch', 100, true, JOINING_TEXT_PATTERN));
    $account = strtoupper(joiningText($values, 'BANK_ACNO', 'Account number', 30, true, '/^[A-Za-z0-9]+$/', 'Account number can only contain letters and digits.'));
    $ifsc = strtoupper(joiningText($values, 'BANK_IFSC', 'IFSC', $ifscLength, true, '/^[A-Za-z0-9]+$/'));
    if (strlen($ifsc) !== $ifscLength) {
        throw new JoiningRequestException("IFSC must be {$ifscLength} characters.", 422);
    }
    $nominee = strtoupper(joiningText($values, 'BANK_NOMINEE', 'Nominee name', 20, true, JOINING_TEXT_PATTERN));
    $status = joiningText($values, 'STATUS', 'Status', 1, true);
    if (!in_array($status, ['A', 'N'], true)) {
        throw new JoiningRequestException('Please select a valid status.', 422);
    }
    joiningUpsert('HR_EMP_BANK_DET', $id, $employee, [
        'BANK_NAME' => $name, 'BANK_BRANCH' => $branch, 'BANK_ACNO' => $account,
        'BANK_IFSC' => $ifsc, 'BANK_NOMINEE' => $nominee, 'STATUS' => $status,
    ], [], $sessionEmp);
    // Payroll pays the active account only.
    if ($status === 'A') {
        joiningExecute("UPDATE HR_BCS_EMPLOYEE SET BANK_ACCT = :account, AC_BRANCH_NAME = :branch, AC_IFSC_NO = :ifsc,
                BANK_NAME = :name, CHG_BY = :chg_by, CHG_ON = SYSDATE
            WHERE EMP_CODE = :emp_code", [
            ':account' => $account, ':branch' => $branch, ':ifsc' => $ifsc, ':name' => $name,
            ':chg_by' => $sessionEmp, ':emp_code' => $employee['EMP_CODE'],
        ], 'Could not update the EPP employee record.');
    }
}

function joiningSaveQualification(array $employee, array $values, $id, $sessionEmp)
{
    $from = joiningIsoDate($values['FROM_DATE'] ?? '', 'Start date');
    $to = joiningIsoDate($values['TO_DATE'] ?? '', 'End date');
    joiningAssertDateOrder($from, $to, 'start date', 'End date');
    joiningUpsert('HR_EMP_EDUCATION', $id, $employee, [
        'INST_NAME' => joiningText($values, 'INST_NAME', 'Institute name', 200, true, JOINING_TEXT_PATTERN),
        'COURSE' => joiningText($values, 'COURSE', 'Qualification', 100, true, JOINING_TEXT_PATTERN),
        'PERGRADE' => joiningText($values, 'PERGRADE', 'Grade / percentage / CGPA', 20, true),
        'FROM_DATE' => $from,
        'TO_DATE' => $to,
        'REMARKS' => joiningText($values, 'REMARKS', 'Remark', 100),
    ], ['FROM_DATE', 'TO_DATE'], $sessionEmp);
}

function joiningSaveExperience(array $employee, array $values, $id, $sessionEmp)
{
    $from = joiningIsoDate($values['FROM_DATE'] ?? '', 'Start date');
    $to = joiningIsoDate($values['TO_DATE'] ?? '', 'End date');
    joiningAssertDateOrder($from, $to, 'start date', 'End date');
    joiningUpsert('HR_EMP_EXPERIENCE', $id, $employee, [
        'ORG_NAME' => joiningText($values, 'ORG_NAME', 'Organization name', 100, true),
        'DESIG' => joiningText($values, 'DESIG', 'Designation', 100, true, JOINING_TEXT_PATTERN),
        'FROM_DATE' => $from,
        'TO_DATE' => $to,
        'GROSS_SALARY' => joiningText($values, 'GROSS_SALARY', 'Gross salary', 8, true, '/^[0-9]+$/', 'Gross salary must be a number.'),
        'DUTY_NATURE' => joiningText($values, 'DUTY_NATURE', 'Nature of duty', 700, true, JOINING_TEXT_PATTERN),
        'LEAVE_REASON' => joiningText($values, 'LEAVE_REASON', 'Leave reason', 4000, true, JOINING_TEXT_PATTERN),
    ], ['FROM_DATE', 'TO_DATE'], $sessionEmp);
}

function joiningSaveFamily(array $employee, array $values, $id, $sessionEmp)
{
    $code = $employee['EMP_CODE'];
    $relation = joiningText($values, 'FM_RELATION', 'Relationship', 50, true);
    if (!in_array($relation, ['Mother', 'Father', 'Wife', 'Husband', 'Son', 'Daughter', 'Sister', 'Brother'], true)) {
        throw new JoiningRequestException('Please select a valid relationship.', 422);
    }
    // Only children can repeat (legacy removed the others from the list).
    if (!in_array($relation, ['Son', 'Daughter'], true)) {
        $duplicate = singRec("SELECT ID FROM HR_EMP_FAMILY_INFO
            WHERE EMP_CODE = :emp_code AND FM_RELATION = :relation AND ID <> :id AND ROWNUM = 1",
            [':emp_code' => $code, ':relation' => $relation, ':id' => (string)$id]);
        if (!empty($duplicate['ID'])) {
            throw new JoiningRequestException("{$relation} has already been added.", 409);
        }
    }
    $dependent = joiningText($values, 'FM_DEP', 'Dependent', 50, true);
    if (!in_array($dependent, ['Dependant', 'Non-Dependant', 'Deceased', 'Not-Applicable'], true)) {
        throw new JoiningRequestException('Please select a valid dependent status.', 422);
    }
    $deceased = $dependent === 'Deceased';
    $dob = $deceased ? '' : joiningIsoDate($values['FM_DOB'] ?? '', 'Date of birth');
    if ($dob !== '' && $dob >= date('Y-m-d')) {
        throw new JoiningRequestException('Date of birth must be in the past.', 422);
    }
    $aadhaar = joiningText($values, 'AADHAAR', 'Aadhaar number', 12, !$deceased);
    if ($aadhaar !== '') joiningAssertAadhaar($aadhaar);
    joiningUpsert('HR_EMP_FAMILY_INFO', $id, $employee, [
        'FM_NAME' => joiningText($values, 'FM_NAME', 'Family member name', 100, true, JOINING_TEXT_PATTERN),
        'FM_RELATION' => $relation,
        'FM_DEP' => $dependent,
        'DOB' => $dob,
        'AADHAAR' => $aadhaar,
        'FM_OCCUPATION' => joiningText($values, 'FM_OCCUPATION', 'Occupation', 100, !$deceased, JOINING_TEXT_PATTERN),
        'FM_CONTACT' => joiningText($values, 'FM_CONTACT', 'Contact number', 10, false, '/^[0-9]+$/', 'Contact number can only contain digits.'),
    ], ['DOB'], $sessionEmp);
}

function joiningSaveKra(array $employee, array $values, $id, $sessionEmp)
{
    $kra = joiningText($values, 'KRA_ID', 'KRA', 10, true, '/^[0-9]+$/');
    joiningAssertMaster($kra, "SELECT 1 AS X FROM HR_KRA_MASTER WHERE KRA_ID = :value", 'KRA');
    $percent = joiningText($values, 'RESP_PERC', 'KRA %', 3, true, '/^[0-9]+$/', 'KRA % must be a whole number.');
    if ((int)$percent > 100) {
        throw new JoiningRequestException('KRA % cannot be more than 100.', 422);
    }
    $from = joiningIsoDate($values['EFFEC_FROM'] ?? '', 'Effective from');
    $to = joiningIsoDate($values['EFFEC_TO'] ?? '', 'Effective to', false);
    joiningAssertDateOrder($from, $to, 'Effective from', 'Effective to');
    $duplicate = singRec("SELECT ID FROM HR_EMP_KRA
        WHERE EMP_CODE = :emp_code AND KRA_ID = :kra AND ID <> :id
          AND NVL(EFFEC_FROM, DATE '2000-03-01') <= NVL(TO_DATE(:eff_to, 'YYYY-MM-DD'), DATE '3000-03-01')
          AND NVL(EFFEC_TO, DATE '3000-03-01') >= TO_DATE(:eff_from, 'YYYY-MM-DD')
          AND ROWNUM = 1", [':emp_code' => $employee['EMP_CODE'], ':kra' => $kra, ':id' => (string)$id, ':eff_to' => $to, ':eff_from' => $from]);
    if (!empty($duplicate['ID'])) {
        throw new JoiningRequestException('This KRA is already assigned for that period.', 409);
    }
    joiningUpsert('HR_EMP_KRA', $id, $employee,
        ['KRA_ID' => $kra, 'RESP_PERC' => $percent, 'EFFEC_FROM' => $from, 'EFFEC_TO' => $to],
        ['EFFEC_FROM', 'EFFEC_TO'], $sessionEmp, false);
}

function joiningSaveAccess(array $employee, array $values, $id, $sessionEmp)
{
    $code = $employee['EMP_CODE'];
    $valid = array_column(joiningRecords("SELECT ID FROM EPPLIVE.BCS_APP"), 'ID');
    $apps = array_values(array_unique(array_map('strval', is_array($values['apps'] ?? null) ? $values['apps'] : [])));
    if (array_diff($apps, array_map('strval', $valid))) {
        throw new JoiningRequestException('Please select valid applications.', 422);
    }

    joiningExecute("DELETE FROM HR_BCS_APP_ACCESS WHERE EMP_CODE = :emp_code", [':emp_code' => $code], 'Could not update the application access.');
    foreach ($apps as $app) {
        joiningExecute("INSERT INTO HR_BCS_APP_ACCESS (EMP_CODE, APP_ID) VALUES (:emp_code, :app_id)",
            [':emp_code' => $code, ':app_id' => $app], 'Could not update the application access.');
    }

    if (in_array('2', $apps, true)) {
        joiningSyncSfmEmployee($code, $sessionEmp);
    }
    if (in_array('4', $apps, true)) {
        $profile = singRec("SELECT ID FROM HR_EPT_EMP_PROFILE WHERE EMP_CODE = :emp_code AND PROFILE_ID = 99 AND ROWNUM = 1", [':emp_code' => $code]);
        if (empty($profile['ID'])) {
            joiningExecute("INSERT INTO HR_EPT_EMP_PROFILE (EMP_CODE, PROFILE_ID, CHG_ON, CHG_BY)
                VALUES (:emp_code, 99, SYSDATE, :chg_by)", [':emp_code' => $code, ':chg_by' => $sessionEmp], 'Could not grant Employee Portal access.');
        }
    }
}

/** SFM access: create or refresh the SFM employee (legacy saveAccess). */
function joiningSyncSfmEmployee($code, $sessionEmp)
{
    $office = singRec("SELECT * FROM (SELECT ORG_ID, ORG_LOC_ID, DEPT_ID, GET_DESIGN_NAME(DESI_ID) AS DESIGNATION
            FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :emp_code ORDER BY EFFEC_FROM DESC)
        WHERE ROWNUM = 1", [':emp_code' => $code]);
    $parent = singRec("SELECT PARENT_ORGID, PARENT_LOCID FROM HR_ORG_LOC_PARENT
        WHERE ORG_ID = :org_id AND ORG_LOC_ID = :loc_id
          AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01') AND ROWNUM = 1",
        [':org_id' => (string)($office['ORG_ID'] ?? ''), ':loc_id' => (string)($office['ORG_LOC_ID'] ?? '')]);
    $manager = '';
    if (!empty($parent['PARENT_ORGID'])) {
        $row = singRec("SELECT S.ID FROM HR_EMP_OFFICE_DET D
            INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = D.EMP_CODE AND E.STATUS = 'A'
            INNER JOIN HR_SFM_EMPLOYEE_INFO S ON S.EMP_CODE = D.EMP_CODE
            WHERE D.ORG_ID = :org_id AND D.ORG_LOC_ID = :loc_id AND ROWNUM = 1",
            [':org_id' => (string)$parent['PARENT_ORGID'], ':loc_id' => (string)$parent['PARENT_LOCID']]);
        $manager = (string)($row['ID'] ?? '');
    }
    $binds = [':emp_code' => $code, ':designation' => (string)($office['DESIGNATION'] ?? ''),
        ':dept_id' => (string)($office['DEPT_ID'] ?? ''), ':manager' => $manager, ':chg_by' => $sessionEmp];

    $existing = singRec("SELECT ID FROM HR_SFM_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code AND ROWNUM = 1", [':emp_code' => $code]);
    if (!empty($existing['ID'])) {
        joiningExecute("UPDATE HR_SFM_EMPLOYEE_INFO S SET (FNAME, MNAME, LNAME, TITE, ADDRESS, CITY, STATE, PINCODE, COUNTRY,
                PHONE, CELL, PER_EMAIL, COM_EMAIL, DOB, DOJ, DOL) =
                (SELECT UPPER(FNAME), UPPER(MNAME), UPPER(LNAME), TITLE, ADDRESS, CITY, STATE, PINCODE, COUNTRY,
                    PHONE, CELL, LOWER(TRIM(PER_EMAIL)), LOWER(TRIM(COM_EMAIL)), DOB, DOJ, DOL
                 FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code),
                DESIGNATION = :designation, DEPT_ID = :dept_id, MANAGER_ID = :manager, STATUS = 'A',
                CHG_BY = :chg_by, CHG_ON = SYSDATE
            WHERE S.EMP_CODE = :emp_code", $binds, 'Could not update the SFM employee.');
        return;
    }
    joiningExecute("INSERT INTO HR_SFM_EMPLOYEE_INFO (EMP_CODE, FNAME, MNAME, LNAME, TITE, ADDRESS, CITY, STATE, PINCODE, COUNTRY,
            PHONE, CELL, PER_EMAIL, COM_EMAIL, DOB, DOJ, DOL, DESIGNATION, DEPT_ID, MANAGER_ID, STATUS, CHG_BY, CHG_ON)
        SELECT EMP_CODE, UPPER(FNAME), UPPER(MNAME), UPPER(LNAME), TITLE, ADDRESS, CITY, STATE, PINCODE, COUNTRY,
            PHONE, CELL, LOWER(TRIM(PER_EMAIL)), LOWER(TRIM(COM_EMAIL)), DOB, DOJ, DOL,
            :designation, :dept_id, :manager, 'A', :chg_by, SYSDATE
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", $binds, 'Could not create the SFM employee.');
    $created = singRec("SELECT ID FROM HR_SFM_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code AND ROWNUM = 1", [':emp_code' => $code]);
    joiningExecute("INSERT INTO HR_SFM_EMP_PROFILE (EMP_ID, PROFILE_ID, CHG_ON, CHG_BY)
        VALUES (:emp_id, 99, SYSDATE, :chg_by)", [':emp_id' => (string)$created['ID'], ':chg_by' => $sessionEmp],
        'Could not grant SFM access.');
}

/** Replaces the employee's joining CTC rows (legacy saveOtherheads). */
function joiningSaveCtc(array $employee, array $values, $id, $sessionEmp)
{
    $code = $employee['EMP_CODE'];
    $ctc = trim((string)($values['ctc'] ?? ''));
    if (!is_numeric($ctc) || (float)$ctc <= 0) {
        throw new JoiningRequestException('Please enter a valid CTC.', 422);
    }
    $heads = joiningCtcHeads($code);
    if (!$heads) {
        throw new JoiningRequestException('No CTC structure is set up for this employee\'s job description.', 422);
    }
    $period = joiningCtcPeriod($code);
    $monthly = is_array($values['monthly'] ?? null) ? $values['monthly'] : [];
    $variables = is_array($values['variables'] ?? null) ? $values['variables'] : [];

    joiningExecute("DELETE FROM HR_EMP_CTC_HEADS WHERE EMP_CODE = :emp_code AND SALARY_TYPE = 'J'",
        [':emp_code' => $code], 'Could not update the CTC.');

    foreach ($heads as $head) {
        $headCode = $head['AD_CODE'];
        if ($head['HEAD_KEY'] === 'V') {
            $amount = (string)($variables[$headCode] ?? '');
            if (!in_array($amount, ['0', '1'], true)) {
                throw new JoiningRequestException("{$head['DESCR']} must be 1 or 0.", 422);
            }
            $calcType = $amount === '1' ? 'F' : 'P';
            $yearly = '';
        } else {
            $amount = trim((string)($monthly[$headCode] ?? ''));
            if ($amount === '' || (float)$amount === 0.0) continue;
            if (!is_numeric($amount) || (float)$amount < 0) {
                throw new JoiningRequestException("{$head['DESCR']} must be a valid amount.", 422);
            }
            $calcType = $head['AD_TYPE'];
            $yearly = (string)((float)$amount * 12);
        }
        joiningExecute("INSERT INTO HR_EMP_CTC_HEADS (EMP_CODE, SALARY_TYPE, CTC, AD_CODE, AMOUNT_MNTH, AMOUNT_YRLY,
                STATUS, CALC_TYPE, EFF_FROM, EFF_TO, CHG_BY, CHG_ON)
            VALUES (:emp_code, 'J', :ctc, :ad_code, :amount, :yearly, 'N', :calc_type,
                TO_DATE(:eff_from, 'YYYY-MM-DD'), TO_DATE(:eff_to, 'YYYY-MM-DD'), :chg_by, SYSDATE)", [
            ':emp_code' => $code, ':ctc' => $ctc, ':ad_code' => $headCode, ':amount' => $amount, ':yearly' => $yearly,
            ':calc_type' => $calcType, ':eff_from' => $period['EFF_FROM'], ':eff_to' => $period['EFF_TO'], ':chg_by' => $sessionEmp,
        ], 'Could not save the CTC.');
    }
}
