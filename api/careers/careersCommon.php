<?php
/*
|--------------------------------------------------------------------------
| CAREERS (candidate portal) - COMMON
|--------------------------------------------------------------------------
| Public portal for job candidates (legacy hradmin/careers). Candidates are
| HR_CANDIDATE_INFO rows with their own login; nothing here uses employee
| sessions or HRMS profiles.
|
| - Own session cookie (SDLCAREERS), so a candidate login never mixes with an
|   employee login in the same browser.
| - No file here may be named session.php, db.php or logger.php: config/logger.php
|   includes 'db.php' and 'session.php' by relative name, which PHP resolves against
|   the working directory (this folder) first.
| - Every POST needs the X-CSRF-Token returned by session.php.
| - Passwords keep the legacy encoding (base64 + reverse, 5 times) so the old
|   careers site keeps working with the same accounts.
| - OTPs go by SMS (India) and email, like legacy login.php.
*/

const CAREERS_SESSION_NAME = 'SDLCAREERS';
const CAREERS_IDLE_SECONDS = 1800;          // candidate is logged out after 30 minutes idle
const CAREERS_OTP_TTL_SECONDS = 1800;       // legacy messages say "valid for 30 min"
const CAREERS_OTP_MAX_ATTEMPTS = 5;
const CAREERS_OTP_RESEND_SECONDS = 60;
const CAREERS_LOGIN_MAX_FAILURES = 10;      // per session, then a 15 minute pause
const CAREERS_LOGIN_LOCK_SECONDS = 900;
const CAREERS_APPLY_MIN_SCORE = 100;        // legacy inc/cand.php: profile must reach 100
const CAREERS_OPEN_JOB_STATUSES = "'N','I'";
const CAREERS_LEGACY_URL = 'https://hrms.sdlindia.com/hradmin/careers/';
// DLT templates of the SMS gateway: message texts must stay exactly as registered.
const CAREERS_SMS_TEMPLATE_REGISTER = '1007765778977790587';
const CAREERS_SMS_TEMPLATE_PASSWORD = '1077404250000927844';

class CareersException extends RuntimeException
{
}

require_once __DIR__ . '/../cors.php';

// Start the candidate session before the shared config: config/logger.php pulls in
// config/session.php, which would otherwise start the employee session first.
careersStartSession();

require_once __DIR__ . '/../config/db.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/utils.php';
require_once __DIR__ . '/../config/env.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!$sql___func___con) {
    apiResponse(false, 'Database connection failed.', null, 500);
    exit;
}

function careersStartSession()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(CAREERS_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!empty($_SESSION['careers']['candidateId'])) {
        $last = (int)($_SESSION['careers']['lastActivity'] ?? 0);
        if ($last > 0 && time() - $last >= CAREERS_IDLE_SECONDS) {
            unset($_SESSION['careers']);
        } else {
            $_SESSION['careers']['lastActivity'] = time();
        }
    }
    if (empty($_SESSION['careersCsrf'])) {
        $_SESSION['careersCsrf'] = bin2hex(random_bytes(32));
    }
}

function careersInput()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/** First row or []. multiRec keeps values as stored (singRec HTML-encodes). */
function careersRow($sql, $binds = [])
{
    $rows = multiRec($sql, $binds);
    return $rows[0] ?? [];
}

function careersRows($sql, $binds = [])
{
    return multiRec($sql, $binds) ?: [];
}

/** Bound write on the HRMS connection, inside the caller's transaction. Returns rows affected. */
function careersExecute($sql, $binds, $errorMessage)
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

/** Insert and return the trigger-generated ID. */
function careersInsert($sql, $binds, $errorMessage)
{
    global $sql___func___con;
    $stmt = oci_parse($sql___func___con, $sql . ' RETURNING ID INTO :new_id');
    if (!$stmt) {
        throw new RuntimeException($errorMessage);
    }
    foreach ($binds as $key => $value) {
        $binds[$key] = $value;
        oci_bind_by_name($stmt, $key, $binds[$key]);
    }
    $newId = '';
    oci_bind_by_name($stmt, ':new_id', $newId, 40);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        logOracleError($error, $sql);
        throw new RuntimeException($errorMessage);
    }
    oci_free_statement($stmt);
    return (string)$newId;
}

function careersRequireCsrf()
{
    $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
    $token = (string)($headers['x-csrf-token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['careersCsrf'] ?? ''), $token)) {
        throw new CareersException('Your session has expired. Please reload the page.', 419);
    }
}

/** Read endpoint (GET): $work returns [$message, $data]. */
function careersRunRead(callable $work, $logName)
{
    global $sql___func___con;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new CareersException('Invalid request method.', 405);
        }
        [$message, $data] = $work();
        apiResponse(true, $message, $data, 200);
    } catch (CareersException $e) {
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Something went wrong. Please try again.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/**
 * Write endpoint (POST, CSRF checked) in one HRMS transaction.
 * $work returns [$message, $data, $afterCommit?]; $afterCommit (SMS / email)
 * runs only once the data is committed and may return a message suffix.
 */
function careersRunAction(callable $work, $logName)
{
    global $sql___func___con;
    $transactionStarted = false;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new CareersException('Invalid request method.', 405);
        }
        careersRequireCsrf();
        startQry();
        $transactionStarted = true;
        $result = $work();
        [$message, $data] = $result;
        $afterCommit = $result[2] ?? null;
        if (($GLOBALS['qry_____result'] ?? 0) == 1) {
            throw new RuntimeException('A database update failed. Nothing was saved.');
        }
        if (!oci_commit($sql___func___con)) {
            throw new RuntimeException('Could not save your changes.');
        }
        $transactionStarted = false;
        if (is_callable($afterCommit)) {
            $message .= (string)$afterCommit();
        }
        if (careersOtpDebug() && !empty($GLOBALS['careersDebugOtp'])) {
            $data = (array)($data ?? []) + ['debugOtp' => $GLOBALS['careersDebugOtp']];
        }
        apiResponse(true, $message, $data, 200);
    } catch (CareersException $e) {
        if ($transactionStarted) oci_rollback($sql___func___con);
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        if ($transactionStarted) oci_rollback($sql___func___con);
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], $logName);
        apiResponse(false, 'Something went wrong. Please try again.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/** Legacy password encoding (hradmin/careers inc/hr_func.php encode). */
function careersEncodePassword($password)
{
    $value = (string)$password;
    for ($i = 0; $i < 5; $i++) {
        $value = strrev(base64_encode($value));
    }
    return $value;
}

function careersAssertLength($value, $maxBytes, $label)
{
    if (strlen((string)$value) > $maxBytes) {
        throw new CareersException("{$label} is too long (maximum {$maxBytes} characters).", 422);
    }
}

function careersRequired($value, $label, $maxBytes)
{
    $value = trim((string)$value);
    if ($value === '') {
        throw new CareersException("{$label} is required.", 422);
    }
    careersAssertLength($value, $maxBytes, $label);
    return $value;
}

function careersOptional($value, $label, $maxBytes)
{
    $value = trim((string)$value);
    careersAssertLength($value, $maxBytes, $label);
    return $value;
}

function careersIsoDate($value, $label, $required = true)
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) throw new CareersException("{$label} is required.", 422);
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new CareersException("{$label} is not a valid date.", 422);
    }
    return $value;
}

function careersValidEmail($value)
{
    $value = strtolower(trim((string)$value));
    if ($value === '' || strlen($value) > 100 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new CareersException('Please enter a valid email address.', 422);
    }
    return $value;
}

function careersValidMobile($value)
{
    $value = preg_replace('/\s+/', '', trim((string)$value));
    if (!preg_match('/^\+?[0-9]{10,15}$/', $value)) {
        throw new CareersException('Please enter a valid mobile number.', 422);
    }
    return $value;
}

/** The logged-in candidate (throws 401). $requireVerified: email (and India mobile) OTP confirmed. */
function careersCandidate($requireVerified = false)
{
    $candidateId = (int)($_SESSION['careers']['candidateId'] ?? 0);
    if ($candidateId <= 0) {
        throw new CareersException('Please log in to continue.', 401);
    }
    $candidate = careersRow("SELECT ID, CND_NAME, CND_EMAIL, CND_MOBILE, CND_COUNTRY, STATUS,
            ISEMAILVERIFIED, ISOTPVERIFIED
        FROM HR_CANDIDATE_INFO WHERE ID = :id", [':id' => (string)$candidateId]);
    if (empty($candidate['ID'])) {
        unset($_SESSION['careers']);
        throw new CareersException('Please log in to continue.', 401);
    }
    if ($requireVerified && !careersIsVerified($candidate)) {
        throw new CareersException('Please verify your email address and mobile number first.', 403);
    }
    return $candidate;
}

function careersIsIndia($candidate)
{
    return strcasecmp(trim((string)($candidate['CND_COUNTRY'] ?? '')), 'India') === 0
        || trim((string)($candidate['CND_COUNTRY'] ?? '')) === '';
}

/** Legacy verify_email_mob: India needs both OTPs, other countries the email OTP. */
function careersIsVerified($candidate)
{
    $email = (string)($candidate['ISEMAILVERIFIED'] ?? '') === '1';
    $mobile = (string)($candidate['ISOTPVERIFIED'] ?? '') === '1';
    return careersIsIndia($candidate) ? ($email && $mobile) : $email;
}

function careersLogin(array $candidate)
{
    session_regenerate_id(true);
    $_SESSION['careers'] = [
        'candidateId' => (int)$candidate['ID'],
        'lastActivity' => time(),
    ];
    $_SESSION['careersCsrf'] = bin2hex(random_bytes(32));
}

function careersSessionCandidate(array $candidate)
{
    return [
        'id' => $candidate['ID'],
        'name' => trim((string)$candidate['CND_NAME']),
        'email' => (string)$candidate['CND_EMAIL'],
        'mobile' => (string)$candidate['CND_MOBILE'],
        'country' => (string)($candidate['CND_COUNTRY'] ?? ''),
        'verified' => careersIsVerified($candidate),
        'mobileVerificationRequired' => careersIsIndia($candidate),
    ];
}

function careersNewOtp()
{
    return (string)random_int(100000, 999999);
}

/** Development switch (config/env.php): OTPs are returned instead of sent. */
function careersOtpDebug()
{
    return !empty($_ENV['CAREERS_OTP_DEBUG']);
}

/** Send one SMS through the DLT gateway. Never throws; returns success. */
function careersSendSms($mobile, $message, $templateId)
{
    if (careersOtpDebug()) {
        return true;
    }
    $mobile = preg_replace('/[^0-9]/', '', (string)$mobile);
    if (strlen($mobile) > 10) {
        $mobile = substr($mobile, -10);
    }
    if (empty($_ENV['SMS_GATEWAY_URL']) || empty($_ENV['SMS_USER']) || strlen($mobile) !== 10) {
        return false;
    }
    $url = $_ENV['SMS_GATEWAY_URL'] . '?' . http_build_query([
        'user' => $_ENV['SMS_USER'],
        'pwd' => $_ENV['SMS_PASSWORD'] ?? '',
        'sender' => $_ENV['SMS_SENDER'] ?? '',
        'mobile' => $mobile,
        'msg' => $message,
        'mt' => '0',
        'tempId' => $templateId,
    ], '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $status >= 400) {
        logOracleError(['message' => "SMS failed (HTTP {$status}): {$error}"], 'careers SMS');
        return false;
    }
    return true;
}

/**
 * Queue an email in the EPP mailbox (legacy bcs_bulk_mail into BCS_MAILBOX_EPP).
 * HRMS can only read those tables, so this uses its own EPP connection and
 * commits immediately. Never throws; returns success.
 */
function careersQueueMail($to, $subject, $body, $description = 'Careers')
{
    if (careersOtpDebug()) {
        return true;
    }
    $epp = db_epplive();
    if (!$epp) {
        logOracleError(['message' => 'EPP connection failed'], 'careers mail');
        return false;
    }
    try {
        $mail = oci_parse($epp, "INSERT INTO BCS_MAILBOX_EPP (SUBJECT, MAIL_BODY, STATUS, CHG_ON, CHG_BY, MAIL_DESCR)
            VALUES (:subject, :body, 'N', SYSDATE, 'CAREERS', :descr) RETURNING ID INTO :mail_id");
        $mailId = '';
        $subjectValue = substr((string)$subject, 0, 500);
        $bodyValue = substr((string)$body, 0, 4000);
        $descrValue = substr((string)$description, 0, 50);
        oci_bind_by_name($mail, ':subject', $subjectValue);
        oci_bind_by_name($mail, ':body', $bodyValue);
        oci_bind_by_name($mail, ':descr', $descrValue);
        oci_bind_by_name($mail, ':mail_id', $mailId, 40);
        if (!oci_execute($mail, OCI_NO_AUTO_COMMIT)) {
            throw new RuntimeException(oci_error($mail)['message'] ?? 'mail insert failed');
        }
        $details = oci_parse($epp, "INSERT INTO BCS_MAILBOX_EPP_DETAILS (MAIL_ID, EMAIL_TO, EMAIL_CC, EMAIL_BCC)
            VALUES (:mail_id, :email_to, NULL, NULL)");
        $toValue = (string)$to;
        oci_bind_by_name($details, ':mail_id', $mailId);
        oci_bind_by_name($details, ':email_to', $toValue);
        if (!oci_execute($details, OCI_NO_AUTO_COMMIT)) {
            throw new RuntimeException(oci_error($details)['message'] ?? 'mail recipient insert failed');
        }
        return oci_commit($epp);
    } catch (Throwable $e) {
        oci_rollback($epp);
        logOracleError(['message' => $e->getMessage()], 'careers mail');
        return false;
    } finally {
        oci_close($epp);
    }
}

/**
 * Profile completeness (legacy inc/cand.php, with its language check fixed).
 * Sections add up to 120; applying needs CAREERS_APPLY_MIN_SCORE.
 */
function careersCompleteness($candidateId)
{
    $id = (string)(int)$candidateId;
    $row = careersRow("SELECT
            CASE WHEN C.DOB IS NOT NULL AND C.GEND_ID IS NOT NULL AND C.CND_CITY IS NOT NULL THEN 1 ELSE 0 END AS PERSONAL,
            (SELECT COUNT(*) FROM HR_CAN_EDUCATION E WHERE E.CAN_ID = C.ID) AS EDUCATION,
            (SELECT COUNT(*) FROM HR_CAN_QUESTION_DETAILS Q WHERE Q.CAN_ID = TO_CHAR(C.ID)) AS GENERAL,
            CASE WHEN C.CUR_LOC IS NOT NULL AND C.PREF_LOC IS NOT NULL THEN 1 ELSE 0 END AS LOCATION,
            CASE WHEN C.CURR_CTC IS NOT NULL AND C.EXPT_CTC IS NOT NULL THEN 1 ELSE 0 END AS CTC,
            CASE WHEN C.DOC_PATH IS NOT NULL THEN 1 ELSE 0 END AS CV,
            (SELECT COUNT(*) FROM HR_CAN_FAMILY_INFO F WHERE F.CAN_ID = C.ID) AS FAMILY,
            (SELECT COUNT(*) FROM HR_CAN_SOCIALMEDIA S WHERE S.CAN_ID = TO_CHAR(C.ID)) AS SOCIAL,
            CASE WHEN C.WHY_1 IS NOT NULL THEN 1 ELSE 0 END AS WHY,
            (SELECT COUNT(*) FROM HR_CAN_LANGUAGE L WHERE L.CAN_ID = TO_CHAR(C.ID)) AS LANGUAGE
        FROM HR_CANDIDATE_INFO C WHERE C.ID = :id", [':id' => $id]);

    $weights = [
        'personal' => ['PERSONAL', 10], 'education' => ['EDUCATION', 10], 'general' => ['GENERAL', 10],
        'location' => ['LOCATION', 10], 'ctc' => ['CTC', 10], 'cv' => ['CV', 20],
        'family' => ['FAMILY', 10], 'social' => ['SOCIAL', 10], 'why' => ['WHY', 10], 'language' => ['LANGUAGE', 10],
    ];
    $score = 0;
    $sections = [];
    foreach ($weights as $key => [$column, $points]) {
        $done = (int)($row[$column] ?? 0) > 0;
        $sections[$key] = $done;
        if ($done) $score += $points;
    }
    return ['score' => $score, 'required' => CAREERS_APPLY_MIN_SCORE, 'sections' => $sections];
}

/** Keep only plain formatting tags of HR-written job descriptions; drop every attribute. */
function careersSafeHtml($html)
{
    $html = trim((string)$html);
    if ($html === '') {
        return '';
    }
    $allowed = ['p', 'br', 'ol', 'ul', 'li', 'b', 'strong', 'i', 'em', 'u', 'span', 'div', 'h4', 'h5', 'h6'];
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="careers-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $document->getElementById('careers-root');
    if (!$root) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }
    $clean = static function (DOMNode $node) use (&$clean, $allowed) {
        for ($child = $node->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                $clean($child);
                if (!in_array($tag, $allowed, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                while ($child->attributes->length > 0) {
                    $child->removeAttribute($child->attributes->item(0)->name);
                }
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $document->saveHTML($child);
    }
    return $out;
}

/** Plain text of legacy HTML-encoded values. */
function careersText($value)
{
    return trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8'));
}
