<?php
/* GET ?user_task_id=[&pf_basis=0|1] - Appraisal CTC Change (47) / CTC Approval (48): current and proposed structure and the saved heads. */
require_once __DIR__ . '/appraisalCtcCommon.php';

employeeAppraisalRunRead(function () use ($empCode) {
    $task = appraisalLoadFollowUpTask($_GET['user_task_id'] ?? 0, $empCode, [47, 48]);
    $appr = $task['APPR'];
    $code = (string)$task['TRAN_CODE'];
    $employee = appraisalFollowUpEmployee($code);

    $heads = appraisalCtcHeads(appraisalCtcOrg($appr));
    $saved = appraisalCtcSaved($appr['ID'], $code);
    // ?pf_basis= recalculates the structure for another PF basis; otherwise the saved one.
    $pfBasis = isset($_GET['pf_basis']) ? ((string)$_GET['pf_basis'] === '1' ? '1' : '0')
        : (isset($saved['XPFBS']) ? (string)(int)$saved['XPFBS']['AMOUNT_MNTH'] : '0');
    $proposed = appraisalCtcStructure($heads, (float)$appr['NEW_CTC'], $pfBasis);
    // No current salary (e.g. a new joiner not yet in payroll): nothing to break up.
    $current = (float)$appr['CURR_CTC'] > 0
        ? appraisalCtcStructure(appraisalCtcHeads($appr['ORG_ID']), (float)$appr['CURR_CTC'], $pfBasis) : [];
    $isPromotion = ($task['MANAGER']['RECO_PROMO'] ?? '') === 'Y';
    $newOrg = $isPromotion && $appr['NEW_ORG_ID'] ? appraisalRow("SELECT GET_ORG_NAME(:id) AS NM FROM DUAL", [':id' => $appr['NEW_ORG_ID']]) : [];

    return ['CTC fetched successfully.', [
        'taskId' => (string)$task['TASK_ID'],
        'employee' => [
            'code' => $code,
            'name' => (string)$employee['NAME'],
            'doj' => (string)$employee['DOJ'],
            'designation' => (string)$employee['DESIGNATION'],
            'department' => (string)$employee['DEPARTMENT'],
            'division' => (string)$employee['DIVISION'],
            'location' => (string)$employee['LOCATION'],
        ],
        'appraisal' => [
            'currentCtc' => (float)$appr['CURR_CTC'],
            'newCtc' => (float)$appr['NEW_CTC'],
            'effectiveFrom' => appraisalDisplayDate($appr['INCR_FROM']),
            'promotion' => $isPromotion,
            'promotionTo' => (string)($newOrg['NM'] ?? ''),
            'remarks' => (string)($task['MANAGER']['APPR_REMARKS'] ?? ''),
        ],
        'pfBasis' => $pfBasis,
        'hasPfBasis' => (bool)array_filter($heads, static fn($h) => $h['AD_CODE'] === 'XPFBS'),
        'heads' => array_values(array_map(static fn($h) => [
            'code' => (string)$h['AD_CODE'],
            'label' => (string)$h['SH_DESCR'],
            'current' => $current[$h['AD_CODE']] ?? null,
            'structure' => $proposed[$h['AD_CODE']] ?? null,
            'monthly' => isset($saved[$h['AD_CODE']]) ? (float)$saved[$h['AD_CODE']]['AMOUNT_MNTH'] : null,
        ], array_filter($heads, static fn($h) => strtoupper((string)$h['CALC_KEY']) !== 'V'))),
        'status' => $saved ? (string)reset($saved)['STATUS'] : '',
    ]];
}, 'getAppraisalCtc.php');
