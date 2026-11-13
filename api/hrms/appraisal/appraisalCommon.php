<?php
/*
|--------------------------------------------------------------------------
| APPRAISAL SETUP - COMMON
|--------------------------------------------------------------------------
| Legacy appraisal_setup.php / appraisal_setup_sv.php. An appraisal (HR_APPRAISAL_SETUP) gets
| questions (HR_APPR_QUESTION) and organogram employees (HR_APPR_ORG_EMP) while New ('N');
| Publish ('P') gives each employee an appraiser (HR_APPR_EMP_MGR), an Employee Appraisal task (46)
| and the question rows to answer (HR_APPR_EMP_APPR); Close sets 'C'.
| Also serves Appraisal Status (appraisal_status.php) and Final Appraisal (final_appraisal.php).
| Every endpoint calls appraisalRequirePage(): a page is open only to profiles holding its menu entry.
*/

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/validateCsrf.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';
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

// HR_MENU_SUB.PROG_URL of each Appraisal page: the old HRMS page and the SDL Hub path.
// An endpoint defines APPRAISAL_PAGE ('setup' by default) before requiring this file.
const APPRAISAL_PAGES = [
    'setup' => ['appraisal_setup.php', 'appraisal/appraisal-setup'],
    'status' => ['appraisal_status.php', 'appraisal/appraisal-status'],
    'final' => ['final_appraisal.php', 'appraisal/final-appraisal'],
];

// Legacy appraisal_setup.php arrays.
const APPRAISAL_TYPES = ['Q' => 'Quarterly', 'H' => 'Half Yearly', 'Y' => 'Yearly', 'A' => 'Ad-hoc'];
const APPRAISAL_BASES = ['F' => 'Fixed Format', 'K' => 'Employee KRA base'];
const APPRAISAL_STATUSES = ['N' => 'New', 'P' => 'Publish', 'C' => 'Closed'];
// Months covered by each type; Ad-hoc takes the Effective To entered.
const APPRAISAL_TYPE_MONTHS = ['Q' => 3, 'H' => 6, 'Y' => 12];
// Employees are added only with one of these tenures (legacy: Permanent, Contractual).
const APPRAISAL_EMP_TYPES = ['1', '2'];
const APPRAISAL_DESCR_MAX = 100;

class AppraisalRequestException extends RuntimeException
{
}

function appraisalInput()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/** First row or []. multiRec does not HTML-encode (singRec does). */
function appraisalRow($sql, $binds = [])
{
    $rows = multiRec($sql, $binds);
    return $rows[0] ?? [];
}

function appraisalRequirePage($empCode)
{
    $pages = APPRAISAL_PAGES[defined('APPRAISAL_PAGE') ? APPRAISAL_PAGE : 'setup'];
    $row = appraisalRow("SELECT COUNT(*) AS CNT
        FROM HR_EMP_PROFILE EP
        INNER JOIN HR_PROFILE_MENU PM ON PM.PROFILE_ID = EP.PROFILE_ID
        INNER JOIN HR_MENU_SUB MS ON MS.ID = PM.SUB_MENU_ID AND MS.STATUS = 'A'
        WHERE EP.EMP_CODE = :emp_code
          AND SYSDATE BETWEEN EP.EFFEC_FROM AND NVL(EP.EFFEC_TO, DATE '3000-03-01')
          AND LOWER(MS.PROG_URL) IN (:page0, :page1)",
        [':emp_code' => $empCode, ':page0' => $pages[0], ':page1' => $pages[1]]);
    if ((int)($row['CNT'] ?? 0) === 0) {
        throw new AppraisalRequestException('You do not have access to this page.', 403);
    }
}

function appraisalExecute($sql, $binds, $errorMessage)
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

/** Bound INSERT; $sysdate lists columns set to SYSDATE. Returns the trigger-assigned ID. */
function appraisalInsert($table, array $data, array $sysdate = [])
{
    global $sql___func___con;
    $columns = array_merge(array_keys($data), $sysdate);
    $values = array_merge(array_map(static fn($column) => ':v_' . strtolower($column), array_keys($data)),
        array_fill(0, count($sysdate), 'SYSDATE'));
    $stmt = oci_parse($sql___func___con, "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ("
        . implode(', ', $values) . ") RETURNING ID INTO :new_id");
    $binds = [];
    foreach ($data as $column => $value) {
        $binds[':v_' . strtolower($column)] = $value === null ? '' : (string)$value;
    }
    foreach ($binds as $key => $value) {
        oci_bind_by_name($stmt, $key, $binds[$key]);
    }
    $newId = '';
    oci_bind_by_name($stmt, ':new_id', $newId, 40);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        logOracleError($error, "INSERT INTO {$table}");
        throw new RuntimeException("Could not save into {$table}.");
    }
    oci_free_statement($stmt);
    return $newId;
}

/** Runs a read endpoint: $work returns [$message, $data]. */
function appraisalRunRead(callable $work, $logName)
{
    global $sql___func___con, $empCode;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new AppraisalRequestException('Invalid request method.', 405);
        }
        appraisalRequirePage($empCode);
        loadHrmsAccess($empCode);
        session_write_close();
        [$message, $data] = $work();
        apiResponse(true, $message, $data, 200);
    } catch (AppraisalRequestException $e) {
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Unable to load the appraisal details.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/** Runs a write endpoint in one transaction: $work returns [$message, $data]. */
function appraisalRunAction(callable $work, $logName)
{
    global $sql___func___con, $empCode;
    $started = false;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new AppraisalRequestException('Invalid request method.', 405);
        }
        appraisalRequirePage($empCode);
        loadHrmsAccess($empCode);
        startQry();
        $started = true;
        [$message, $data] = $work();
        if (!oci_commit($sql___func___con)) {
            throw new RuntimeException('Could not commit the appraisal changes.');
        }
        apiResponse(true, $message, $data, 200);
    } catch (AppraisalRequestException $e) {
        if ($started) oci_rollback($sql___func___con);
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        if ($started) oci_rollback($sql___func___con);
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Unable to save the appraisal details.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/** An appraisal row (dates as YYYY-MM-DD and display text) or a 404. */
function appraisalLoad($id)
{
    $id = (int)$id;
    if ($id <= 0) {
        throw new AppraisalRequestException('A valid appraisal is required.', 422);
    }
    $row = appraisalRow("SELECT ID, DESCR, COMP_ID, APPR_TYPE, APPR_BASE, APPR_DAYS, STATUS,
            TO_CHAR(EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM, TO_CHAR(EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO,
            TO_CHAR(INCR_EFFEC_FROM, 'YYYY-MM-DD') AS INCR_EFFEC_FROM,
            TO_CHAR(PUBLISH_DATE, 'DD-Mon-YYYY') AS PUBLISH_DATE, TO_CHAR(CLOSE_DATE, 'DD-Mon-YYYY') AS CLOSE_DATE
        FROM HR_APPRAISAL_SETUP WHERE ID = :id", [':id' => (string)$id]);
    if (empty($row['ID'])) {
        throw new AppraisalRequestException('Appraisal not found.', 404);
    }
    return $row;
}

/** Stop unless the appraisal is still New (questions and employees can change only then). */
function appraisalRequireNew(array $appraisal)
{
    if ($appraisal['STATUS'] !== 'N') {
        throw new AppraisalRequestException('This appraisal has already been published and can no longer be changed.', 409);
    }
}

/** SQL condition: organogram (alias) in the user's division / department scope. Call loadHrmsAccess() first. */
function appraisalOrgScopeSql($alias)
{
    $in = static fn($values) => implode(',', array_map('intval', $values ?: [-1]));
    return "{$alias}.DIVSN_ID IN (" . $in($_SESSION['divId'] ?? []) . ") AND {$alias}.DEPT_ID IN (" . $in($_SESSION['deptId'] ?? []) . ")";
}

/**
 * Appraisers of an employee (legacy appraisal_setup.php / publishAppraisal). The appraisal levels of the
 * employee's current organogram (HR_ORG_APPR_LEVELS) name parent organograms; the person holding each in
 * the employee's reporting chain (GET_EMP_PARENTAL: "parent org,location,employee#...") is the appraiser.
 * Returns ['levels' => [[level, empCode|null]], 'first' => empCode|null], first being the nearest
 * appraiser in the chain who is in place (the one a published appraisal goes to).
 */
function appraisalAppraisers($empCode)
{
    $office = appraisalRow("SELECT ORG_ID FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :code
        AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01') ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':code' => (string)$empCode]);
    $levels = multiRec("SELECT APPR_LEVEL, APPR_ORGID FROM HR_ORG_APPR_LEVELS WHERE ORG_ID = :org_id
        AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01') ORDER BY APPR_LEVEL",
        [':org_id' => (string)($office['ORG_ID'] ?? '')]) ?: [];
    $chainRow = appraisalRow("SELECT GET_EMP_PARENTAL(:code, SYSDATE) AS ORG FROM DUAL", [':code' => (string)$empCode]);
    $chain = [];
    foreach (explode('#', rtrim((string)($chainRow['ORG'] ?? ''), '#')) as $entry) {
        $parts = explode(',', $entry);
        if (count($parts) === 3) {
            $chain[] = ['org' => $parts[0], 'emp' => strcasecmp($parts[2], 'vacant') === 0 ? null : $parts[2]];
        }
    }
    $levelOrgs = array_column($levels, 'APPR_ORGID');
    $first = null;
    foreach ($chain as $link) {
        if ($link['emp'] !== null && in_array($link['org'], $levelOrgs, true)) {
            $first = $link['emp'];
            break;
        }
    }
    $list = [];
    foreach ($levels as $level) {
        $holder = null;
        foreach ($chain as $link) {
            if ($link['org'] === $level['APPR_ORGID']) {
                $holder = $link['emp'];
                break;
            }
        }
        $list[] = [(int)$level['APPR_LEVEL'], $holder];
    }
    return ['levels' => $list, 'first' => $first];
}

/** Title-case names of employee codes, keyed by code. */
function appraisalEmployeeNames(array $codes)
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
    $names = [];
    foreach (array_chunk($codes, 500) as $chunk) {
        $binds = [];
        foreach ($chunk as $index => $code) {
            $binds[":c{$index}"] = $code;
        }
        foreach (multiRec("SELECT EMP_CODE, INITCAP(TRIM(FNAME || ' ' || LNAME)) AS NM FROM HR_EMPLOYEE_INFO
            WHERE EMP_CODE IN (" . implode(',', array_keys($binds)) . ")", $binds) ?: [] as $row) {
            $names[$row['EMP_CODE']] = $row['NM'];
        }
    }
    return $names;
}
