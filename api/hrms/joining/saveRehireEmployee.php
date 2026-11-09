<?php
/*
 * POST { emp_code, etype_id, etype_period, eff_from, eff_to, divsn_id, dept_id, desi_id, org_id, org_loc_id }
 * Rehire a relieved employee (legacy employee_info_sv.php?rehireEmp). Beyond the
 * legacy page it also records the new office and restores the login:
 *  - Old tenure and office rows still open past the leaving date are closed, so
 *    the employee does not end up with two current rows.
 *  - A new tenure and office start on the rehire date. The date of joining stays.
 *  - HRMS, EPP and SFM employee masters become active; logins are restored or,
 *    for employees who never had one, created with the default password.
 *  - SFM territory and division mappings stay closed.
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
    if ($employee['STATUS'] !== 'R') {
        throw new JoiningRequestException('This employee is not relieved.', 409);
    }
    $code = $employee['EMP_CODE'];
    $info = joiningRecord("SELECT TO_CHAR(DOL, 'YYYY-MM-DD') AS DOL_ISO, CELL FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code",
        [':emp_code' => $code]);
    $dol = $info['DOL_ISO'] ?? '';

    $type = singRec("SELECT ETYPE_ID FROM HR_EMP_TYPE WHERE ETYPE_ID = :id", [':id' => (string)(int)($input['etype_id'] ?? 0)]);
    if (empty($type['ETYPE_ID'])) {
        throw new JoiningRequestException('Employee type is required.', 422);
    }
    $period = trim((string)($input['etype_period'] ?? ''));
    if ($period !== '' && !ctype_digit($period)) {
        throw new JoiningRequestException('Period must be a whole number of months.', 422);
    }
    $from = joiningIsoDate($input['eff_from'] ?? '', 'Effective from');
    $to = joiningIsoDate($input['eff_to'] ?? '', 'Effective to', false);
    if ($to !== '' && $to < $from) {
        throw new JoiningRequestException('Effective to cannot be before effective from.', 422);
    }
    if ($dol !== '' && $from <= $dol) {
        throw new JoiningRequestException('Effective from must be after the leaving date.', 422);
    }
    $office = joiningValidateOffice($input, $employee['COMP_ID'], $code);

    // Old rows end on the leaving date, or the day before the rehire when none was recorded.
    $closeOn = $dol !== '' ? $dol : (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
    $closeBinds = [':close_on' => $closeOn, ':emp_code' => $code, ':chg_by' => $empCode];
    joiningExecute("UPDATE HR_EMP_TENURE SET EFF_TO = TO_DATE(:close_on, 'YYYY-MM-DD'), CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code AND EFF_FROM <= TO_DATE(:close_on, 'YYYY-MM-DD')
          AND NVL(EFF_TO, DATE '3000-03-01') > TO_DATE(:close_on, 'YYYY-MM-DD')",
        $closeBinds, 'Could not close the previous tenure.');

    // Deactivation frees the position only for the row it closes, so free it here for these.
    $openOffices = joiningRecords("SELECT ID, ORG_ID FROM HR_EMP_OFFICE_DET
        WHERE EMP_CODE = :emp_code AND EFFEC_FROM <= TO_DATE(:close_on, 'YYYY-MM-DD')
          AND NVL(EFFEC_TO, DATE '3000-03-01') > TO_DATE(:close_on, 'YYYY-MM-DD')",
        [':emp_code' => $code, ':close_on' => $closeOn]);
    foreach ($openOffices as $open) {
        joiningExecute("UPDATE HR_EMP_OFFICE_DET SET EFFEC_TO = TO_DATE(:close_on, 'YYYY-MM-DD'), CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':close_on' => $closeOn, ':chg_by' => $empCode, ':id' => $open['ID']], 'Could not close the previous office record.');
        if (!empty($open['ORG_ID'])) {
            joiningExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = GREATEST(NVL(FILL_COUNT, 0) - 1, 0), CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
                [':chg_by' => $empCode, ':id' => $open['ORG_ID']], 'Could not update the organogram fill count.');
        }
    }

    // Anything still reaching the rehire date starts after the leaving date: a real clash.
    $clash = singRec("SELECT (SELECT COUNT(*) FROM HR_EMP_TENURE WHERE EMP_CODE = :emp_code
                AND NVL(EFF_TO, DATE '3000-03-01') >= TO_DATE(:eff_from, 'YYYY-MM-DD'))
            + (SELECT COUNT(*) FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :emp_code
                AND NVL(EFFEC_TO, DATE '3000-03-01') >= TO_DATE(:eff_from, 'YYYY-MM-DD')) AS CNT
        FROM DUAL", [':emp_code' => $code, ':eff_from' => $from]);
    if ((int)($clash['CNT'] ?? 0) > 0) {
        throw new JoiningRequestException('Effective from must be after the employee\'s existing tenure and office dates.', 422);
    }

    // One statement, so two people cannot both take the last free position.
    $filled = joiningExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = NVL(FILL_COUNT, 0) + 1, CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE ID = :id AND NVL(FILL_COUNT, 0) < NVL(POSI_COUNT, 0)",
        [':chg_by' => $empCode, ':id' => $office['org_id']], 'Could not update the organogram fill count.');
    if ($filled !== 1) {
        throw new JoiningRequestException('Position is already occupied!', 409);
    }

    joiningExecute("INSERT INTO HR_EMP_TENURE (EMP_ID, EMP_CODE, ETYPE_ID, ETYPE_PERIOD, EFF_FROM, EFF_TO, CHG_ON, CHG_BY)
        VALUES (:emp_id, :emp_code, :etype_id, :etype_period, TO_DATE(:eff_from, 'YYYY-MM-DD'), TO_DATE(:eff_to, 'YYYY-MM-DD'), SYSDATE, :chg_by)", [
        ':emp_id' => $employee['ID'],
        ':emp_code' => $code,
        ':etype_id' => $type['ETYPE_ID'],
        ':etype_period' => $period,
        ':eff_from' => $from,
        ':eff_to' => $to,
        ':chg_by' => $empCode,
    ], 'Could not save the tenure.');
    joiningExecute("INSERT INTO HR_EMP_OFFICE_DET (EMP_ID, EMP_CODE, DIVSN_ID, DEPT_ID, DESI_ID, ORG_ID, ORG_LOC_ID, EFFEC_FROM, CHG_ON, CHG_BY)
        VALUES (:emp_id, :emp_code, :divsn_id, :dept_id, :desi_id, :org_id, :org_loc_id, TO_DATE(:eff_from, 'YYYY-MM-DD'), SYSDATE, :chg_by)", [
        ':emp_id' => $employee['ID'],
        ':emp_code' => $code,
        ':divsn_id' => $office['divsn_id'],
        ':dept_id' => $office['dept_id'],
        ':desi_id' => $office['desi_id'],
        ':org_id' => $office['org_id'],
        ':org_loc_id' => $office['org_loc_id'],
        ':eff_from' => $from,
        ':chg_by' => $empCode,
    ], 'Could not save the office details.');

    $base = [':emp_code' => $code, ':chg_by' => $empCode];
    joiningExecute("UPDATE HR_EMPLOYEE_INFO SET STATUS = 'A', DOL = NULL, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        $base, 'Could not update the employee record.');
    joiningExecute("UPDATE HR_BCS_EMPLOYEE SET EMP_TYPE = :emp_type, STATUS = 'A', RELIEVE_DATE = NULL, CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code", $base + [':emp_type' => $type['ETYPE_ID']], 'Could not update the EPP employee record.');
    joiningUpdateEppOffice($code, $office, $empCode);
    joiningExecute("UPDATE HR_SFM_EMPLOYEE_INFO SET STATUS = 'A', DOL = NULL, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        $base, 'Could not update the SFM employee record.');

    $hadLogin = singRec("SELECT COUNT(*) AS CNT FROM SDLUSERS.SDL_USERS WHERE EMP_CODE = :emp_code", [':emp_code' => $code]);
    joiningEnsureLogins($code, joiningEmployeeName($employee), $info['CELL'] ?? '', 'A', $empCode);
    joiningExecute("UPDATE HR_BCS_USERS SET STATUS = 'A', VALID_UPTO = NULL, CHG_USER = :chg_by, CHG_DATE = SYSDATE WHERE EMP_CODE = :emp_code",
        $base, 'Could not activate the EPP login.');
    joiningExecute("UPDATE SDLUSERS.SDL_USERS SET STATUS = 'A', VALID_UPTO = NULL, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE EMP_CODE = :emp_code",
        $base, 'Could not activate the SDL login.');

    $message = (int)($hadLogin['CNT'] ?? 0) > 0
        ? 'Employee rehired.'
        : "Employee rehired. A new login was created with the default password P{$code}.";
    return [$message, null];
}, 'saveRehireEmployee.php');
