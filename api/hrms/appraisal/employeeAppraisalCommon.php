<?php
/*
|--------------------------------------------------------------------------
| EMPLOYEE APPRAISAL TASK (46, EMP_APPR) - COMMON
|--------------------------------------------------------------------------
| Legacy employee_appraisal.php + appraisal_setup_sv.php (EmpAppFIX / CloseEmpAppFIX /
| EmpAppKRA / CloseEmpAppKRA). Each appraiser in the employee's chain gets task 46 in turn:
| HR_APPR_EMP_MGR holds that appraiser's row (TASK_ID links it to the task), HR_APPR_EMP_APPR
| the answers. Fixed format (APPR_BASE F) answers the appraisal's questions; KRA base (K)
| rates the employee's KRAs and remarks each KRA activity. Submit hands the appraisal to the
| next appraiser in the chain, if any. Access follows the task (not the Appraisal menu).
*/

require_once __DIR__ . '/appraisalCommon.php';

const EMPLOYEE_APPRAISAL_TASK = 46;
const EMPLOYEE_APPRAISAL_REMARK_MAX = 500;   // HR_APPR_EMP_APPR.QUES_REMARKS
const EMPLOYEE_APPRAISAL_ANSWER_MAX = 1000;  // HR_APPR_EMP_APPR.ANS_TEXT
const EMPLOYEE_APPRAISAL_KRA_REMARK_MAX = 3000;
const EMPLOYEE_APPRAISAL_REVIEW_MAX = 3000;  // legacy editor limit

/** Read endpoint without the Appraisal menu check (the task grants access). */
function employeeAppraisalRunRead(callable $work, $logName)
{
    global $sql___func___con, $empCode;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new AppraisalRequestException('Invalid request method.', 405);
        }
        loadHrmsAccess($empCode);
        session_write_close();
        [$message, $data] = $work();
        apiResponse(true, $message, $data, 200);
    } catch (AppraisalRequestException $e) {
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Unable to load the appraisal.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/** Write endpoint in one transaction, without the Appraisal menu check. */
function employeeAppraisalRunAction(callable $work, $logName)
{
    global $sql___func___con, $empCode;
    $started = false;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new AppraisalRequestException('Invalid request method.', 405);
        }
        loadHrmsAccess($empCode);
        startQry();
        $started = true;
        [$message, $data] = $work();
        if (($GLOBALS['qry_____result'] ?? 0) == 1) {
            throw new RuntimeException('A database update failed. Nothing was saved.');
        }
        if (!oci_commit($sql___func___con)) {
            throw new RuntimeException('Could not commit the appraisal.');
        }
        apiResponse(true, $message, $data, 200);
    } catch (AppraisalRequestException $e) {
        if ($started) oci_rollback($sql___func___con);
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        if ($started) oci_rollback($sql___func___con);
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Unable to save the appraisal.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/**
 * The open task 46 the user can act on, with the appraiser row it belongs to and the appraisal.
 * The row is found by TASK_ID; older legacy rows by their ID (TRAN_CODE) or the employee's open row.
 */
function employeeAppraisalLoadTask($userTaskId, $sessionEmp)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new AppraisalRequestException('A valid task is required.', 422);
    }
    $task = appraisalRow("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'",
        [':id' => (string)$userTaskId, ':task_id' => (string)EMPLOYEE_APPRAISAL_TASK]);
    if (empty($task['ID'])) {
        throw new AppraisalRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new AppraisalRequestException('You are not authorized to act on this task.', 403);
    }
    $columns = "ID, APPR_ID, APPR_LEVEL, EMP_CODE, EMP_CODE_APPR, APPR_REMARKS, PROP_CTC, RECO_PROMO, STATUS, TASK_ID,
        TO_CHAR(NXT_APPR_DUE, 'YYYY-MM-DD') AS NXT_APPR_DUE";
    $manager = appraisalRow("SELECT {$columns} FROM HR_APPR_EMP_MGR WHERE TASK_ID = :task ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':task' => (string)$task['ID']]);
    if (empty($manager['ID']) && ctype_digit((string)$task['TRAN_CODE'])) {
        $manager = appraisalRow("SELECT {$columns} FROM HR_APPR_EMP_MGR WHERE ID = :id AND STATUS = 'I'", [':id' => $task['TRAN_CODE']]);
    }
    if (empty($manager['ID'])) {
        $manager = appraisalRow("SELECT {$columns} FROM HR_APPR_EMP_MGR WHERE EMP_CODE = :code AND STATUS = 'I'
            ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => (string)$task['TRAN_CODE']]);
    }
    if (empty($manager['ID'])) {
        throw new AppraisalRequestException('The appraisal of this task was not found.', 404);
    }
    $task['MANAGER'] = $manager;
    $task['APPRAISAL'] = appraisalLoad($manager['APPR_ID']);
    return $task;
}

/**
 * The next appraiser after $currentAppraiser in the employee's chain (legacy CloseEmpAppFIX):
 * the next holder of one of the employee organogram's appraisal levels who is in place.
 * Returns ['code' => ..., 'level' => ...] or null.
 */
function employeeAppraisalNext($employeeCode, $currentAppraiser)
{
    $office = appraisalRow("SELECT ORG_ID FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :code
        AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01') ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':code' => (string)$employeeCode]);
    $levels = [];
    foreach (multiRec("SELECT APPR_LEVEL, APPR_ORGID FROM HR_ORG_APPR_LEVELS WHERE ORG_ID = :org_id AND APPR_ORGID IS NOT NULL
        AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01') ORDER BY APPR_LEVEL",
        [':org_id' => (string)($office['ORG_ID'] ?? '')]) ?: [] as $level) {
        $levels[(string)$level['APPR_ORGID']] = (int)$level['APPR_LEVEL'];
    }
    $chainRow = appraisalRow("SELECT GET_EMP_PARENTAL(:code, SYSDATE) AS ORG FROM DUAL", [':code' => (string)$employeeCode]);
    $found = false;
    foreach (explode('#', rtrim((string)($chainRow['ORG'] ?? ''), '#')) as $entry) {
        $parts = explode(',', $entry);
        if (count($parts) !== 3) {
            continue;
        }
        [$org, , $holder] = $parts;
        if (!$found) {
            $found = $holder === (string)$currentAppraiser;
            continue;
        }
        if (isset($levels[$org]) && strcasecmp($holder, 'vacant') !== 0) {
            return ['code' => $holder, 'level' => $levels[$org]];
        }
    }
    return null;
}

/** Next Appraisal Due choices (legacy): April of the coming year and the ten after it. */
function employeeAppraisalDueOptions()
{
    $first = (int)date('n') > 4 ? (int)date('Y') + 1 : (int)date('Y');
    $options = [];
    for ($year = $first; $year <= $first + 10; $year++) {
        $options[] = ['value' => sprintf('%04d-04-01', $year), 'label' => "Apr-{$year}"];
    }
    return $options;
}
