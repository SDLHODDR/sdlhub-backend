<?php
/*
 * POST { user_task_id }
 * Deactivate Employee (23). Legacy separation_sv.php?DeactUser: ends the employee
 * in HRMS, EPP and SFM as of the relieving date, then creates the Relieving &
 * Experience Letter task.
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    [$task, $separation] = exitLoadTask(EXIT_TASK_DEACTIVATE, $input['user_task_id'] ?? 0, $empCode);
    $employeeCode = $separation['EMP_CODE'];
    $relievingDate = $separation['RELEIVING_DATE_ISO'];
    if (!$relievingDate) {
        throw new ExitRequestException('The separation has no approved relieve date.', 422);
    }
    $dateSql = "TO_DATE(:relieving_date, 'YYYY-MM-DD')";
    $base = [':relieving_date' => $relievingDate, ':emp_code' => $employeeCode, ':chg_by' => $empCode];

    // Resignation type / mode and handover can be corrected on this screen.
    $resignType = strtoupper(trim((string)($input['resign_type'] ?? '')));
    $resignMode = strtoupper(trim((string)($input['resign_mode'] ?? '')));
    $handoverTo = trim((string)($input['handover_to'] ?? ''));
    if (!in_array($resignType, ['A', 'T', 'R', 'W', 'C'], true)) {
        throw new ExitRequestException('Resignation type is required.', 422);
    }
    if (!in_array($resignMode, ['E', 'T', 'O'], true)) {
        throw new ExitRequestException('Resignation mode is required.', 422);
    }
    $handover = singRec("SELECT EMP_CODE FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code AND STATUS = 'A'", [':code' => $handoverTo]);
    if ($handoverTo === '' || empty($handover['EMP_CODE'])) {
        throw new ExitRequestException('Please select an active handover employee.', 422);
    }
    exitExecute("UPDATE HR_EMP_SEPARATION
        SET RESIGN_TYPE = :resign_type, RESIGN_MODE = :resign_mode, HANDOVER_TO = :handover_to
        WHERE ID = :id", [
        ':resign_type' => $resignType,
        ':resign_mode' => $resignMode,
        ':handover_to' => $handoverTo,
        ':id' => $separation['ID'],
    ], 'Could not update the separation details.');

    exitExecute("UPDATE HR_EMPLOYEE_INFO SET DOL = {$dateSql}, STATUS = 'R', CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code", $base, 'Could not update the employee record.');

    $office = singRec("SELECT * FROM (SELECT ID, ORG_ID FROM HR_EMP_OFFICE_DET
            WHERE EMP_CODE = :emp_code AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01'))
        WHERE ROWNUM = 1", [':emp_code' => $employeeCode]);
    if (!empty($office['ID'])) {
        exitExecute("UPDATE HR_EMP_OFFICE_DET SET EFFEC_TO = {$dateSql}, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':relieving_date' => $relievingDate, ':chg_by' => $empCode, ':id' => $office['ID']],
            'Could not close the employee office record.');
        exitExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = FILL_COUNT - 1, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':chg_by' => $empCode, ':id' => $office['ORG_ID']],
            'Could not update the organogram fill count.');
    }

    $tenure = singRec("SELECT * FROM (SELECT ID FROM HR_EMP_TENURE
            WHERE EMP_CODE = :emp_code AND SYSDATE BETWEEN EFF_FROM AND NVL(EFF_TO, DATE '3000-03-01'))
        WHERE ROWNUM = 1", [':emp_code' => $employeeCode]);
    if (!empty($tenure['ID'])) {
        exitExecute("UPDATE HR_EMP_TENURE SET EFF_TO = {$dateSql}, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':relieving_date' => $relievingDate, ':chg_by' => $empCode, ':id' => $tenure['ID']],
            'Could not close the employee tenure.');
    }

    // EPP login and employee master.
    exitExecute("UPDATE HR_BCS_USERS SET VALID_UPTO = {$dateSql}, STATUS = 'R', CHG_USER = :chg_by, CHG_DATE = SYSDATE
        WHERE EMP_CODE = :emp_code", $base, 'Could not deactivate the EPP user.');
    exitExecute("UPDATE HR_BCS_EMPLOYEE SET RELIEVE_DATE = {$dateSql}, STATUS = 'R', CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code", $base, 'Could not update the EPP employee record.');

    // Central login (SDLUSERS schema, same database, so it commits or rolls back with the rest).
    exitExecute("UPDATE SDLUSERS.SDL_USERS SET STATUS = 'R', VALID_UPTO = {$dateSql}, CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code", $base, 'Could not deactivate the SDL login.');

    // SFM territory, divisions and employee master.
    $sfmEmployee = "SELECT ID FROM HR_SFM_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code";
    $sfmBinds = [':relieving_date' => $relievingDate, ':emp_code' => $employeeCode];
    exitExecute("UPDATE HR_SFM_EMP_GEO SET EFFEC_TO = {$dateSql}
        WHERE EMP_ID IN ({$sfmEmployee}) AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01')",
        $sfmBinds, 'Could not close the SFM territory mapping.');
    exitExecute("UPDATE HR_SFM_EMP_DIVISIONS SET EFFEC_TO = {$dateSql}
        WHERE EMP_ID IN ({$sfmEmployee}) AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01')",
        $sfmBinds, 'Could not close the SFM division mapping.');
    exitExecute("UPDATE HR_SFM_EMPLOYEE_INFO SET DOL = {$dateSql}, STATUS = 'R', CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE EMP_CODE = :emp_code", $base, 'Could not update the SFM employee record.');

    $org = exitEmployeeOffice($employeeCode, $relievingDate);
    exitGenerateTask('REL_EXP', $separation,
        'Releiving & Exp Letter  :  ' . getEmpInfoByCodeHR($employeeCode) . ' - ' . $separation['RELEIVING_DATE'],
        $empCode, $org['COMPANY'] ?? '', $org['ORG_DIVSN_ID'] ?? '', EXIT_HR_DEPT_ID);
    exitCloseTask($task['ID']);

    return ['Employee deactivated. Relieving & Experience Letter task generated.', null];
}, 'deactivateExitEmployee.php');
