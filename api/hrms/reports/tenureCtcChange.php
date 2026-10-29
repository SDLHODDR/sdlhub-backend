<?php

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/validateCsrf.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/emp_func.php';

header('Content-Type: application/json; charset=UTF-8');

if (!$sql___func___con) {
    apiResponse(false, 'Database connection failed.', null, 500);
    exit;
}

$empCode = trim((string)($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? ''));
if ($empCode === '') {
    apiResponse(false, 'Session expired. Please login again.', null, 401);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$transactionStarted = false;

function tenureCtcSafeCode($value) {
    $value = trim((string)$value);
    return $value !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $value) ? $value : '';
}

function tenureCtcResolveSalaryType($employeeCode, $adCode) {
    // Task 26 creates tenure CTC rows, whose salary type is T.
    return 'T';
}

function tenureCtcCeiling($number, $step) {
    return is_numeric($number) && is_numeric($step) && (float)$step !== 0.0
        ? ceil((float)$number / (float)$step) * (float)$step
        : (float)$number;
}

function tenureCtcBuildStructure($ctc, $headRows, $pfInput = 0) {
    $monthlyCtc = (float)$ctc / 12;
    $structure = [];
    $basic = 0.0;
    foreach ($headRows as $head) {
        $code = (string)$head['AD_CODE'];
        $calcKey = strtoupper((string)($head['CALC_KEY'] ?? ''));
        $value = (float)($head['CALC_VAL'] ?? 0);
        $amount = 0.0;

        if ($code === 'MBAS') {
            if ($calcKey === 'C') $basic = tenureCtcCeiling($monthlyCtc * $value / 100, 50);
            elseif ($calcKey === 'F') $basic = $value;
            $amount = $basic;
        } elseif ($code !== 'MSPL') {
            if ($code === 'CPF') {
                $amount = $basic < 15000 ? $basic * 12 / 100 : ($pfInput == 1 ? $value : $basic * 12 / 100);
            } elseif ($calcKey === 'C') {
                $amount = $monthlyCtc * $value / 100;
            } elseif ($calcKey === 'B') {
                $amount = $basic * $value / 100;
            } elseif ($calcKey === 'F') {
                $amount = $value;
            }

        }

        if ($code !== 'MSPL' && (($head['RND_OFF'] ?? 'N') !== 'N' || $code === 'MHRA') && $calcKey !== 'F') {
            $amount = tenureCtcCeiling($amount, $head['RND_TO'] ?? 0);
        }

        if ($code !== 'MSPL' && $code !== '') $structure[$code] = round($amount, 2);
    }

    $net = array_sum($structure);
    if ($net <= $monthlyCtc) {
        $specialRowExists = false;
        foreach ($headRows as $head) if (($head['AD_CODE'] ?? '') === 'MSPL') $specialRowExists = true;
        if ($specialRowExists) {
            $structure['MSPL'] = round($monthlyCtc - $net, 2);
        } elseif (isset($structure['MHRA'])) {
            $structure['MHRA'] = round(tenureCtcCeiling($structure['MHRA'] + $monthlyCtc - $net, 10), 2);
        }
    } elseif (isset($structure['MHRA'])) {
        $structure['MHRA'] = round(tenureCtcCeiling($structure['MHRA'] - ($net - $monthlyCtc), 10), 2);
    }
    return $structure;
}

function tenureCtcLoadRequest($userTaskId, $employeeCode, $workflowTaskId = 26) {
    $taskId = (int)$userTaskId;
    $workflowTaskId = (int)$workflowTaskId;
    $employeeCode = str_replace("'", "''", $employeeCode);
    if ($taskId <= 0 || !in_array($workflowTaskId, [26, 27], true)) {
        throw new RuntimeException('Invalid tenure CTC task.');
    }

    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS
        WHERE ID = '{$taskId}' AND TASK_ID = '{$workflowTaskId}' AND STATUS = 'O'");
    if (empty($task) || trim((string)($task['TRAN_CODE'] ?? '')) !== $employeeCode) {
        throw new RuntimeException('The pending task was not found or is no longer available.');
    }
    $sessionEmp = trim((string)($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? ''));
    $assignedToUser = trim((string)($task['EMP_CODE_FOR'] ?? '')) === $sessionEmp;
    $scopeMatch = false;
    foreach (['COMP_ID' => 'compId', 'DIVSN_ID' => 'divId', 'DEPT_ID' => 'deptId'] as $taskField => $sessionField) {
        $allowedIds = $_SESSION[$sessionField] ?? [];
        if (!is_array($allowedIds)) $allowedIds = preg_split('/\s*,\s*/', (string)$allowedIds, -1, PREG_SPLIT_NO_EMPTY);
        $allowedIds = array_map(static fn($id) => trim((string)$id, " \"'"), $allowedIds);
        if ($allowedIds) {
            $scopeMatch = true;
            if (!in_array(trim((string)($task[$taskField] ?? '')), $allowedIds, true)) {
                $scopeMatch = false;
                break;
            }
        }
    }
    if (!$assignedToUser && !$scopeMatch) {
        throw new RuntimeException('You are not authorized to open this tenure CTC task.');
    }

    $tenure = singRec("SELECT * FROM HR_EMP_TENURE_CHANGE
        WHERE EMP_CODE = '{$employeeCode}' AND STATUS = 'T'
        ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY");
    if (empty($tenure)) {
        throw new RuntimeException('The employee tenure change record is not available.');
    }

    return [$task, $tenure];
}

try {
    if ($method === 'GET') {
        $taskId = (int)($_GET['task_id'] ?? 26);
        $userTaskId = (int)($_GET['user_task_id'] ?? 0);
        $employeeCode = tenureCtcSafeCode($_GET['emp_code'] ?? '');
        if ($employeeCode === '') {
            apiResponse(false, 'Employee code is required.', null, 422);
            exit;
        }

        [$task, $tenure] = tenureCtcLoadRequest($userTaskId, $employeeCode, $taskId);
        $office = singRec("SELECT * FROM (
                SELECT O.ORG_ID, O.ORG_LOC_ID, O.DESI_ID,
                    TO_CHAR(O.EFFEC_FROM, 'DD-MON-YYYY') AS DOJ, O.EFFEC_FROM, O.EFFEC_TO
                FROM HR_EMP_OFFICE_DET O
                WHERE O.EMP_CODE = '{$employeeCode}'
                  AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
                ORDER BY O.EFFEC_FROM DESC
            ) WHERE ROWNUM = 1");
        $employeeRow = singRec("SELECT I.EMP_CODE, I.FNAME, I.LNAME,
                GET_DEPT_NAME(O.DEPT_ID) AS DEPARTMENT,
                GET_ORG_LOC_NAME(O.ORG_LOC_ID) AS LOCATION,
                GET_DESIGN_NAME(O.DESI_ID) AS DESIGNATION,
                T.EMP_TYPE AS EMPLOYEE_TYPE
            FROM HR_EMPLOYEE_INFO I
            LEFT JOIN HR_EMP_OFFICE_DET O ON O.EMP_CODE = I.EMP_CODE
                AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
            LEFT JOIN HR_EMP_TYPE T ON T.ETYPE_ID = '{$tenure['ETYPE_ID']}'
            WHERE I.EMP_CODE = '{$employeeCode}'
            ORDER BY O.EFFEC_FROM DESC FETCH FIRST 1 ROWS ONLY");
        $tenureRowId = (int)$tenure['ID'];
        $orgId = (int)($office['ORG_ID'] ?? 0);
        if ($orgId <= 0) {
            throw new RuntimeException('Active employee organization details were not found.');
        }

        $org = singRec("SELECT JD_ID, COMPANY FROM HR_ORGANOGRAM WHERE ID = '{$orgId}'");
        $jobId = (int)($org['JD_ID'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('No job description is configured for the employee organization.');
        }

        $headRows = multiRec("SELECT H.AD_CODE, B.SH_DESCR AS DESCR, H.KEY AS CALC_KEY, H.VAL AS CALC_VAL,
                B.RND_OFF, B.RND_TO, B.SEQ_NO
            FROM HR_JD_CTC_HEADS H
            INNER JOIN HR_BCS_ALLW_DEDN B ON B.ID = H.AD_ID
            WHERE H.JD_ID = '{$jobId}'
              AND SYSDATE BETWEEN H.EFFEC_FROM AND NVL(H.EFFEC_TO, DATE '3000-03-01')
            ORDER BY B.SEQ_NO");
        if (empty($headRows)) {
            throw new RuntimeException('No CTC heads are configured for the employee organization.');
        }

        $savedHeadRows = multiRec("SELECT AD_CODE, AMOUNT_MNTH
            FROM HR_EMP_CTC_HEADS
            WHERE EMP_CODE = '{$employeeCode}' AND REF_ID = '{$tenureRowId}'");
        $savedAmounts = [];
        foreach ($savedHeadRows ?: [] as $savedHead) {
            $savedAmounts[(string)$savedHead['AD_CODE']] = $savedHead['AMOUNT_MNTH'];
        }
        $structure = tenureCtcBuildStructure(
            (float)($tenure['PROP_CTC'] ?? $tenure['CURR_CTC'] ?? 0),
            $headRows,
            $savedAmounts['XPFBS'] ?? 0
        );
        $ctcHeads = [];
        foreach ($headRows as $head) {
            $code = trim((string)$head['AD_CODE']);
            if ($code === '') continue;
            $monthly = (float)($structure[$code] ?? 0);
            $ctcHeads[] = [
                'code' => $code,
                'name' => $head['DESCR'] ?: $code,
                'structureMonthly' => $monthly,
                'structureAnnual' => $monthly * 12,
                'monthlyAmount' => $savedAmounts[$code] ?? '',
            ];
        }

        $empName = trim(($employeeRow['FNAME'] ?? '') . ' ' . ($employeeRow['LNAME'] ?? ''));
        $details = [
            'employee' => [
                'code' => $employeeCode,
                'name' => $empName,
                'department' => $employeeRow['DEPARTMENT'] ?? '',
                'employeeType' => $employeeRow['EMPLOYEE_TYPE'] ?? '',
                'location' => $employeeRow['LOCATION'] ?? '',
                'designation' => $employeeRow['DESIGNATION'] ?? '',
                'doj' => $office['DOJ'] ?? '',
            ],
            'assessment' => [
                'currentCtc' => (float)($tenure['CURR_CTC'] ?? 0),
                'proposedCtc' => (float)($tenure['PROP_CTC'] ?? 0),
                'effectiveFrom' => $tenure['CTC_EFF_FROM'] ?? $tenure['EFF_FROM'] ?? '',
                'remarks' => $tenure['REMARKS'] ?? '',
                'period' => $tenure['ETYPE_PERIOD'] ?? '0',
                'employeeType' => $tenure['ETYPE_ID'] ?? '',
            ],
            'pfInput' => $savedAmounts['XPFBS'] ?? 0,
            'ctcHeads' => $ctcHeads,
            'tenureChangeId' => $tenureRowId,
        ];
        apiResponse(true, 'Tenure CTC details fetched successfully.', $details, 200);
        exit;
    }

    if ($method !== 'POST') {
        apiResponse(false, 'Invalid request method.', null, 405);
        exit;
    }

    $action = strtolower(trim((string)($input['action'] ?? 'submit')));
    if (!in_array($action, ['save', 'submit'], true)) {
        apiResponse(false, 'Invalid CTC action.', null, 422);
        exit;
    }

    $workflowTaskId = (int)($input['task_id'] ?? 0);
    $userTaskId = (int)($input['user_task_id'] ?? 0);
    $employeeCode = tenureCtcSafeCode($input['emp_code'] ?? '');
    $pfInput = filter_var($input['pf_input'] ?? null, FILTER_VALIDATE_INT);
    $rows = $input['ctc_heads'] ?? null;
    if ($employeeCode === '' || $pfInput === false || !is_array($rows) || count($rows) === 0) {
        apiResponse(false, 'Employee, PF selection, and CTC head values are required.', null, 422);
        exit;
    }

    [$task, $tenure] = tenureCtcLoadRequest($userTaskId, $employeeCode, $workflowTaskId);
    $tenureId = (int)$tenure['ID'];
    $office = singRec("SELECT * FROM (
            SELECT ORG_ID FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = '{$employeeCode}'
              AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01')
            ORDER BY EFFEC_FROM DESC
        ) WHERE ROWNUM = 1");
    $org = singRec("SELECT COMPANY FROM HR_ORGANOGRAM WHERE ID = '" . (int)($office['ORG_ID'] ?? 0) . "'");
    $allowedHeads = multiRec("SELECT H.AD_CODE, B.AD_TYPE
        FROM HR_JD_CTC_HEADS H
        INNER JOIN HR_BCS_ALLW_DEDN B ON B.ID = H.AD_ID
        WHERE H.JD_ID = (SELECT JD_ID FROM HR_ORGANOGRAM WHERE ID = '" . (int)($office['ORG_ID'] ?? 0) . "')
          AND SYSDATE BETWEEN H.EFFEC_FROM AND NVL(H.EFFEC_TO, DATE '3000-03-01')");
    $allowed = [];
    foreach ($allowedHeads ?: [] as $allowedHead) {
        $allowed[(string)$allowedHead['AD_CODE']] = (string)($allowedHead['AD_TYPE'] ?? '');
    }
    $totalMonthly = 0.0;
    $validatedRows = [];
    foreach ($rows as $row) {
        $code = tenureCtcSafeCode($row['ad_code'] ?? '');
        $monthly = $row['monthly'] ?? null;
        if ($code === '' || !isset($allowed[$code]) || !is_numeric($monthly) || (float)$monthly < 0) {
            apiResponse(false, 'One or more CTC head values are invalid.', null, 422);
            exit;
        }
        $totalMonthly += (float)$monthly;
        $validatedRows[$code] = (float)$monthly;
    }
    if (count($validatedRows) !== count($rows)) {
        apiResponse(false, 'Duplicate CTC head codes are not allowed.', null, 422);
        exit;
    }

    $salaryTypes = [];
    foreach (array_keys($validatedRows) as $code) {
        $salaryTypes[$code] = tenureCtcResolveSalaryType($employeeCode, $code);
        if ($salaryTypes[$code] === '') {
            apiResponse(false, "Could not determine the required salary type for CTC head {$code}.", null, 422);
            exit;
        }
    }
    $pfSalaryType = tenureCtcResolveSalaryType($employeeCode, 'XPFBS');
    if ($pfSalaryType === '') {
        $pfSalaryType = reset($salaryTypes);
    }
    if ($pfSalaryType === '') {
        apiResponse(false, 'Could not determine the required salary type for the PF selection.', null, 422);
        exit;
    }

    $period = singRec("SELECT TO_CHAR(T.CTC_EFF_FROM, 'YYYY-MM-DD') AS EFF_FROM,
            TO_CHAR(P.TO_DATE, 'YYYY-MM-DD') AS EFF_TO
        FROM HR_EMP_TENURE_CHANGE T
        CROSS JOIN EPPLIVE.BCS_ACCT_PERIOD P
        WHERE T.ID = '{$tenureId}'
          AND SYSDATE BETWEEN P.FR_DATE AND P.TO_DATE");
    if (empty($period['EFF_FROM']) || empty($period['EFF_TO'])) {
        throw new RuntimeException('The tenure effective date or current payroll period was not found.');
    }
    $effectiveFromSql = "TO_DATE('{$period['EFF_FROM']}', 'YYYY-MM-DD')";
    $effectiveToSql = "TO_DATE('{$period['EFF_TO']}', 'YYYY-MM-DD')";
    $effectiveFromIso = $period['EFF_FROM'];
    $proposedCtc = round($totalMonthly * 12, 2);
    $eppConnection = db_epplive();
    if (!$eppConnection) throw new RuntimeException('EPP database connection failed.');
    $hrmsConnection = $sql___func___con;

    startQry();
    $transactionStarted = true;
    $saveCtcHead = static function ($code, $monthly, $salaryType, $calcType) use (
        $employeeCode, $tenureId, $proposedCtc, $effectiveFromSql,
        $effectiveToSql, $empCode
    ) {
        $annual = round((float)$monthly * 12, 2);
        $existing = singRec("SELECT ID FROM HR_EMP_CTC_HEADS
            WHERE EMP_CODE = '{$employeeCode}' AND REF_ID = '{$tenureId}'
              AND AD_CODE = '{$code}' AND SALARY_TYPE = '{$salaryType}'");
        $data = [
            'EMP_CODE' => $employeeCode,
            'AD_CODE' => $code,
            'AMOUNT_MNTH' => $monthly,
            'REF_ID' => (string)$tenureId,
            'SALARY_TYPE' => $salaryType,
            'STATUS' => 'T',
            'CALC_TYPE' => $calcType,
            'EFF_FROM' => $effectiveFromSql,
            'EFF_TO' => $effectiveToSql,
            'CHG_ON' => 'SYSDATE',
            'CHG_BY' => $empCode,
        ];
        if ($code !== 'XPFBS') {
            $data['AMOUNT_YRLY'] = $annual;
            $data['CTC'] = $proposedCtc;
        }
        if (!empty($existing['ID'])) {
            $saved = execQry([
                'type' => 'update', 'table' => 'HR_EMP_CTC_HEADS',
                'data' => $data, 'where' => ['ID' => (string)$existing['ID']], 'print' => 0,
            ]);
        } else {
            $data = ['ID' => ''] + $data;
            $saved = execQry([
                'type' => 'insert', 'table' => 'HR_EMP_CTC_HEADS',
                'data' => $data, 'print' => 0,
            ]);
        }
        if ($saved === false) throw new RuntimeException("Could not save CTC head {$code}.");
    };

    $saveEppSalary = static function ($code, $monthly, $type) use (
        $employeeCode, $effectiveFromSql, $effectiveToSql, $effectiveFromIso,
        $empCode, $eppConnection, $hrmsConnection
    ) {
        $GLOBALS['sql___func___con'] = $eppConnection;
        try {
            $existing = singRec("SELECT ID FROM BCS_EMPLOYEE_AD
                WHERE EMP_CODE = '{$employeeCode}' AND AD_CODE = '{$code}'
                  AND TRUNC(EFF_DATE) = TO_DATE('{$effectiveFromIso}', 'YYYY-MM-DD')
                  AND ROWNUM = 1");
            $data = [
                'TYPE' => $type,
                'EFF_DATE' => $effectiveFromSql,
                'EXP_DATE' => $effectiveToSql,
                'AMOUNT' => $monthly,
                'CONFIRMED' => 'Y',
                'CONF_DATE' => 'SYSDATE',
                'CHG_ON' => 'SYSDATE',
                'CHG_BY' => $empCode,
                'EMP_CODE_APRV' => $empCode,
            ];
            if (!empty($existing['ID'])) {
                $saved = execQry([
                    'type' => 'update', 'table' => 'BCS_EMPLOYEE_AD',
                    'data' => $data, 'where' => ['ID' => (string)$existing['ID']], 'print' => 0,
                ]);
            } else {
                $overlapUpdated = executeQry("UPDATE BCS_EMPLOYEE_AD
                    SET EXP_DATE = {$effectiveFromSql} - 1
                    WHERE EMP_CODE = '{$employeeCode}' AND AD_CODE = '{$code}'
                      AND CONFIRMED = 'Y' AND EFF_DATE < {$effectiveFromSql}
                      AND EXP_DATE >= {$effectiveFromSql}");
                if ($overlapUpdated === false) {
                    throw new RuntimeException("Could not close the prior EPP salary period for {$code}.");
                }
                $data = ['ID' => '', 'EMP_CODE' => $employeeCode, 'AD_CODE' => $code] + $data;
                $saved = execQry([
                    'type' => 'insert', 'table' => 'BCS_EMPLOYEE_AD',
                    'data' => $data, 'print' => 0,
                ]);
            }
            if ($saved === false) throw new RuntimeException("Could not update EPP salary head {$code}.");
        } finally {
            $GLOBALS['sql___func___con'] = $hrmsConnection;
        }
    };

    foreach ($validatedRows as $code => $monthly) {
        $adType = $allowed[$code] ?? '';
        $calcType = $adType === 'A' ? 'F' : ($adType === 'D' ? 'P' : '');
        if ($calcType === '') throw new RuntimeException("Could not determine the calculation type for {$code}.");
        $eppType = $calcType === 'P' ? 'P' : 'F';
        $saveCtcHead($code, $monthly, $salaryTypes[$code], $calcType);
        $saveEppSalary($code, $monthly, $eppType);
    }
    $pfCalcType = 'F';
    $saveCtcHead('XPFBS', $pfInput, $pfSalaryType, $pfCalcType);
    $saveEppSalary('XPFBS', $pfInput, 'F');
    $isFinalCtcStep = $workflowTaskId === 27;
    $savedProposal = execQry([
        'type' => 'update', 'table' => 'HR_EMP_TENURE_CHANGE',
        'data' => [
            'PROP_CTC' => $proposedCtc,
            'STATUS' => $isFinalCtcStep && $action === 'submit' ? 'A' : 'T',
            'CHG_ON' => 'SYSDATE',
            'CHG_BY' => $empCode,
        ],
        'where' => ['ID' => (string)$tenureId], 'print' => 0,
    ]);
    if ($savedProposal === false) throw new RuntimeException('Could not save the proposed CTC total.');

    if ($action === 'save') {
        endQry();
        $transactionStarted = false;
        apiResponse(true, 'CTC values saved successfully.', [
            'proposed_ctc' => $proposedCtc,
        ], 200);
        exit;
    }

    $companyId = $task['COMP_ID'] ?: ($org['COMPANY'] ?? '');
    if ($isFinalCtcStep) {
        $nextTaskId = generateTaskHR(
            'PRT_TNU_CHG_LTR', $employeeCode,
            'Print Tenure Change Letter for ' . getEmpInfoByCodeHR($employeeCode), '',
            'A', 'O', '', $empCode, $companyId, $task['DIVSN_ID'], $task['DEPT_ID']
        );
        $successMessage = 'Final CTC saved and sent for tenure letter printing.';
        $taskError = 'Could not create the Print Tenure Change Letter task.';
    } else {
        $nextTaskId = generateTaskHR(
            'AUTH_TNU_CHG_LTR', $employeeCode,
            'Tenure CTC approval for ' . getEmpInfoByCodeHR($employeeCode), '',
            'A', 'O', '', $empCode, $companyId, $task['DIVSN_ID'], $task['DEPT_ID']
        );
        $successMessage = 'CTC changes saved and sent for final authorization.';
        $taskError = 'Could not create the final authorization task.';
    }
    if (empty($nextTaskId)) throw new RuntimeException($taskError);

    taskUpdateHR('C', '', $userTaskId, null, null);
    if (($GLOBALS['qry_____result'] ?? 0) == 1) {
        throw new RuntimeException('Could not close the current task.');
    }
    endQry();
    $transactionStarted = false;
    apiResponse(true, $successMessage, [
        'task_id' => $nextTaskId,
        'proposed_ctc' => $proposedCtc,
    ], 200);
    exit;
} catch (Throwable $e) {
    if ($transactionStarted && !empty($sql___func___con)) {
        oci_rollback($sql___func___con);
    }
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'tenureCtcChange.php');
    apiResponse(false, $e->getMessage(), null, 500);
} finally {
    if (!empty($eppConnection)) oci_close($eppConnection);
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
