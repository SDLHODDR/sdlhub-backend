<?php
/*
|--------------------------------------------------------------------------
| EXIT WORKFLOW - COMMON
|--------------------------------------------------------------------------
| Separation (18) -> Exit Interview (20) -> Exit Activity per department (19)
| + Deactivate Employee (23) -> Relieving & Experience Letter (24).
| Every task's TRAN_CODE is HR_EMP_SEPARATION.ID. Mirrors legacy
| separation_sv.php / exit_act_sv.php.
*/

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../cors.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/validateCsrf.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/utils.php';
require_once __DIR__ . '/../../../config/emp_func.php';
require_once __DIR__ . '/../../../config/hrmsAccess.php';

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

const EXIT_TASK_SEPARATION = 18;
const EXIT_TASK_ACTIVITY = 19;
const EXIT_TASK_INTERVIEW = 20;
const EXIT_TASK_DEACTIVATE = 23;
const EXIT_TASK_LETTER = 24;
// Exit Interview, Deactivate and Letter tasks go to Human Resource (legacy dept 35).
const EXIT_HR_DEPT_ID = 35;

class ExitRequestException extends RuntimeException
{
}

function exitInput()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/**
 * singRec() HTML-encodes every value; React escapes text itself, so decode
 * records whose text is returned to the frontend.
 */
function exitRecord($sql, $binds = [])
{
    return array_map(
        static fn($value) => html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'),
        singRec($sql, $binds) ?: []
    );
}

/** Oracle VARCHAR2 limits are in bytes. */
function exitAssertLength($value, $maxBytes, $label)
{
    if (strlen((string)$value) > $maxBytes) {
        throw new ExitRequestException("{$label} is too long (maximum {$maxBytes} characters).", 422);
    }
}

/**
 * SQL condition limiting an employee to the user's profile scope: company on
 * HR_EMPLOYEE_INFO ($employeeAlias), division / department on
 * HR_EMP_OFFICE_DET ($officeAlias). Call loadHrmsAccess() first.
 */
/**
 * Condition on HR_EMP_SEPARATION (alias $alias) for a separation that blocks a new
 * one: not rejected, not closed with the employee retained at the Exit Interview
 * (RETENTION_FLAG 'Y': they stay, so a new separation may be raised later), and not an
 * exit completed before the employee's current office began, which means they have
 * been rehired since.
 */
function exitBlockingSeparationSql($alias)
{
    return "{$alias}.STATUS <> 'R' AND NOT ({$alias}.STATUS = 'C' AND {$alias}.RETENTION_FLAG = 'Y')
        AND NOT ({$alias}.STATUS = 'C' AND {$alias}.RETENTION_FLAG = 'N'
        AND NVL({$alias}.RELEIVING_DATE, DATE '3000-03-01') < NVL((SELECT MAX(O.EFFEC_FROM) FROM HR_EMP_OFFICE_DET O
            WHERE O.EMP_CODE = {$alias}.EMP_CODE
              AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')), DATE '1900-01-01'))";
}

function exitScopeSql($officeAlias, $employeeAlias)
{
    $in = static fn($values) => implode(',', array_map('intval', $values ?: [-1]));
    return "{$employeeAlias}.COMP_ID IN (" . $in($_SESSION['compId'] ?? []) . ")"
        . " AND {$officeAlias}.DIVSN_ID IN (" . $in($_SESSION['divId'] ?? []) . ")"
        . " AND {$officeAlias}.DEPT_ID IN (" . $in($_SESSION['deptId'] ?? []) . ")";
}

/**
 * Load an open exit task the user may act on, with its separation record.
 */
function exitLoadTask($workflowTaskId, $userTaskId, $sessionEmp)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new ExitRequestException('A valid task is required.', 422);
    }

    loadHrmsAccess($sessionEmp);
    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS
        WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'", [
        ':id' => (string)$userTaskId,
        ':task_id' => (string)(int)$workflowTaskId,
    ]);
    if (empty($task['ID'])) {
        throw new ExitRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new ExitRequestException('You are not authorized to open this task.', 403);
    }

    $separation = exitRecord("SELECT ID, EMP_CODE, RESIGN_TYPE, RESIGN_MODE, RESIGN_RCP_EMAIL,
            TO_CHAR(RESIGN_RCP_DATE, 'DD-Mon-YYYY') AS RESIGN_RCP_DATE,
            TO_CHAR(EXP_RELIV_DATE, 'DD-Mon-YYYY') AS EXP_RELIV_DATE,
            TO_CHAR(EXP_RELIV_DATE, 'YYYY-MM-DD') AS EXP_RELIV_DATE_ISO,
            TO_CHAR(RELEIVING_DATE, 'DD-Mon-YYYY') AS RELEIVING_DATE,
            TO_CHAR(RELEIVING_DATE, 'YYYY-MM-DD') AS RELEIVING_DATE_ISO,
            EMP_COMMENTS, STATUS, AUTH_COMMENTS, EXIT_COMMENTS, RETENTION_FLAG, HANDOVER_TO,
            TO_CHAR(ENTER_ON_EMP, 'YYYY-MM-DD') AS ENTER_ON_ISO
        FROM HR_EMP_SEPARATION WHERE ID = :id", [':id' => (string)$task['TRAN_CODE']]);
    if (empty($separation['ID'])) {
        throw new ExitRequestException('The separation request for this task was not found.', 404);
    }

    return [$task, $separation];
}

/**
 * The employee's office/organogram as on a date (YYYY-MM-DD), falling back to
 * the latest office row (legacy getEmpOfficeDated).
 */
function exitEmployeeOffice($employeeCode, $isoDate = '')
{
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$isoDate) ? $isoDate : date('Y-m-d');
    $select = "SELECT GET_DEPT_NAME(A.DEPT_ID) AS DEPT, GET_DESIGN_NAME(A.DESI_ID) AS DESIG,
            GET_ORG_LOC_NAME(A.ORG_LOC_ID) AS LOC, A.ORG_ID, A.DIVSN_ID, A.DEPT_ID,
            C.COMPANY, C.DIVSN_ID AS ORG_DIVSN_ID, C.JD_ID
        FROM HR_EMP_OFFICE_DET A
        INNER JOIN HR_ORGANOGRAM C ON C.ID = A.ORG_ID
        WHERE A.EMP_CODE = :emp_code";
    $office = singRec("SELECT * FROM ({$select}
            AND TO_DATE(:as_on, 'YYYY-MM-DD') BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
            ORDER BY A.EFFEC_FROM DESC) WHERE ROWNUM = 1",
        [':emp_code' => (string)$employeeCode, ':as_on' => $date]);
    if (empty($office['ORG_ID'])) {
        $office = singRec("SELECT * FROM ({$select} ORDER BY A.EFFEC_FROM DESC) WHERE ROWNUM = 1",
            [':emp_code' => (string)$employeeCode]);
    }
    return $office ?: [];
}

function exitSeparationDate(array $separation)
{
    return $separation['RELEIVING_DATE_ISO'] ?: $separation['EXP_RELIV_DATE_ISO'];
}

function exitEmployeeSummary(array $separation, array $office)
{
    $info = exitRecord("SELECT COM_EMAIL FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code",
        [':emp_code' => (string)$separation['EMP_CODE']]);
    return [
        'code' => $separation['EMP_CODE'],
        'name' => getEmpInfoByCodeHR($separation['EMP_CODE']),
        'department' => $office['DEPT'] ?? '',
        'designation' => $office['DESIG'] ?? '',
        'location' => $office['LOC'] ?? '',
        'email' => $info['COM_EMAIL'] ?? '',
    ];
}

/**
 * Exit interview questions configured for the JD (group X), with options
 * and saved answers for the separation.
 */
function exitInterviewQuestions($jdId, $separationId)
{
    if (empty($jdId)) {
        return [];
    }
    $rows = multiRec("SELECT A.QUESTION_ID, A.DISP_SEQ, B.QUESTION, B.RATING_TYPE, G.QSGRP_DESC
        FROM HR_JD_QUESTIONS A
        INNER JOIN HR_QUESTION_MASTER B ON B.ID = A.QUESTION_ID
        LEFT JOIN HR_QUESTION_SGROUP G ON G.QSGRP_ID = B.QGRP_ID
        WHERE A.JD_ID = :jd_id AND A.QGRP_TYPE = 'X'
        ORDER BY B.QGRP_ID, A.DISP_SEQ", [':jd_id' => (string)$jdId]);
    $answers = [];
    foreach (multiRec("SELECT QUES_ID, EMP_ANS_TEXT FROM HR_EMP_EXIT_INTRV WHERE REF_ID = :ref_id",
        [':ref_id' => (string)$separationId]) ?: [] as $answer) {
        $answers[(string)$answer['QUES_ID']] = (string)($answer['EMP_ANS_TEXT'] ?? '');
    }

    $questions = [];
    foreach ($rows ?: [] as $row) {
        $id = (string)$row['QUESTION_ID'];
        $type = strtoupper((string)$row['RATING_TYPE']);
        $options = $type === 'R' || $type === 'C'
            ? array_column(multiRec("SELECT OPTS_TEXT FROM HR_QUESTION_OPTS WHERE QUESTION_ID = :id ORDER BY ID",
                [':id' => $id]) ?: [], 'OPTS_TEXT')
            : [];
        $questions[] = [
            'id' => $id,
            'disp_seq' => $row['DISP_SEQ'],
            'subgroup' => strtoupper((string)($row['QSGRP_DESC'] ?: 'GENERAL')),
            'question' => ucwords(strtolower((string)$row['QUESTION'])),
            'input_type' => $type === 'R' ? 'radio' : ($type === 'C' ? 'checkbox' : 'text'),
            'options' => $options,
            'answer' => $answers[$id] ?? '',
        ];
    }
    return $questions;
}

/**
 * Exit activities of a department (ACT_TYPE E) with saved status/remarks.
 */
function exitDepartmentActivities($deptId, $separationId)
{
    $rows = multiRec("SELECT A.ID AS DEPT_ACT_ID, A.ACT_DESC, A.DEPT_ID, A.ACT_TYPE, A.DISP_SEQ,
            D.STATUS, D.REMARKS
        FROM HR_DEPT_JOEX_ACTIVITY A
        LEFT JOIN HR_EMP_DEPT_ACTIVITY D
            ON D.DEPT_ACT_ID = A.ID AND D.DEPT_ID = A.DEPT_ID AND D.REF_ID = :ref_id
        WHERE A.DEPT_ID = :dept_id AND A.ACT_TYPE = 'E'
        ORDER BY A.DISP_SEQ, A.ID", [
        ':ref_id' => (string)$separationId,
        ':dept_id' => (string)$deptId,
    ]);
    return array_map(static fn($row) => [
        'id' => (string)$row['DEPT_ACT_ID'],
        'activity' => $row['ACT_DESC'],
        'deptId' => $row['DEPT_ID'],
        'dispSeq' => $row['DISP_SEQ'],
        'status' => $row['STATUS'] ?? '',
        'remarks' => $row['REMARKS'] ?? '',
    ], $rows ?: []);
}

function exitGenerateTask($taskGroup, array $separation, $description, $sessionEmp, $companyId, $divisionId, $deptId)
{
    $taskId = generateTaskHR($taskGroup, $separation['ID'], $description, '', 'A', 'O', '',
        $sessionEmp, $companyId, $divisionId, $deptId);
    if (empty($taskId)) {
        throw new RuntimeException("Could not create the {$taskGroup} task.");
    }
    return $taskId;
}

function exitCloseTask($userTaskId, $remark = '')
{
    taskUpdateHR('C', $remark, $userTaskId, null, null);
    if (($GLOBALS['qry_____result'] ?? 0) == 1) {
        throw new RuntimeException('Could not close the current task.');
    }
}

function exitExecute($sql, $binds, $errorMessage)
{
    global $sql___func___con;
    $stmt = oci_parse($sql___func___con, $sql);
    if (!$stmt) {
        throw new RuntimeException($errorMessage);
    }
    foreach ($binds as $key => $value) {
        $binds[$key] = $value;
        oci_bind_by_name($stmt, $key, $binds[$key]);
    }
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        logOracleError($error, $sql);
        throw new RuntimeException($errorMessage);
    }
    $count = oci_num_rows($stmt);
    oci_free_statement($stmt);
    return $count;
}

/**
 * Run $work in one transaction; respond with its result or the error.
 */
function exitRunAction(callable $work, $logName)
{
    global $sql___func___con;
    $transactionStarted = false;
    try {
        startQry();
        $transactionStarted = true;
        [$message, $data] = $work();
        endQry();
        apiResponse(true, $message, $data, 200);
    } catch (ExitRequestException $e) {
        if ($transactionStarted) oci_rollback($sql___func___con);
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        if ($transactionStarted) oci_rollback($sql___func___con);
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, $e->getMessage(), null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/*
|--------------------------------------------------------------------------
| RELIEVING & EXPERIENCE LETTERS
|--------------------------------------------------------------------------
*/
// HR_DOC_TYPES: 3 = Relieving Letter, 4 = Experience Certificate.
const EXIT_LETTERS = [
    3 => ['name' => 'Relieving Letter', 'file' => 'Relieving_Letter', 'remark' => 'RELIEVING'],
    4 => ['name' => 'Experience Letter', 'file' => 'Experience_Letter', 'remark' => 'EXPERIENCE LETTER'],
];

/**
 * This separation's letter documents (latest per doc type issued on/after the
 * request was raised, so letters from an earlier employment are ignored).
 */
function exitLetterDocs($employeeCode, $sinceIso)
{
    $docs = [];
    foreach (array_keys(EXIT_LETTERS) as $docId) {
        $doc = singRec("SELECT * FROM (
                SELECT ID, DOC_REF, DOC_PATH, TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS DOC_DATE
                FROM HR_EMP_DOCS
                WHERE EMP_CODE = :emp_code AND DOC_ID = :doc_id AND DOC_REF IS NOT NULL
                  AND CHG_ON >= NVL(TO_DATE(:since, 'YYYY-MM-DD'), DATE '1900-01-01')
                ORDER BY ID DESC)
            WHERE ROWNUM = 1", [
            ':emp_code' => (string)$employeeCode,
            ':doc_id' => (string)$docId,
            ':since' => (string)$sinceIso,
        ]);
        $docs[$docId] = !empty($doc['ID']) ? $doc : null;
    }
    return $docs;
}

/**
 * Next outward letter reference: MMYY/<user work site>/<employee dept short code>/LTTR/<seq>
 * (legacy relieving_letter.php / experience_letter.php).
 */
function exitNextLetterReference($sessionEmp, $employeeDeptId)
{
    $user = singRec("SELECT WORK_SITE, DEPT_CODE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :emp_code",
        [':emp_code' => (string)$sessionEmp]);
    $workSite = trim((string)($user['WORK_SITE'] ?? ''));
    if ($workSite === '' || !preg_match('/^[A-Za-z0-9_]+$/', $workSite)) {
        throw new ExitRequestException('Your employee account lacks a valid Work Site in EPP.', 422);
    }
    $period = singRec("SELECT DOC_PREFIX FROM HR_BCS_ACCT_PERIOD
        WHERE CODE = (SELECT ACCT_PRD FROM HR_BCS_PERIOD WHERE TRUNC(SYSDATE) BETWEEN FR_DATE AND TO_DATE)");
    $prefix = trim((string)($period['DOC_PREFIX'] ?? ''));
    if ($prefix === '' || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        throw new ExitRequestException('No active financial accounting period / prefix found.', 422);
    }
    $dept = singRec("SELECT SHORT_CODE FROM HR_DEPARTMENT WHERE DEPT_ID = :dept_id", [':dept_id' => (string)$employeeDeptId]);
    $sequence = singRec("SELECT EPPLIVE.TRAN_OR_{$workSite}_{$prefix}_SEQ.NEXTVAL AS SEQNO FROM DUAL");
    if (empty($sequence['SEQNO'])) {
        throw new RuntimeException("Letter sequence EPPLIVE.TRAN_OR_{$workSite}_{$prefix}_SEQ is not available.");
    }
    return [
        'ref' => date('my') . '/' . $workSite . '/' . ($dept['SHORT_CODE'] ?? '') . '/LTTR/' . $sequence['SEQNO'],
        'workSite' => $workSite,
        'userDeptCode' => $user['DEPT_CODE'] ?? '',
    ];
}
