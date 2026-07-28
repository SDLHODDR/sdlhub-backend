<?php

// error_reporting(E_ALL);
// ini_set('display_errors', 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /*
    |--------------------------------------------------------------------------
    | METHOD CHECK
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method", null, 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION CHECK
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    $empCode = $_SESSION["emp_code"];

    /*
    |--------------------------------------------------------------------------
    | READ INPUT
    |--------------------------------------------------------------------------
    */

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        apiResponse(false, "Invalid input data.");
    }

    $regime = strtoupper(trim($input["regime"] ?? ""));

    if (!in_array($regime, ["N", "O"])) {
        apiResponse(false, "Invalid Regime Selected.");
    }

    /*
    |--------------------------------------------------------------------------
    | GET EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '".$empCode."'
    ");

    $empId = $employee["ID"] ?? null;

    if (!$empId) {
        apiResponse(false, "Employee Not Found", null, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT FINANCIAL YEAR
    |--------------------------------------------------------------------------
    */

    $acctPeriod = singRec("
        SELECT CODE
        FROM EPT_BCS_ACCT_PERIOD
        WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
    ");

    $fy = $acctPeriod["CODE"] ?? "";

    if (empty($fy)) {
        apiResponse(false, "Financial Year Not Found");
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING RECORD
    |--------------------------------------------------------------------------
    */

    $checkSql = "
        SELECT ID
        FROM EPT_BCS_ITAX_EMP_REGIME
        WHERE EMP_ID = :emp_id
        AND FY = :fy
    ";

    $checkStmt = oci_parse($sql___func___con, $checkSql);

    if (!$checkStmt) {
        throw new Exception(oci_error($sql___func___con)["message"]);
    }

    oci_bind_by_name($checkStmt, ":emp_id", $empId);
    oci_bind_by_name($checkStmt, ":fy", $fy);

    if (!oci_execute($checkStmt)) {
        throw new Exception(oci_error($checkStmt)["message"]);
    }

    $existing = oci_fetch_assoc($checkStmt);

    oci_free_statement($checkStmt);

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    if (!empty($existing)) {

        $sql = "
            UPDATE EPT_BCS_ITAX_EMP_REGIME
            SET
                REGIME = :regime,
                CHG_BY = :chg_by,
                CHG_ON = SYSDATE
            WHERE
                EMP_ID = :emp_id
            AND FY = :fy
        ";

        $stmt = oci_parse($sql___func___con, $sql);

        if (!$stmt) {
            throw new Exception(oci_error($sql___func___con)["message"]);
        }

        oci_bind_by_name($stmt, ":regime", $regime);
        oci_bind_by_name($stmt, ":chg_by", $empCode);
        oci_bind_by_name($stmt, ":emp_id", $empId);
        oci_bind_by_name($stmt, ":fy", $fy);

        $message = "Regime Updated Successfully";
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    else {

        $sql = "
            INSERT INTO EPT_BCS_ITAX_EMP_REGIME
            (
                ID,
                EMP_ID,
                REGIME,
                CHG_ON,
                CHG_BY,
                FY
            )
            VALUES
            (
                EPT_BCS_ITAX_EMP_REGIME_SEQ.NEXTVAL,
                :emp_id,
                :regime,
                SYSDATE,
                :chg_by,
                :fy
            )
        ";

        $stmt = oci_parse($sql___func___con, $sql);

        if (!$stmt) {
            throw new Exception(oci_error($sql___func___con)["message"]);
        }

        oci_bind_by_name($stmt, ":emp_id", $empId);
        oci_bind_by_name($stmt, ":regime", $regime);
        oci_bind_by_name($stmt, ":chg_by", $empCode);
        oci_bind_by_name($stmt, ":fy", $fy);

        $message = "Regime Saved Successfully";
    }

    /*
    |--------------------------------------------------------------------------
    | EXECUTE
    |--------------------------------------------------------------------------
    */

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        throw new Exception(oci_error($stmt)["message"]);
    }

    oci_commit($sql___func___con);

    apiResponse(true, $message, [
        "emp_id" => $empId,
        "fy" => $fy,
        "regime" => $regime
    ]);

} catch (Throwable $e) {

    oci_rollback($sql___func___con);

    if (function_exists("logOracleError")) {
        logOracleError($e);
    }

    apiResponse(false, "Unable to save regime.", null, 500);

} finally {

    if (isset($stmt) && $stmt) {
        oci_free_statement($stmt);
    }

    if (isset($checkStmt) && $checkStmt) {
        oci_free_statement($checkStmt);
    }
}