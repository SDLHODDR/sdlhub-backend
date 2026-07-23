<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

require_once __DIR__ . "/../config/logger.php";

header("Content-Type: application/json");

try {

    /* ==========================================================
       SESSION VALIDATION
    ========================================================== */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired", null, 401);
    }

    $empCode = $_SESSION["emp_code"];

    /* ==========================================================
       READ INPUT
    ========================================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    $policyId = $data["policy_id"] ?? 0;

    if (empty($policyId)) {
        apiResponse(false, "Invalid policy", null, 400);
    }

    /* ==========================================================
       CHECK POLICY ALREADY ACCEPTED
    ========================================================== */

    $checkSql = "
        SELECT COUNT(*) CNT
        FROM EPT_USER_POLICY_VIEW_LOG
        WHERE POLICY_ID = :policy_id
        AND EMP_CODE = :emp_code
    ";

    $checkStmt = oci_parse($sql___func___con, $checkSql);

    if (!$checkStmt) {

        $e = oci_error($sql___func___con);

        logOracleError($e, $checkSql);

        apiResponse(false, "Unable to process request.", null, 500);
    }

    oci_bind_by_name($checkStmt, ":policy_id", $policyId);
    oci_bind_by_name($checkStmt, ":emp_code", $empCode);

    if (!oci_execute($checkStmt)) {

        $e = oci_error($checkStmt);

        logOracleError($e, $checkSql);

        apiResponse(false, "Unable to process request.", null, 500);
    }

    $checkRow = oci_fetch_assoc($checkStmt);

    oci_free_statement($checkStmt);

    if (($checkRow["CNT"] ?? 0) > 0) {

        apiResponse(true, "Policy already accepted.");
    }

    /* ==========================================================
       INSERT POLICY ACCEPTANCE
    ========================================================== */

    $sql = "
        INSERT INTO EPT_USER_POLICY_VIEW_LOG
        (
            ID,
            POLICY_ID,
            EMP_CODE,
            ACCEPTED_ON,
            IP_ADDR,
            USER_AGENT,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            EPT_USER_POLICY_VIEW_LOG_SEQ.NEXTVAL,
            :policy_id,
            :emp_code,
            SYSDATE,
            :ip_addr,
            :user_agent,
            SYSDATE,
            :chg_by
        )
    ";

    $stmt = oci_parse($sql___func___con, $sql);

    if (!$stmt) {

        $e = oci_error($sql___func___con);

        logOracleError($e, $sql);

        apiResponse(false, "Unable to save policy acceptance.", null, 500);
    }

    $ipAddr = $_SERVER["REMOTE_ADDR"] ?? "";
    $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

    oci_bind_by_name($stmt, ":policy_id", $policyId);
    oci_bind_by_name($stmt, ":emp_code", $empCode);
    oci_bind_by_name($stmt, ":ip_addr", $ipAddr);
    oci_bind_by_name($stmt, ":user_agent", $userAgent);
    oci_bind_by_name($stmt, ":chg_by", $empCode);

    if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {

        $e = oci_error($stmt);
        logOracleError($e, $sql);
        oci_free_statement($stmt);
        apiResponse(false, "Unable to save policy acceptance.", null, 500);
    }

    oci_free_statement($stmt);

    apiResponse(true, "Policy accepted successfully");

} catch (Throwable $e) {

    writeErrorLog($e->getMessage());

    apiResponse(false, "Internal Server Error", null, 500);

} finally {

    if (isset($stmt) && is_resource($stmt)) {
        @oci_free_statement($stmt);
    }

    if (isset($checkStmt) && is_resource($checkStmt)) {
        @oci_free_statement($checkStmt);
    }

    if (isset($sql___func___con) && is_resource($sql___func___con)) {
        @oci_close($sql___func___con);
    }

}
