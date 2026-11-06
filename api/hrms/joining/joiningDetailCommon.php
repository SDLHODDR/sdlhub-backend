<?php
/*
|--------------------------------------------------------------------------
| JOINING DETAIL TASKS - COMMON
|--------------------------------------------------------------------------
| Tasks 34-43, created when a new employee is authorized. Each one opens one
| tab of the Employee Information page (legacy employee_edit2.php with
| TASK_GRP). The employee always comes from the task, never from the request.
*/
require_once __DIR__ . '/joiningCommon.php';

/** The open detail task, after checking the user may act on it. */
function joiningLoadDetailTask($userTaskId, $sessionEmp)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new JoiningRequestException('A valid task is required.', 422);
    }
    loadHrmsAccess($sessionEmp);
    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND STATUS = 'O'", [':id' => (string)$userTaskId]);
    $section = JOINING_DETAIL_SECTIONS[(string)($task['TASK_ID'] ?? '')] ?? '';
    if (empty($task['ID']) || $section === '') {
        throw new JoiningRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new JoiningRequestException('You are not authorized to act on this task.', 403);
    }
    $task['SECTION'] = $section;
    $task['EMPLOYEE'] = joiningLoadEmployee($task['TRAN_CODE']);
    return $task;
}

/** Cleaned text field: required / byte length / allowed characters. */
function joiningText(array $values, $key, $label, $maxBytes, $required = false, $pattern = null, $patternMessage = '')
{
    $value = trim(preg_replace('/\s+/', ' ', (string)($values[$key] ?? '')));
    if ($value === '') {
        if ($required) throw new JoiningRequestException("{$label} is required.", 422);
        return '';
    }
    joiningAssertLength($value, $maxBytes, $label);
    if ($pattern !== null && !preg_match($pattern, $value)) {
        throw new JoiningRequestException($patternMessage ?: "{$label} is not valid.", 422);
    }
    return $value;
}

function joiningAssertAadhaar($value, $label = 'Aadhaar number')
{
    if (!preg_match('/^[2-9][0-9]{11}$/', $value)) {
        throw new JoiningRequestException("{$label} must be 12 digits and cannot start with 0 or 1.", 422);
    }
}

/** A value must exist in its master table. */
function joiningAssertMaster($value, $sql, $label)
{
    if (empty(singRec($sql, [':value' => (string)$value])['X'])) {
        throw new JoiningRequestException("Please select a valid {$label}.", 422);
    }
}

/** Open-ended periods run to 01-Mar-3000, as everywhere else in HRMS. */
function joiningAssertDateOrder($from, $to, $fromLabel, $toLabel)
{
    if ($from !== '' && $to !== '' && $to < $from) {
        throw new JoiningRequestException("{$toLabel} cannot be before {$fromLabel}.", 422);
    }
}

/** Rounds up to the next multiple; heads without a rounding step stay as they are. */
function joiningCeil($amount, $step)
{
    $step = (float)$step;
    return $step > 0 ? ceil(((float)$amount) / $step) * $step : (float)$amount;
}

/** The employee's CTC heads: JD structure heads currently in force. */
function joiningCtcHeads($employeeCode)
{
    $organogram = singRec("SELECT G.JD_ID FROM HR_EMP_OFFICE_DET O
        INNER JOIN HR_ORGANOGRAM G ON G.ID = O.ORG_ID
        WHERE O.EMP_CODE = :emp_code
          AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
          AND ROWNUM = 1", [':emp_code' => $employeeCode]);
    if (empty($organogram['JD_ID'])) {
        // A joiner whose office row starts in the future.
        $organogram = singRec("SELECT * FROM (SELECT G.JD_ID FROM HR_EMP_OFFICE_DET O
                INNER JOIN HR_ORGANOGRAM G ON G.ID = O.ORG_ID
                WHERE O.EMP_CODE = :emp_code ORDER BY O.EFFEC_FROM DESC)
            WHERE ROWNUM = 1", [':emp_code' => $employeeCode]);
    }
    if (empty($organogram['JD_ID'])) {
        return [];
    }
    return joiningRecords("SELECT A.ID, A.AD_CODE, A.KEY AS HEAD_KEY, A.VAL,
            B.SH_DESCR AS DESCR, B.AD_TYPE, B.RND_OFF, B.RND_TO
        FROM HR_JD_CTC_HEADS A
        INNER JOIN HR_BCS_ALLW_DEDN B ON A.AD_ID = B.ID
        WHERE A.JD_ID = :jd_id
          AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
        ORDER BY B.SEQ_NO", [':jd_id' => (string)$organogram['JD_ID']]);
}

/**
 * Monthly amounts per head for a CTC in lakhs (legacy employee_edit2.php CTC tab).
 * $pfBasis is the XPFBS input: 1 = 12% of 15000, 0 = 12% of Basic.
 */
function joiningCtcStructure(array $heads, $ctcLakhs, $pfBasis)
{
    $monthlyCtc = ((float)$ctcLakhs * 100000) / 12;
    $basic = 0.0;
    $amounts = [];
    $hasSpecial = false;

    // Basic first: the heads calculated on it may come before it.
    foreach ($heads as $head) {
        if ($head['AD_CODE'] !== 'MBAS') continue;
        if ($head['HEAD_KEY'] === 'C') {
            $basic = joiningCeil($monthlyCtc * (float)$head['VAL'] / 100, 50);
        } elseif ($head['HEAD_KEY'] === 'F') {
            $basic = (float)$head['VAL'];
        }
    }

    foreach ($heads as $head) {
        $key = $head['HEAD_KEY'];
        $code = $head['AD_CODE'];
        $value = (float)$head['VAL'];
        if ($code === 'MSPL') $hasSpecial = true;
        if ($key === 'V' || $code === 'MSPL') continue;

        $amount = 0.0;
        if ($code === 'CPF') {
            if ($basic < 15000) {
                $amount = $basic * 12 / 100;
            } else {
                $amount = (int)$pfBasis === 1 ? $value : $basic * 12 / 100;
            }
        } elseif ($key === 'C') {
            $amount = $monthlyCtc * $value / 100;
        } elseif ($key === 'B') {
            $amount = $basic * $value / 100;
        } elseif ($key === 'F') {
            $amount = $value;
        }
        if (($head['RND_OFF'] !== 'N' || $code === 'MHRA') && $key !== 'F') {
            $amount = joiningCeil($amount, $head['RND_TO']);
        }
        $amounts[$code] = $amount;
    }

    $net = array_sum($amounts);
    if ($net < $monthlyCtc) {
        $difference = joiningCeil($monthlyCtc - $net, 10);
        if ($hasSpecial) {
            $amounts['MSPL'] = $difference;
        } else {
            $amounts['MHRA'] = ($amounts['MHRA'] ?? 0) + $difference;
        }
    } else {
        $amounts['MHRA'] = joiningCeil(($amounts['MHRA'] ?? 0) - ($net - $monthlyCtc), 10);
    }
    return $amounts;
}

/** Salary period a joiner's CTC rows belong to: DOJ to the end of the current accounting year. */
function joiningCtcPeriod($employeeCode)
{
    $period = singRec("SELECT TO_CHAR(E.DOJ, 'YYYY-MM-DD') AS EFF_FROM, TO_CHAR(P.TO_DATE, 'YYYY-MM-DD') AS EFF_TO
        FROM HR_EMPLOYEE_INFO E
        CROSS JOIN HR_BCS_ACCT_PERIOD P
        WHERE E.EMP_CODE = :emp_code AND SYSDATE BETWEEN P.FR_DATE AND P.TO_DATE
          AND ROWNUM = 1", [':emp_code' => $employeeCode]);
    if (empty($period['EFF_FROM']) || empty($period['EFF_TO'])) {
        throw new JoiningRequestException('No accounting period is open for today.', 422);
    }
    return $period;
}

/** Saved joining CTC rows, per AD_CODE (eff_from is the DOJ, which can still be in the future). */
function joiningSavedCtc($employeeCode)
{
    $rows = joiningRecords("SELECT AD_CODE, CTC, AMOUNT_MNTH, AMOUNT_YRLY
        FROM HR_EMP_CTC_HEADS
        WHERE EMP_CODE = :emp_code AND SALARY_TYPE = 'J'", [':emp_code' => $employeeCode]);
    $saved = [];
    foreach ($rows as $row) {
        $saved[$row['AD_CODE']] = $row;
    }
    return $saved;
}
