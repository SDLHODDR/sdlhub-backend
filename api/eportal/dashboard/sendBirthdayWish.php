<?php
/*
 * Send a birthday wish to a colleague (old ePortal: myspace/dashboard_util.php?sendbirthdaywish).
 *
 * POST { "emp_code": "04095", "message": "Happy birthday!" }
 *
 * Rules (see birthdayRules.php):
 *  - only to an active employee whose birthday is today or within the next WISH_ADVANCE_DAYS days
 *  - one wish per colleague per birthday; once sent it is read-only (as in the old ePortal)
 * Stored in EPT_USER_MESSAGES (ID filled by trigger BI_EPT_USER_MSG_SEQ).
 */

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/validateCsrf.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/birthdayRules.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    apiResponse(false, "Invalid request method", null, 405);
}

$empCode = $_SESSION["emp_code"] ?? null;

if (!$empCode) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

session_write_close();

const WISH_MAX_CHARS = 500; // MESSAGE is NVARCHAR2(1000 bytes) = 500 characters

try {

    $input = json_decode(file_get_contents("php://input"), true) ?: [];

    $wishFor = trim((string)($input["emp_code"] ?? ""));
    $message = trim((string)($input["message"] ?? ""));

    if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $wishFor)) {
        apiResponse(false, "Invalid employee.", null, 400);
    }

    if ($wishFor === $empCode) {
        apiResponse(false, "You cannot send a wish to yourself.", null, 400);
    }

    if ($message === "") {
        apiResponse(false, "Please write your wish.", null, 400);
    }

    if (mb_strlen($message, "UTF-8") > WISH_MAX_CHARS) {
        apiResponse(false, "Wish can be up to " . WISH_MAX_CHARS . " characters.", null, 400);
    }

    /*
     * The colleague must be active and have a birthday today or within the next few days.
     */
    $emp = singRec("
        SELECT TO_CHAR(" . wishWindowStartSql("BIRTH_DATE") . ", 'YYYY-MM-DD') AS WINDOW_START
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = :emp_code
          AND STATUS = 'A'
          AND BIRTH_DATE IS NOT NULL
          AND " . nextBirthdaySql("BIRTH_DATE") . " - TRUNC(SYSDATE) BETWEEN 0 AND " . WISH_ADVANCE_DAYS . "
    ", [":emp_code" => $wishFor]);

    if (empty($emp)) {
        apiResponse(false, "Wishes can be sent up to " . WISH_ADVANCE_DAYS . " days before the birthday.", null, 400);
    }

    /*
     * One wish per colleague per birthday.
     */
    $already = singRec("
        SELECT COUNT(*) CNT
        FROM EPT_USER_MESSAGES
        WHERE CREATED_BY = :created_by
          AND CREATED_FOR = :created_for
          AND CREATED_ON >= TO_DATE(:window_start, 'YYYY-MM-DD')
    ", [":created_by" => $empCode, ":created_for" => $wishFor, ":window_start" => $emp["WINDOW_START"]]);

    if ((int)($already["CNT"] ?? 0) > 0) {
        apiResponse(false, "You have already sent your wish.", null, 409);
    }

    /*
     * Save.
     */
    $sql = "
        INSERT INTO EPT_USER_MESSAGES (ID, CREATED_BY, CREATED_FOR, MESSAGE, CREATED_ON)
        VALUES (NULL, :created_by, :created_for, :message, SYSDATE)
    ";

    $stmt = oci_parse($sql___func___con, $sql);

    if (!$stmt) {
        $e = oci_error($sql___func___con);
        logOracleError($e, $sql);
        throw new Exception("Parse failed");
    }

    oci_bind_by_name($stmt, ":created_by", $empCode);
    oci_bind_by_name($stmt, ":created_for", $wishFor);
    oci_bind_by_name($stmt, ":message", $message, -1);

    if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
        $e = oci_error($stmt);
        logOracleError($e, $sql);
        oci_free_statement($stmt);
        throw new Exception("Insert failed");
    }

    oci_free_statement($stmt);

    apiResponse(true, "Your wish has been sent!", ["message" => $message]);

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to send your wish.", null, 500);

} finally {

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}
