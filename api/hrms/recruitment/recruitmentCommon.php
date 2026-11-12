<?php
/*
|--------------------------------------------------------------------------
| RECRUITMENT - COMMON
|--------------------------------------------------------------------------
| Candidate List, Recruitment Request and Pending Recruitment pages.
| Mirrors legacy candidate_info_sv.php / recruitment_sv.php.
| Every endpoint must call recruitmentRequirePage(): candidate records hold
| personal data, so a page is only open to profiles that have its menu entry.
*/

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/validateCsrf.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/emp_func.php';
require_once __DIR__ . '/../../config/hrmsAccess.php';

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

// HR_MENU_SUB.PROG_URL of each page: the SDL Hub path ("recuritment" is how it was
// spelled) and the old HRMS page name, since the menu table is shared with the old HRMS.
const RECRUITMENT_PAGE_CANDIDATES = ['recruitment/candidate_info_list', 'candidate_info_list.php'];
const RECRUITMENT_PAGE_REQUEST = ['recruitment/recruitment_req', 'recruitment_req.php'];
const RECRUITMENT_PAGE_PENDING = ['recruitment/pending_recuritment', 'pending_recruitments.php'];
const RECRUITMENT_ALL_PAGES = [RECRUITMENT_PAGE_CANDIDATES, RECRUITMENT_PAGE_REQUEST, RECRUITMENT_PAGE_PENDING];

// Legacy blocks a candidate only after this many follow-ups.
const RECRUITMENT_BLOCK_AFTER_FOLLOWUPS = 5;
// Legacy form limit (nod validation) for remarks.
const RECRUITMENT_REMARK_MAX = 100;

class RecruitmentRequestException extends RuntimeException
{
}

function recruitmentInput()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/** Stop unless the user's active profile has the menu entry of one of these pages. */
function recruitmentRequirePage($empCode, array $pages)
{
    $names = [];
    $binds = [':emp_code' => $empCode];
    // Each page is a list of its menu names (see RECRUITMENT_PAGE_*).
    $pages = array_values(array_unique(array_merge(...array_map(static fn($page) => (array)$page, $pages))));
    foreach ($pages as $index => $page) {
        $names[] = ":page{$index}";
        $binds[":page{$index}"] = $page;
    }
    $rows = multiRec("SELECT COUNT(*) AS CNT
        FROM HR_EMP_PROFILE EP
        INNER JOIN HR_PROFILE_MENU PM ON PM.PROFILE_ID = EP.PROFILE_ID
        INNER JOIN HR_MENU_SUB MS ON MS.ID = PM.SUB_MENU_ID AND MS.STATUS = 'A'
        WHERE EP.EMP_CODE = :emp_code
          AND SYSDATE BETWEEN EP.EFFEC_FROM AND NVL(EP.EFFEC_TO, DATE '3000-03-01')
          AND MS.PROG_URL IN (" . implode(',', $names) . ")", $binds);
    if ((int)($rows[0]['CNT'] ?? 0) === 0) {
        throw new RecruitmentRequestException('You do not have access to this page.', 403);
    }
}

/** First row or []. Uses multiRec because singRec HTML-encodes values and React escapes text itself. */
function recruitmentRow($sql, $binds = [])
{
    $rows = multiRec($sql, $binds);
    return $rows[0] ?? [];
}

function recruitmentAssertLength($value, $maxBytes, $label)
{
    if (strlen((string)$value) > $maxBytes) {
        throw new RecruitmentRequestException("{$label} is too long (maximum {$maxBytes} characters).", 422);
    }
}

function recruitmentAssertDate($value, $label)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RecruitmentRequestException("{$label} is required.", 422);
    }
}

function recruitmentExecute($sql, $binds, $errorMessage)
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

/** Runs a read endpoint: returns [$message, $data]. */
function recruitmentRunRead(callable $work, $logName)
{
    global $sql___func___con;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new RecruitmentRequestException('Invalid request method.', 405);
        }
        [$message, $data] = $work();
        apiResponse(true, $message, $data, 200);
    } catch (RecruitmentRequestException $e) {
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Unable to load the recruitment details.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/** Runs a write endpoint in one transaction: returns [$message, $data]. */
function recruitmentRunAction(callable $work, $logName)
{
    global $sql___func___con;
    $transactionStarted = false;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new RecruitmentRequestException('Invalid request method.', 405);
        }
        startQry();
        $transactionStarted = true;
        [$message, $data] = $work();
        endQry();
        apiResponse(true, $message, $data, 200);
    } catch (RecruitmentRequestException $e) {
        if ($transactionStarted) oci_rollback($sql___func___con);
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        if ($transactionStarted) oci_rollback($sql___func___con);
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Unable to save the recruitment details.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/** A candidate must exist; returns the row. */
function recruitmentCandidate($canId)
{
    $canId = (int)$canId;
    if ($canId <= 0) {
        throw new RecruitmentRequestException('A valid candidate is required.', 422);
    }
    $row = recruitmentRow("SELECT ID, CND_NAME, CND_MOBILE, CND_EMAIL, STATUS FROM HR_CANDIDATE_INFO WHERE ID = :id",
        [':id' => (string)$canId]);
    if (empty($row['ID'])) {
        throw new RecruitmentRequestException('Candidate not found.', 404);
    }
    return $row;
}

/** Same page of the candidate list for the grid and for its export. */
function recruitmentCandidatePage($start, $length, $search)
{
    $start = max(0, (int)$start);
    $length = (int)$length;
    if ($length <= 0 || $length > 100) {
        $length = 10;
    }
    $search = trim((string)$search);

    $where = '';
    $binds = [];
    if ($search !== '') {
        $where = "WHERE (UPPER(C.CND_NAME) LIKE :search OR UPPER(C.CND_EMAIL) LIKE :search OR C.CND_MOBILE LIKE :search)";
        $binds[':search'] = '%' . strtoupper($search) . '%';
    }

    $total = recruitmentRow("SELECT COUNT(*) AS CNT FROM HR_CANDIDATE_INFO");
    $filtered = $search === ''
        ? $total
        : recruitmentRow("SELECT COUNT(*) AS CNT FROM HR_CANDIDATE_INFO C {$where}", $binds);

    $rows = multiRec("SELECT C.ID, C.CND_NAME, C.CND_MOBILE, C.CND_EMAIL, C.DOC_PATH, C.STATUS,
            TO_CHAR(C.CHG_ON, 'DD-Mon-YYYY') AS CHG_ON,
            (SELECT COUNT(*) FROM HR_CAN_FOLLOWUP F WHERE F.CAN_ID = TO_CHAR(C.ID)) AS FOLLOW_CNT
        FROM HR_CANDIDATE_INFO C
        {$where}
        ORDER BY C.CHG_ON DESC NULLS LAST, C.ID DESC
        OFFSET " . $start . " ROWS FETCH NEXT " . $length . " ROWS ONLY", $binds) ?: [];

    $number = $start;
    return [
        'total' => (int)($total['CNT'] ?? 0),
        'filtered' => (int)($filtered['CNT'] ?? 0),
        'rows' => array_map(static function ($row) use (&$number) {
            $number++;
            return [
                'rowNumber' => $number,
                'id' => $row['ID'],
                'name' => $row['CND_NAME'] ?? '',
                'mobile' => $row['CND_MOBILE'] ?? '',
                'email' => $row['CND_EMAIL'] ?? '',
                'date' => $row['CHG_ON'] ?? '',
                'docPath' => $row['DOC_PATH'] ?? '',
                'status' => $row['STATUS'] ?? '',
                'followUpCount' => (int)($row['FOLLOW_CNT'] ?? 0),
            ];
        }, $rows),
    ];
}

/**
 * Candidates attached to a set of requests, grouped by request id.
 * $requestFilter is trusted SQL selecting HR_RECRUITMENT.ID values.
 */
function recruitmentRequestCandidates($requestFilter, $binds, $onlyWithStatus)
{
    $rows = multiRec("SELECT A.REQ_ID, A.CAN_ID, A.STATUS, B.CND_NAME, B.CND_EMAIL, B.CND_MOBILE, B.DOC_PATH,
            B.STATUS AS CANDIDATE_STATUS,
            (SELECT COUNT(*) FROM HR_CAN_FOLLOWUP F WHERE F.CAN_ID = TO_CHAR(A.CAN_ID)) AS FOLLOW_CNT
        FROM HR_CAN_APPLICATION_DETAILS A
        INNER JOIN HR_CANDIDATE_INFO B ON B.ID = A.CAN_ID
        WHERE A.REQ_ID IN ({$requestFilter})" . ($onlyWithStatus ? " AND A.STATUS IS NOT NULL" : "") . "
        ORDER BY A.REQ_ID, A.ID", $binds) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['REQ_ID']][] = [
            'id' => $row['CAN_ID'],
            'name' => $row['CND_NAME'] ?? '',
            'email' => $row['CND_EMAIL'] ?? '',
            'mobile' => $row['CND_MOBILE'] ?? '',
            'docPath' => $row['DOC_PATH'] ?? '',
            'applicationStatus' => $row['STATUS'] ?? '',
            'status' => $row['CANDIDATE_STATUS'] ?? '',
            'followUpCount' => (int)($row['FOLLOW_CNT'] ?? 0),
        ];
    }
    return $grouped;
}

/**
 * Locations of an organogram that can still be requested: not held by a current
 * employee and not in another open request, plus this request's own location.
 * Legacy recruitment_sv.php?populate_org_loc. A request in C, R or I frees its
 * location as in legacy; X (cancelled) is added so cancelling frees it too.
 */
function recruitmentOpenLocations($orgId, $requestId)
{
    return multiRec("SELECT HOL.ID, HOL.ID || ' - ' || HOL.GEO_DESC AS LABEL
        FROM HR_ORGANOGRAM_LOC HOL
        WHERE HOL.ORG_ID = :org_id
          AND SYSDATE BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
          AND NOT EXISTS (SELECT 1 FROM HR_EMP_OFFICE_DET D
                WHERE D.ORG_LOC_ID = HOL.ID AND D.ORG_ID = :org_id
                  AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01'))
          AND NOT EXISTS (SELECT 1 FROM HR_RECRUITMENT R
                WHERE R.ORG_LOC_ID = HOL.ID AND R.ORG_ID = :org_id AND R.ID <> :rec_id
                  AND R.STATUS NOT IN ('C', 'R', 'I', 'X'))
        UNION
        SELECT HOL.ID, HOL.ID || ' - ' || HOL.GEO_DESC AS LABEL
        FROM HR_ORGANOGRAM_LOC HOL
        INNER JOIN HR_RECRUITMENT R ON R.ORG_LOC_ID = HOL.ID AND R.ID = :rec_id
        WHERE HOL.ORG_ID = :org_id
        ORDER BY 2", [':org_id' => (string)(int)$orgId, ':rec_id' => (string)(int)$requestId]) ?: [];
}

/** SQL condition: organogram (alias) inside the user's profile scope. Call loadHrmsAccess() first. */
function recruitmentOrgScopeSql($alias)
{
    $in = static fn($values) => implode(',', array_map('intval', $values ?: [-1]));
    return "{$alias}.COMPANY IN (" . $in($_SESSION['compId'] ?? []) . ")"
        . " AND {$alias}.DIVSN_ID IN (" . $in($_SESSION['divId'] ?? []) . ")"
        . " AND {$alias}.DEPT_ID IN (" . $in($_SESSION['deptId'] ?? []) . ")";
}

// HR_TASK_MASTER: the recruitment tasks that have a screen in SDL Hub, and the request status each one acts on.
const RECRUITMENT_TASK_AUTHORIZE = 2;
const RECRUITMENT_TASK_ASSIGN = 5;
const RECRUITMENT_TASK_REQUEST_STATUS = [RECRUITMENT_TASK_AUTHORIZE => 'T', RECRUITMENT_TASK_ASSIGN => 'A'];
// Legacy recruitment_req.php: recruiters are the employees holding this profile.
const RECRUITMENT_RECRUITER_PROFILE_ID = '3';

/**
 * Load an open recruitment task the user may act on, with its request.
 * Task access comes from the profile (hrmsCanActOnTask), not from a page menu.
 */
function recruitmentLoadTask($workflowTaskId, $userTaskId, $empCode)
{
    $workflowTaskId = (int)$workflowTaskId;
    $userTaskId = (int)$userTaskId;
    if (!isset(RECRUITMENT_TASK_REQUEST_STATUS[$workflowTaskId]) || $userTaskId <= 0) {
        throw new RecruitmentRequestException('A valid task is required.', 422);
    }

    loadHrmsAccess($empCode);
    $task = recruitmentRow("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'", [
        ':id' => (string)$userTaskId,
        ':task_id' => (string)$workflowTaskId,
    ]);
    if (empty($task['ID'])) {
        throw new RecruitmentRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $empCode)) {
        throw new RecruitmentRequestException('You are not authorized to open this task.', 403);
    }

    $request = recruitmentRow("SELECT HR.ID, HR.STATUS, HR.REC_TITLE, HR.REMARKS, HR.AUTH_REMARK, HR.REQ_COUNT, HR.POSI_COUNT, HR.FILL_COUNT,
            HR.DIVSN_ID, HR.DEPT_ID, O.COMPANY, HR.ENTER_BY_EMP,
            TO_CHAR(HR.REQ_DATE, 'DD-Mon-YYYY') AS REQ_DATE, TO_CHAR(HR.REQ_BY_DATE, 'DD-Mon-YYYY') AS REQ_BY_DATE,
            GET_SHCOMP_NAME(O.COMPANY) || ' - ' || GET_DIVISION_NAME(O.DIVSN_ID) || ' - ' || GET_DEPT_NAME(O.DEPT_ID) || ' - ' || GET_DESIGN_NAME(O.DESI_ID) AS ORGANOGRAM,
            GET_DEPT_NAME(O.DEPT_ID) || '-' || GET_DESIGN_NAME(O.DESI_ID) AS POSITION,
            HOL.GEO_DESC,
            TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' ')) AS RAISED_BY
        FROM HR_RECRUITMENT HR
        INNER JOIN HR_ORGANOGRAM O ON O.ID = HR.ORG_ID
        LEFT JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
        LEFT JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = HR.ENTER_BY_EMP
        WHERE HR.ID = :id", [':id' => (string)$task['TRAN_CODE']]);
    if (empty($request['ID'])) {
        throw new RecruitmentRequestException('The recruitment request for this task was not found.', 404);
    }
    if ($request['STATUS'] !== RECRUITMENT_TASK_REQUEST_STATUS[$workflowTaskId]) {
        throw new RecruitmentRequestException('This recruitment request has already been processed.', 409);
    }

    return [$task, $request];
}

/** Close a task; a second user acting on the same task is refused. */
function recruitmentCloseTask($userTaskId, $empCode)
{
    $closed = recruitmentExecute("UPDATE HR_USER_TASKS SET STATUS = 'C', AUTH_ON = SYSDATE, AUTH_BY = :auth_by
        WHERE ID = :id AND STATUS = 'O'", [':id' => (string)(int)$userTaskId, ':auth_by' => $empCode], 'Could not close the task.');
    if ($closed !== 1) {
        throw new RecruitmentRequestException('The task is no longer open.', 409);
    }
}

function recruitmentRecruiters()
{
    return multiRec("SELECT DISTINCT HE.EMP_CODE,
            TRIM(REGEXP_REPLACE(HE.FNAME || ' ' || HE.MNAME || ' ' || HE.LNAME, ' +', ' ')) || ' (' || HE.EMP_CODE || ')' AS LABEL
        FROM HR_EMPLOYEE_INFO HE
        INNER JOIN HR_EMP_PROFILE HP ON HP.EMP_CODE = HE.EMP_CODE
        WHERE HP.PROFILE_ID = :profile_id AND HE.STATUS = 'A'
          AND SYSDATE BETWEEN HP.EFFEC_FROM AND NVL(HP.EFFEC_TO, DATE '3000-03-01')
        ORDER BY 2", [':profile_id' => RECRUITMENT_RECRUITER_PROFILE_ID]) ?: [];
}

/** Legacy convertToLacs: plain rupees become lakhs; anything else is shown as entered. */
function recruitmentToLakhs($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $digits = str_replace(',', '', $value);
    if (ctype_digit($digits) && strlen($digits) > 2) {
        return (string)round((int)$digits / 100000, 2);
    }
    return $value;
}
