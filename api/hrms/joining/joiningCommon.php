<?php
/*
|--------------------------------------------------------------------------
| JOINING WORKFLOW - COMMON
|--------------------------------------------------------------------------
| New Employee (Manual) page -> Send for Authorization (task 45 EMP_AUTH)
| -> Authorize (employee active + joining detail tasks).
| Mirrors legacy employee_info_sv2.php (saveEmp, saveOfficeDet,
| sendEmpAuth, AuthorizeEmp). Every joining task's TRAN_CODE is the
| employee code.
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

const JOINING_TASK_EMP_AUTH = 45;
// Joining tasks completed on the Employee Information page: task ID -> tab.
// Document Upload (13) and Appointment Letter (44) have their own screens.
const JOINING_DETAIL_SECTIONS = [
    '34' => 'tenure',
    '35' => 'basic',
    '36' => 'bank',
    '37' => 'ctc',
    '38' => 'qualification',
    '39' => 'experience',
    '41' => 'family',
    '42' => 'access',
    '43' => 'kra',
];
// Joining tasks go to Human Resource (legacy dept 35).
const JOINING_HR_DEPT_ID = 35;

// Employee code series per company (legacy saveEmp): companies sharing a
// series are locked together while a code is generated.
const JOINING_CODE_SERIES = [
    '1' => ['prefix' => '0', 'companies' => [1, 7]],
    '7' => ['prefix' => '0', 'companies' => [1, 7]],
    '2' => ['prefix' => '9', 'companies' => [2, 4]],
    '4' => ['prefix' => '9', 'companies' => [2, 4]],
    '6' => ['prefix' => '7', 'companies' => [6]],
    '3' => ['prefix' => '8', 'companies' => [3]],
];

// Tasks generated when the new employee is authorized (legacy AuthorizeEmp).
const JOINING_DETAIL_TASKS = [
    'EMP_TENURE_DET' => 'Tenure Details',
    'EMP_BASIC_DET' => 'Basic Details',
    'EMP_BANK_DET' => 'Bank Details',
    'EMP_CTC_DET' => 'Ctc Details',
    'EMP_QUAL_DET' => 'Qualification Details',
    'EMP_EXP_DET' => 'Experience Details',
    'EMP_FAM_DET' => 'Family Details',
    'EMP_APP_DET' => 'App Access Details',
    'EMP_KRA_DET' => 'KRA Details',
    'DOC_REQ' => 'Document Upload',
    'PRT_APPT_LTR' => 'Appointment Letter',
];

class JoiningRequestException extends RuntimeException
{
}

function joiningInput()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/** singRec() HTML-encodes values; React escapes text itself. */
function joiningRecord($sql, $binds = [])
{
    return array_map(
        static fn($value) => html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'),
        singRec($sql, $binds) ?: []
    );
}

/** multiRec() returns values as stored (no HTML encoding). */
function joiningRecords($sql, $binds = [])
{
    return multiRec($sql, $binds) ?: [];
}

/** Oracle VARCHAR2 limits are in bytes. */
function joiningAssertLength($value, $maxBytes, $label)
{
    if (strlen((string)$value) > $maxBytes) {
        throw new JoiningRequestException("{$label} is too long (maximum {$maxBytes} characters).", 422);
    }
}

function joiningIsoDate($value, $label, $required = true)
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) throw new JoiningRequestException("{$label} is required.", 422);
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new JoiningRequestException("{$label} is not a valid date.", 422);
    }
    return $value;
}

/** Company IDs in the user's HRMS profile scope (loadHrmsAccess first). */
function joiningUserCompanies()
{
    return array_map('strval', $_SESSION['compId'] ?? []);
}

function joiningAssertCompanyScope($companyId)
{
    if (!in_array((string)$companyId, joiningUserCompanies(), true)) {
        throw new JoiningRequestException('You are not authorized for this company.', 403);
    }
}

/** The employee being created, with its status. */
function joiningLoadEmployee($employeeCode)
{
    $employeeCode = trim((string)$employeeCode);
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $employeeCode)) {
        throw new JoiningRequestException('A valid employee code is required.', 422);
    }
    $employee = joiningRecord("SELECT ID, EMP_CODE, STATUS, COMP_ID, FNAME, MNAME, LNAME,
            TO_CHAR(DOJ, 'YYYY-MM-DD') AS DOJ_ISO
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    if (empty($employee['ID'])) {
        throw new JoiningRequestException('Employee not found.', 404);
    }
    return $employee;
}

/** Current office row of the employee (new joiners have one). */
function joiningEmployeeOffice($employeeCode)
{
    return joiningRecord("SELECT * FROM (
            SELECT O.ID, O.DIVSN_ID, O.DEPT_ID, O.DESI_ID, O.ORG_ID, O.ORG_LOC_ID,
                TO_CHAR(O.EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM_ISO,
                TO_CHAR(O.EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO_ISO,
                G.COMPANY AS ORG_COMPANY, G.DIVSN_ID AS ORG_DIVSN_ID
            FROM HR_EMP_OFFICE_DET O
            LEFT JOIN HR_ORGANOGRAM G ON G.ID = O.ORG_ID
            WHERE O.EMP_CODE = :emp_code
            ORDER BY O.EFFEC_FROM DESC, O.ID DESC)
        WHERE ROWNUM = 1", [':emp_code' => (string)$employeeCode]);
}

function joiningEmployeeName(array $employee)
{
    return trim(preg_replace('/\s+/', ' ', implode(' ', [
        $employee['FNAME'] ?? '', $employee['MNAME'] ?? '', $employee['LNAME'] ?? '',
    ])));
}

function joiningExecute($sql, $binds, $errorMessage)
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

function joiningGenerateTask($taskGroup, $employeeCode, $description, $sessionEmp, $companyId, $divisionId, $deptId)
{
    $taskId = generateTaskHR($taskGroup, $employeeCode, $description, '', 'A', 'O', '',
        $sessionEmp, $companyId, $divisionId, $deptId);
    if (empty($taskId)) {
        throw new RuntimeException("Could not create the {$taskGroup} task.");
    }
    return $taskId;
}

/**
 * Create the EPP employee, EPP login and central login rows when missing, or
 * refresh the EPP employee's personal fields. Logins start with the legacy
 * default password P<employee code>; an existing password is never reset.
 * $values holds uppercase names, lowercase emails and YYYY-MM-DD dates.
 */
function joiningSyncEppAndLogins($employeeCode, array $values, $status, $sessionEmp)
{
    $binds = [
        ':emp_code' => $employeeCode,
        ':fname' => $values['fname'],
        ':mname' => $values['mname'],
        ':lname' => $values['lname'],
        ':prefix' => $values['title'],
        ':sex' => $values['gender'],
        ':dob' => $values['dob'],
        ':doj' => $values['doj'],
        ':mobile' => $values['cell'],
        ':email_per' => $values['per_email'],
        ':email_off' => $values['com_email'],
        ':chg_by' => $sessionEmp,
    ];
    $eppEmployee = singRec("SELECT EMP_CODE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    if (empty($eppEmployee['EMP_CODE'])) {
        joiningExecute("INSERT INTO HR_BCS_EMPLOYEE
                (EMP_CODE, EMP_FNAME, EMP_MNAME, EMP_LNAME, NAME_PREFIX, SEX, BIRTH_DATE, DATE_JOIN, STATUS,
                 MOBILE_NO, EMAIL_ID_PER, EMAIL_ID_OFF, CHG_ON, CHG_BY)
            VALUES (:emp_code, :fname, :mname, :lname, :prefix, :sex, TO_DATE(:dob, 'YYYY-MM-DD'),
                 TO_DATE(:doj, 'YYYY-MM-DD'), :status, :mobile, :email_per, :email_off, SYSDATE, :chg_by)",
            $binds + [':status' => $status], 'Could not create the EPP employee record.');
    } else {
        joiningExecute("UPDATE HR_BCS_EMPLOYEE
            SET EMP_FNAME = :fname, EMP_MNAME = :mname, EMP_LNAME = :lname, NAME_PREFIX = :prefix, SEX = :sex,
                BIRTH_DATE = TO_DATE(:dob, 'YYYY-MM-DD'), DATE_JOIN = TO_DATE(:doj, 'YYYY-MM-DD'),
                MOBILE_NO = :mobile, EMAIL_ID_PER = :email_per, EMAIL_ID_OFF = :email_off,
                CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE EMP_CODE = :emp_code", $binds, 'Could not update the EPP employee record.');
    }

    $name = trim(preg_replace('/\s+/', ' ', "{$values['fname']} {$values['mname']} {$values['lname']}"));
    $password = encode('P' . $employeeCode);

    $eppUser = singRec("SELECT CODE FROM HR_BCS_USERS WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    if (empty($eppUser['CODE'])) {
        joiningExecute("INSERT INTO HR_BCS_USERS
                (CODE, EMP_CODE, NAME, PASS_WD, PASSWD_FREQ, STATUS, VALID_UPTO, CHG_DATE, CHG_USER)
            VALUES (:emp_code, :emp_code, :name, :pass_wd, 30, :status, SYSDATE + 30, SYSDATE, :chg_by)", [
            ':emp_code' => $employeeCode,
            ':name' => substr($name, 0, 40),
            ':pass_wd' => $password,
            ':status' => $status,
            ':chg_by' => $sessionEmp,
        ], 'Could not create the EPP login.');
    }

    // Our login page checks this table; it stays N until the employee is authorized.
    $login = singRec("SELECT EMP_CODE FROM SDLUSERS.SDL_USERS WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    if (empty($login['EMP_CODE'])) {
        joiningExecute("INSERT INTO SDLUSERS.SDL_USERS
                (LOGIN_CODE, EMP_CODE, NAME, PASS_WD, STATUS, MOBILE_NO, PASSWD_FREQ, VALID_UPTO, CHG_ON, CHG_BY)
            VALUES (:emp_code, :emp_code, :name, :pass_wd, 'N', :mobile, 30, SYSDATE + 30, SYSDATE, :chg_by)", [
            ':emp_code' => $employeeCode,
            ':name' => substr($name, 0, 100),
            ':pass_wd' => $password,
            ':mobile' => substr((string)$values['cell'], 0, 13),
            ':chg_by' => $sessionEmp,
        ], 'Could not create the SDL login.');
    }
}

/** Run $work in one transaction; respond with its result or the error. */
function joiningRunAction(callable $work, $logName)
{
    global $sql___func___con;
    $transactionStarted = false;
    try {
        startQry();
        $transactionStarted = true;
        [$message, $data] = $work();
        // A failed legacy helper (execQry / generateTaskHR) flags qry_____result
        // and endQry() would silently roll back: treat it as an error instead.
        if (($GLOBALS['qry_____result'] ?? 0) == 1) {
            throw new RuntimeException('A database update failed. Nothing was saved.');
        }
        endQry();
        apiResponse(true, $message, $data, 200);
    } catch (JoiningRequestException $e) {
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
