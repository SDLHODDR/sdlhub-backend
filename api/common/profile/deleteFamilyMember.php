<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       READ INPUT
    =========================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request data.", null, 400);
    }

    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        apiResponse(false, "Family member ID is required.", null, 400);
    }

    $empCodeEsc = str_replace("'", "''", $empCode);

    /* ===========================================
       SOFT DELETE
    =========================================== */

    startQry();

    executeQry("
        UPDATE EPT_HR_EMP_FAMILY_INFO
        SET
            STATUS = 'd',
            CHG_ON = SYSDATE,
            CHG_BY = '{$empCodeEsc}'
        WHERE ID = {$id}
          AND EMP_CODE = '{$empCodeEsc}'
    ", 1);

    if ($qry_____result != 0) {
        forceRollback("Failed to delete family member.");
    }

    endQry();

    apiResponse(true, "Family member deleted successfully.");

} catch (Throwable $e) {

    forceRollback("Delete family member failed.");

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "deleteFamilyMember.php"
    );

    apiResponse(false, "Unable to delete family member.", null, 500);
}