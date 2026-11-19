<?php
/*
 * POST { user_task_id, action, pf_basis: 0|1, amounts: { adCode: monthly } }
 *   CTC Change (47):   save   -> heads saved (N);  submit -> sent for approval (T), CTC Approval task (48)
 *   CTC Approval (48): save   -> amounts updated;  authorize -> approved (A) and written to payroll from the
 *                      increment date (CONFIRMED T, with the EPP confirmation task 70, as legacy authAppraisalCTC);
 *                      an employee not promoted then gets the Print Increment Letter task (52).
 */
require_once __DIR__ . '/appraisalCtcCommon.php';

$input = appraisalInput();

employeeAppraisalRunAction(function () use ($input, $empCode) {
    $task = appraisalLoadFollowUpTask($input['user_task_id'] ?? 0, $empCode, [47, 48]);
    $taskType = (int)$task['TASK_ID'];
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $allowed = $taskType === 47 ? ['save', 'submit'] : ['save', 'authorize'];
    if (!in_array($action, $allowed, true)) {
        throw new AppraisalRequestException('Invalid action.', 422);
    }
    $appr = $task['APPR'];
    $code = (string)$task['TRAN_CODE'];
    $refId = (string)$appr['ID'];
    if (empty($appr['INCR_FROM'])) {
        throw new AppraisalRequestException('The increment effective date is not set in the final appraisal.', 422);
    }
    $effFrom = $appr['INCR_FROM'];
    $effTo = appraisalPeriodEnd($effFrom);

    $heads = appraisalCtcHeads(appraisalCtcOrg($appr));
    if (!$heads) {
        throw new AppraisalRequestException('No salary heads are set for the JD of this position.', 422);
    }
    $pfBasis = (string)($input['pf_basis'] ?? '0') === '1' ? '1' : '0';
    $amounts = (array)($input['amounts'] ?? []);
    $rows = [];
    foreach ($heads as $head) {
        $adCode = (string)$head['AD_CODE'];
        if ($adCode === 'XPFBS') {
            $rows[$adCode] = ['monthly' => (float)$pfBasis, 'calc' => 'V'];
            continue;
        }
        if (strtoupper((string)$head['CALC_KEY']) === 'V') {
            continue;
        }
        $raw = trim((string)($amounts[$adCode] ?? ''));
        if ($raw === '') {
            $raw = '0';
        }
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
            throw new AppraisalRequestException("Please enter a valid monthly amount for {$head['SH_DESCR']}.", 422);
        }
        $rows[$adCode] = ['monthly' => (float)$raw, 'calc' => $head['AD_TYPE'] === 'D' ? 'P' : 'F'];
    }
    $annualCtc = 0.0;
    foreach ($rows as $adCode => $row) {
        if ($adCode !== 'XPFBS') {
            $annualCtc += $row['monthly'] * 12;
        }
    }
    if ($action !== 'save' && $annualCtc <= 0) {
        throw new AppraisalRequestException('Please enter the salary breakup before submitting.', 422);
    }

    $saved = appraisalCtcSaved($refId, $code);
    $status = $taskType === 47 ? 'N' : 'T';
    foreach ($rows as $adCode => $row) {
        $binds = [
            ':monthly' => (string)$row['monthly'],
            ':yearly' => $adCode === 'XPFBS' ? '' : (string)round($row['monthly'] * 12, 2),
            ':ctc' => (string)round($annualCtc, 2),
            ':calc' => $row['calc'],
            ':eff_from' => $effFrom,
            ':eff_to' => $effTo,
            ':chg_by' => $empCode,
            ':ref' => $refId,
            ':code' => $code,
            ':ad' => $adCode,
        ];
        if (isset($saved[$adCode])) {
            appraisalExecute("UPDATE HR_EMP_CTC_HEADS SET AMOUNT_MNTH = :monthly, AMOUNT_YRLY = :yearly, CTC = :ctc, CALC_TYPE = :calc,
                    EFF_FROM = TO_DATE(:eff_from, 'YYYY-MM-DD'), EFF_TO = TO_DATE(:eff_to, 'YYYY-MM-DD'), CHG_ON = SYSDATE, CHG_BY = :chg_by
                WHERE REF_ID = :ref AND EMP_CODE = :code AND AD_CODE = :ad AND SALARY_TYPE = 'A'", $binds, 'Could not save the CTC.');
        } else {
            appraisalExecute("INSERT INTO HR_EMP_CTC_HEADS (EMP_CODE, SALARY_TYPE, REF_ID, CTC, AD_CODE, AMOUNT_MNTH, AMOUNT_YRLY,
                    STATUS, CALC_TYPE, EFF_FROM, EFF_TO, CHG_ON, CHG_BY)
                VALUES (:code, 'A', :ref, :ctc, :ad, :monthly, :yearly, :status, :calc,
                    TO_DATE(:eff_from, 'YYYY-MM-DD'), TO_DATE(:eff_to, 'YYYY-MM-DD'), SYSDATE, :chg_by)",
                $binds + [':status' => $status], 'Could not save the CTC.');
        }
    }
    // Heads no longer in the JD are dropped.
    foreach (array_diff(array_keys($saved), array_keys($rows)) as $stale) {
        appraisalExecute("DELETE FROM HR_EMP_CTC_HEADS WHERE REF_ID = :ref AND EMP_CODE = :code AND AD_CODE = :ad AND SALARY_TYPE = 'A'",
            [':ref' => $refId, ':code' => $code, ':ad' => $stale], 'Could not save the CTC.');
    }

    if ($action === 'save') {
        return ['CTC saved!', null];
    }

    $names = appraisalEmployeeNames([$code]);
    $name = $names[$code] ?? $code;
    $scope = [':ref' => $refId, ':code' => $code];

    if ($taskType === 47) {
        appraisalExecute("UPDATE HR_EMP_CTC_HEADS SET STATUS = 'T', CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE REF_ID = :ref AND EMP_CODE = :code AND SALARY_TYPE = 'A'", $scope + [':chg_by' => $empCode], 'Could not submit the CTC.');
        appraisalRaiseFollowUp('AUTH_APPR_CHG_LTR', $code, 'Appraisal CTC Approval for ' . $name, $empCode);
        taskUpdateHR('C', '', $task['ID'], null, null);
        return ['CTC sent for approval. A CTC Approval task has been generated.', null];
    }

    // CTC Approval (48): approve and write the new salary to payroll from the increment date.
    appraisalExecute("UPDATE HR_EMP_CTC_HEADS SET STATUS = 'A', AUTH_BY = :chg_by, AUTH_ON = SYSDATE
        WHERE REF_ID = :ref AND EMP_CODE = :code AND SALARY_TYPE = 'A'", $scope + [':chg_by' => $empCode], 'Could not approve the CTC.');

    $jdValues = [];
    foreach ($heads as $head) {
        $jdValues[$head['AD_CODE']] = (float)$head['CALC_VAL'];
    }
    $adTypes = array_column($heads, 'AD_TYPE', 'AD_CODE');
    $payroll = [':code' => $code, ':eff_from' => $effFrom, ':eff_to' => $effTo, ':chg_by' => $empCode];
    // Rows running to the period end stop the day before; a re-run replaces rows from the same date.
    appraisalExecute("DELETE FROM HR_BCS_EMPLOYEE_AD WHERE EMP_CODE = :code AND EFF_DATE = TO_DATE(:eff_from, 'YYYY-MM-DD')",
        [':code' => $code, ':eff_from' => $effFrom], 'Could not update the payroll heads.');
    appraisalExecute("UPDATE HR_BCS_EMPLOYEE_AD SET EXP_DATE = TO_DATE(:eff_from, 'YYYY-MM-DD') - 1, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE EMP_CODE = :code AND EXP_DATE = TO_DATE(:eff_to, 'YYYY-MM-DD') AND EFF_DATE < TO_DATE(:eff_from, 'YYYY-MM-DD')",
        $payroll, 'Could not close the current payroll heads.');
    $basic = (float)($rows['MBAS']['monthly'] ?? 0);
    foreach ($rows as $adCode => $row) {
        $adType = $adTypes[$adCode] ?? '';
        $type = $adType === 'D' ? 'P' : 'F';
        $amount = $row['monthly'];
        if ($adCode === 'CPF') {
            [$type, $amount] = $pfBasis === '1' ? ['F', 15000] : ['P', 12];
        } elseif (in_array($adCode, ['CBON', 'CGRA', 'MTDS'], true)) {
            [$type, $amount] = ['P', round($jdValues[$adCode] ?? 0, 2)];
        } elseif ($adCode === 'MPFC') {
            [$type, $amount] = ['F', $jdValues[$adCode] ?? 0];
        } elseif ($adCode === 'CESI') {
            [$type, $amount] = ['P', $basic > 0 ? round($row['monthly'] * 100 / $basic, 2) : 0];
        }
        if ($adType !== 'D' && $adType !== 'V' && (float)$amount == 0) {
            continue;
        }
        appraisalExecute("INSERT INTO HR_BCS_EMPLOYEE_AD (EMP_CODE, AD_CODE, EFF_DATE, EXP_DATE, TYPE, AMOUNT, CONFIRMED, CHG_ON, CHG_BY)
            VALUES (:code, :ad, TO_DATE(:eff_from, 'YYYY-MM-DD'), TO_DATE(:eff_to, 'YYYY-MM-DD'), :type, :amount, 'T', SYSDATE, :chg_by)",
            $payroll + [':ad' => $adCode, ':type' => $type, ':amount' => (string)$amount], "Could not write payroll head {$adCode}.");
    }

    // EPP task for payroll to confirm the new salary (legacy task 70, UDF_1 = 47).
    $site = appraisalRow("SELECT WORK_SITE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :code", [':code' => $empCode]);
    appraisalExecute("INSERT INTO HR_BCS_USER_TASKS (TASK_ID, CREATED_ON, EXPIRE_ON, STATUS, TRAN_CODE, TASK_TYPE, TRAN_DESC, SITE_CODE, UDF_1, CREATED_BY)
        VALUES (70, SYSDATE, SYSDATE + 2, 'O', :code, 'A', :descr, :site, 47, :chg_by)",
        [':code' => $code, ':descr' => substr($code . ' - ' . $name, 0, 200), ':site' => (string)($site['WORK_SITE'] ?? ''), ':chg_by' => $empCode],
        'Could not create the payroll confirmation task.');

    taskUpdateHR('C', '', $task['ID'], null, null);
    $message = 'CTC approved and sent to payroll for confirmation.';
    if (($task['MANAGER']['RECO_PROMO'] ?? '') !== 'Y') {
        appraisalRaiseFollowUp('PRT_INCR_LTR', $code, 'Print Increment Letter for ' . $name, $empCode);
        $message .= ' A Print Increment Letter task has been generated.';
    }
    return [$message, null];
}, 'saveAppraisalCtc.php');
