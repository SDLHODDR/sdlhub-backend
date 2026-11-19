<?php
/*
 * POST { user_task_id, action, org_id, org_loc_id } - legacy emp_promotion_sv.php.
 *   Employee Promotion (49):  save -> new organogram / location;  send -> Promotion Auth task (50)
 *   Promotion Auth (50):      save -> same;  authorize -> the employee moves to the new position from the
 *                             promotion date (office row), the appraisal closes (C), fill counts move,
 *                             and the Print Promotion Letter task (51) is generated.
 */
require_once __DIR__ . '/appraisalTaskCommon.php';

$input = appraisalInput();

employeeAppraisalRunAction(function () use ($input, $empCode) {
    $task = appraisalLoadFollowUpTask($input['user_task_id'] ?? 0, $empCode, [49, 50]);
    $taskType = (int)$task['TASK_ID'];
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, $taskType === 49 ? ['save', 'send'] : ['save', 'authorize'], true)) {
        throw new AppraisalRequestException('Invalid action.', 422);
    }
    $appr = $task['APPR'];
    if ($appr['STATUS'] !== 'A') {
        throw new AppraisalRequestException('This promotion has already been authorized.', 409);
    }
    $code = (string)$task['TRAN_CODE'];
    $date = $appr['PROMO_FROM'];
    if (!$date) {
        throw new AppraisalRequestException('The promotion effective date is not set in the final appraisal.', 422);
    }

    $orgId = (string)(int)($input['org_id'] ?? 0);
    $locId = (string)(int)($input['org_loc_id'] ?? 0);
    if ($orgId === '0' || $locId === '0') {
        throw new AppraisalRequestException('Please select the new organogram and its location.', 422);
    }
    $org = appraisalRow("SELECT ID, COMPANY, DIVSN_ID, DEPT_ID, DESI_ID, NVL(POSI_COUNT, 0) AS POSI, NVL(FILL_COUNT, 0) AS FILLED
        FROM HR_ORGANOGRAM WHERE ID = :id", [':id' => $orgId]);
    if (empty($org['ID'])) {
        throw new AppraisalRequestException('Organogram not found.', 404);
    }
    $free = appraisalRow("SELECT COUNT(*) AS N FROM HR_ORGANOGRAM_LOC HOL
        WHERE HOL.ID = :loc AND HOL.ORG_ID = :org
          AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
          AND NOT EXISTS (SELECT 1 FROM HR_EMP_OFFICE_DET D WHERE D.ORG_LOC_ID = HOL.ID AND D.EMP_CODE <> :code
                AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01'))",
        [':loc' => $locId, ':org' => $orgId, ':dt' => $date, ':code' => $code]);
    if ((int)($free['N'] ?? 0) === 0) {
        throw new AppraisalRequestException('This location is not free on the promotion date. Please select another.', 409);
    }

    appraisalExecute("UPDATE HR_APPR_ORG_EMP SET NEW_ORG_ID = :org, NEW_ORG_LOC_ID = :loc, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE ID = :id AND STATUS = 'A'", [':org' => $orgId, ':loc' => $locId, ':chg_by' => $empCode, ':id' => (string)$appr['ID']],
        'Could not save the promotion.');

    if ($action === 'save') {
        return ['Updated Successfully!', null];
    }
    $names = appraisalEmployeeNames([$code]);
    $name = $names[$code] ?? $code;

    if ($taskType === 49) {
        taskUpdateHR('C', '', $task['ID'], null, null);
        appraisalRaiseFollowUp('APPR_EMP_PROMO_AUTH', $code, 'Appraisal Promotion Auth for ' . $name, $empCode);
        return ['Promotion sent for authorization.', null];
    }

    // Authorize: the current office row ends the day before the promotion; the new one starts on it.
    $office = appraisalRow("SELECT ID, EMP_ID, ORG_ID FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :code ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':code' => $code]);
    if (empty($office['ID'])) {
        throw new AppraisalRequestException('The employee has no office details.', 422);
    }
    appraisalExecute("UPDATE HR_EMP_OFFICE_DET SET EFFEC_TO = TO_DATE(:dt, 'YYYY-MM-DD') - 1, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
        [':dt' => $date, ':chg_by' => $empCode, ':id' => (string)$office['ID']], 'Could not close the current office details.');
    appraisalExecute("INSERT INTO HR_EMP_OFFICE_DET (EMP_ID, EMP_CODE, DIVSN_ID, DEPT_ID, DESI_ID, ORG_ID, ORG_LOC_ID, EFFEC_FROM, CHG_ON, CHG_BY)
        VALUES (:emp_id, :code, :divsn, :dept, :desi, :org, :loc, TO_DATE(:dt, 'YYYY-MM-DD'), SYSDATE, :chg_by)", [
        ':emp_id' => (string)$office['EMP_ID'], ':code' => $code, ':divsn' => (string)$org['DIVSN_ID'], ':dept' => (string)$org['DEPT_ID'],
        ':desi' => (string)$org['DESI_ID'], ':org' => $orgId, ':loc' => $locId, ':dt' => $date, ':chg_by' => $empCode,
    ], 'Could not save the new office details.');
    appraisalExecute("UPDATE HR_APPR_ORG_EMP SET STATUS = 'C', CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id AND STATUS = 'A'",
        [':chg_by' => $empCode, ':id' => (string)$appr['ID']], 'Could not close the appraisal.');
    if ((string)$office['ORG_ID'] !== $orgId) {
        // The position moves: legacy only added to the new organogram; the old one is freed too.
        appraisalExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = NVL(FILL_COUNT, 0) + 1, CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':chg_by' => $empCode, ':id' => $orgId], 'Could not update the organogram fill count.');
        appraisalExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = GREATEST(NVL(FILL_COUNT, 0) - 1, 0), CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':chg_by' => $empCode, ':id' => (string)$office['ORG_ID']], 'Could not update the organogram fill count.');
    }
    // EPP employee follows the new position.
    $deptCode = appraisalRow("SELECT DEPT_CODE FROM HR_DEPARTMENT WHERE DEPT_ID = :id", [':id' => (string)$org['DEPT_ID']]);
    appraisalExecute("UPDATE HR_BCS_EMPLOYEE SET DIVISION = :divsn, DEPT_ID = :dept, DEPT_CODE = :dept_code, DESIGNATION = :desi,
            ORG_ID = :org, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE EMP_CODE = :code", [
        ':divsn' => (string)$org['DIVSN_ID'], ':dept' => (string)$org['DEPT_ID'], ':dept_code' => (string)($deptCode['DEPT_CODE'] ?? ''),
        ':desi' => (string)$org['DESI_ID'], ':org' => $orgId, ':chg_by' => $empCode, ':code' => $code,
    ], 'Could not update the EPP employee record.');

    taskUpdateHR('C', '', $task['ID'], null, null);
    appraisalRaiseFollowUp('PRT_PROMO_LTR', $code, 'Print Promotion Letter for ' . $name, $empCode);
    return ['Promotion authorized. A Print Promotion Letter task has been generated.', null];
}, 'saveAppraisalPromotion.php');
