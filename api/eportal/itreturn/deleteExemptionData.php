<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* =====================================================
       METHOD VALIDATION
    ===================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* =====================================================
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION["emp_code"] ?? "";

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* =====================================================
       READ INPUT
    ===================================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request payload.");
    }

    $exemptionId = trim($data["exemption_id"] ?? "");

    if (empty($exemptionId)) {
        apiResponse(false, "Exemption ID is required.");
    }

    /* =====================================================
       GET EMPLOYEE ID
    ===================================================== */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    ");

    $empId = $employee["ID"] ?? null;

    if (empty($empId)) {
        apiResponse(false, "Employee not found.");
    }

    /* =====================================================
       VERIFY RECORD
    ===================================================== */

    $existingRecord = singRec("
        SELECT ID
        FROM EPT_BCS_ITAX_EXEMPTION
        WHERE ID = '$exemptionId'
        AND EMP_ID = '$empId'
    ");

    if (empty($existingRecord)) {
        apiResponse(false, "Exemption record not found.");
    }

    /* =====================================================
       DELETE RECORD
    ===================================================== */

    startQry();

    executeQry("
        DELETE FROM EPT_BCS_ITAX_EXEMPTION
        WHERE ID = '$exemptionId'
        AND EMP_ID = '$empId'
    ");

    endQry("Exemption Deleted");

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(true, "Exemption record deleted successfully.");

} catch (Throwable $e) {

    logOracleError($e);
    apiResponse(false, "Unable to delete exemption record.", null, 500);
}