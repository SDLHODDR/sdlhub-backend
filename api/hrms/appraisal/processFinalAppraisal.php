<?php
/* POST { action: save|submit, id, employees: [{code, promotion Y|N, increment, increment_from, promotion_from, remarks}] }
 *   save   -> final recommendation on the latest approved review and HR_APPR_ORG_EMP (NEW_CTC = current + 12 x increment)
 *   submit -> save, then employee 'A', close the appraisal task and raise CTC Change (47) / Promotion (49). */
require_once __DIR__ . '/finalAppraisalCommon.php';

appraisalRunAction(static function () use ($empCode) {
    $input = appraisalInput();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new AppraisalRequestException('Invalid action.', 422);
    }
    $appraisal = appraisalLoad($input['id'] ?? 0);
    $id = (string)$appraisal['ID'];
    $employees = finalAppraisalEmployees($id);
    $minMonth = finalAppraisalMinMonth($appraisal);
    $selected = array_filter((array)($input['employees'] ?? []), 'is_array');
    if (!$selected) {
        throw new AppraisalRequestException('Please select one or more employees!', 422);
    }
    $names = appraisalEmployeeNames(array_keys($employees));

    $monthStart = static function ($value, $label, $name) use ($minMonth) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$value));
        if (!$date) {
            throw new AppraisalRequestException("Please select the {$label} for {$name}.", 422);
        }
        $date = $date->modify('first day of this month');
        if ($minMonth && $date < $minMonth) {
            throw new AppraisalRequestException("The {$label} for {$name} cannot be before " . $minMonth->format('M-Y') . '.', 422);
        }
        return $date->format('Y-m-d');
    };

    $missingRemarks = [];
    $plans = [];
    foreach ($selected as $entry) {
        $code = trim((string)($entry['code'] ?? ''));
        $row = $employees[$code] ?? null;
        if (!$row) {
            throw new AppraisalRequestException("Employee {$code} has no approved appraisal.", 422);
        }
        $name = $names[$code] ?? $code;
        if ($row['ORG_STATUS'] !== 'I') {
            throw new AppraisalRequestException("The final appraisal of {$name} has already been submitted.", 409);
        }
        $promotion = strtoupper(trim((string)($entry['promotion'] ?? '')));
        if (!in_array($promotion, ['Y', 'N'], true)) {
            throw new AppraisalRequestException("Please select the promotion for {$name}.", 422);
        }
        $increment = trim((string)($entry['increment'] ?? ''));
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $increment) || (float)$increment > FINAL_APPRAISAL_INCREMENT_MAX) {
            throw new AppraisalRequestException("Please enter a valid monthly increment for {$name}.", 422);
        }
        $remarks = trim((string)($entry['remarks'] ?? ''));
        if (strlen($remarks) > FINAL_APPRAISAL_REMARK_MAX) {
            throw new AppraisalRequestException("The remarks for {$name} are too long.", 422);
        }
        if ($promotion === 'Y' && $remarks === '') {
            $missingRemarks[] = $name;
        }
        $plans[$code] = [
            'row' => $row, 'name' => $name, 'promotion' => $promotion, 'increment' => $increment, 'remarks' => $remarks,
            'incrementFrom' => $monthStart($entry['increment_from'] ?? '', 'increment effective from', $name),
            'promotionFrom' => $monthStart($entry['promotion_from'] ?? '', 'promotion effective from', $name),
        ];
    }
    if ($missingRemarks) {
        throw new AppraisalRequestException('Promotion remarks are required for: ' . implode(', ', $missingRemarks), 422);
    }

    foreach ($plans as $code => $plan) {
        appraisalExecute("UPDATE HR_APPR_EMP_MGR SET PROP_CTC = :incr, RECO_PROMO = :promo, APPR_REMARKS = :remarks,
                CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
            [':incr' => $plan['increment'], ':promo' => $plan['promotion'], ':remarks' => $plan['remarks'], ':chg_by' => $empCode,
                ':id' => (string)$plan['row']['MGR_ID']], 'Could not save the final recommendation.');
        $newCtc = (float)($plan['row']['CURR_CTC'] ?? 0) + (float)$plan['increment'] * 12;
        appraisalExecute("UPDATE HR_APPR_ORG_EMP SET NEW_CTC = :ctc, INCR_EFFEC_FROM = TO_DATE(:incr_from, 'YYYY-MM-DD'),
                PROMO_EFFEC_FROM = TO_DATE(:promo_from, 'YYYY-MM-DD'), CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE ID = :id AND STATUS = 'I'",
            [':ctc' => (string)round($newCtc, 2), ':incr_from' => $plan['incrementFrom'], ':promo_from' => $plan['promotionFrom'],
                ':chg_by' => $empCode, ':id' => (string)$plan['row']['ORG_EMP_ID']], 'Could not save the final recommendation.');
    }
    if ($action === 'save') {
        return ['Updated Successfully!', null];
    }

    foreach ($plans as $code => $plan) {
        $row = $plan['row'];
        $unit = appraisalRow("SELECT O.COMPANY, O.DIVSN_ID, O.DEPT_ID FROM HR_EMP_OFFICE_DET D
            INNER JOIN HR_ORGANOGRAM O ON O.ID = D.ORG_ID
            WHERE D.EMP_CODE = :code AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
            ORDER BY D.ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => (string)$code]);
        $done = appraisalExecute("UPDATE HR_APPR_ORG_EMP SET STATUS = 'A', CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id AND STATUS = 'I'",
            [':chg_by' => $empCode, ':id' => (string)$row['ORG_EMP_ID']], 'Could not submit the final appraisal.');
        if ($done !== 1) {
            throw new AppraisalRequestException("The final appraisal of {$plan['name']} has already been submitted.", 409);
        }
        if (!empty($row['TASK_ID'])) {
            appraisalExecute("UPDATE HR_USER_TASKS SET STATUS = 'C', AUTH_ON = SYSDATE, AUTH_BY = :auth_by WHERE ID = :id AND STATUS = 'O'",
                [':auth_by' => $empCode, ':id' => (string)$row['TASK_ID']], 'Could not close the appraisal task.');
        }
        // As legacy: unassigned tasks in the employee's unit, created in the approving appraiser's name.
        if ($plan['promotion'] === 'Y') {
            generateTaskHR('APPR_EMP_PROMO', $code, 'Appraisal Promotion Change for ' . $plan['name'], '', 'A', 'O', null,
                $row['EMP_CODE_APPR'], $unit['COMPANY'] ?? '', $unit['DIVSN_ID'] ?? '', $unit['DEPT_ID'] ?? '');
        }
        generateTaskHR('APPR_CHG_LTR', $code, 'Appraisal CTC Change for ' . $plan['name'], '', 'A', 'O', null,
            $row['EMP_CODE_APPR'], $unit['COMPANY'] ?? '', $unit['DIVSN_ID'] ?? '', $unit['DEPT_ID'] ?? '');
    }
    return ['Task Generated Sucessfully for further Process!', null];
}, 'processFinalAppraisal.php');
