<?php
/*
 * POST { user_task_id } - Submit a joining detail task (legacy CloseTask): closes
 * the task. The CTC task first posts the saved amounts to payroll (legacy
 * submitCTCtoBCS). Basic, Tenure and Bank need saved details, as on the old page.
 */
require_once __DIR__ . '/joiningDetailCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = joiningLoadDetailTask($input['user_task_id'] ?? 0, $empCode);
    $code = $task['TRAN_CODE'];
    $required = [
        'basic' => ["SELECT ID FROM HR_EMP_BASIC_INFO WHERE EMP_CODE = :emp_code AND ADHAR_NO IS NOT NULL AND ROWNUM = 1", 'Please save the basic details first.'],
        'tenure' => ["SELECT ID FROM HR_EMP_TENURE WHERE EMP_CODE = :emp_code AND ROWNUM = 1", 'Please save the tenure details first.'],
        'bank' => ["SELECT ID FROM HR_EMP_BANK_DET WHERE EMP_CODE = :emp_code AND ROWNUM = 1", 'Please save the bank details first.'],
    ];
    if (isset($required[$task['SECTION']])) {
        [$sql, $message] = $required[$task['SECTION']];
        if (empty(singRec($sql, [':emp_code' => $code])['ID'])) {
            throw new JoiningRequestException($message, 422);
        }
    }
    if ($task['SECTION'] === 'ctc') {
        joiningPostCtcToPayroll($code, $empCode);
    }
    taskUpdateHR('C', '', $task['ID'], null, null);
    return ['Task submitted.', null];
}, 'submitJoiningTask.php');

/** Saved CTC rows -> payroll allowances and deductions (HR_BCS_EMPLOYEE_AD). */
function joiningPostCtcToPayroll($code, $sessionEmp)
{
    $saved = joiningSavedCtc($code);
    if (!$saved) {
        throw new JoiningRequestException('Please save the CTC breakup first.', 422);
    }
    $period = joiningCtcPeriod($code);
    $basic = (float)($saved['MBAS']['AMOUNT_MNTH'] ?? 0);
    $percentOfBasic = static function ($row) use ($basic) {
        if ($basic <= 0) {
            throw new JoiningRequestException('Basic must be saved before the CTC can be submitted.', 422);
        }
        return (float)$row['AMOUNT_MNTH'] * 100 / $basic;
    };

    $dates = [':eff_date' => $period['EFF_FROM'], ':exp_date' => $period['EFF_TO'], ':emp_code' => $code];
    joiningExecute("DELETE FROM HR_BCS_EMPLOYEE_AD WHERE EMP_CODE = :emp_code
        AND EFF_DATE = TO_DATE(:eff_date, 'YYYY-MM-DD') AND EXP_DATE = TO_DATE(:exp_date, 'YYYY-MM-DD')",
        $dates, 'Could not update the payroll heads.');

    $types = [];
    foreach (joiningRecords("SELECT AD_CODE, AD_TYPE FROM HR_BCS_ALLW_DEDN") as $row) {
        $types[$row['AD_CODE']] = $row['AD_TYPE'];
    }
    foreach ($saved as $adCode => $row) {
        $adType = $types[$adCode] ?? '';
        $type = ['A' => 'F', 'D' => 'P', 'V' => 'F'][$adType] ?? '';
        $amount = (float)$row['AMOUNT_MNTH'];
        if ($adCode === 'CPF') {
            if ((string)($saved['XPFBS']['AMOUNT_MNTH'] ?? '') === '1') {
                $type = 'F';
                $amount = 15000;
            } else {
                $type = 'P';
                $amount = $percentOfBasic($row);
            }
        } elseif (in_array($adCode, ['CBON', 'CGRA', 'CESI'], true)) {
            $type = 'P';
            $amount = $percentOfBasic($row);
        }
        if ($adType === 'D' || $adType === 'V' || $amount != 0) {
            joiningExecute("INSERT INTO HR_BCS_EMPLOYEE_AD (EMP_CODE, AD_CODE, EFF_DATE, EXP_DATE, TYPE, AMOUNT, CONFIRMED, CHG_ON, CHG_BY)
                VALUES (:emp_code, :ad_code, TO_DATE(:eff_date, 'YYYY-MM-DD'), TO_DATE(:exp_date, 'YYYY-MM-DD'),
                    :type, :amount, 'N', SYSDATE, :chg_by)", $dates + [
                ':ad_code' => $adCode, ':type' => $type, ':amount' => (string)$amount, ':chg_by' => $sessionEmp,
            ], 'Could not save the payroll heads.');
        }
    }
}
