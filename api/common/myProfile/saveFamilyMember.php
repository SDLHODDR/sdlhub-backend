<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access", null, 401);
    }

    /* ===========================================
       READ INPUT
    =========================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request data.", null, 400);
    }

    $id         = (int)($data['id'] ?? 0);
    $name       = trim($data['name'] ?? '');
    $relation   = trim($data['relation'] ?? '');
    $dob        = trim($data['dob'] ?? '');
    $aadhaar    = trim($data['aadhaar'] ?? '');
    $dependent  = trim($data['dependent'] ?? '');
    $occupation = trim($data['occupation'] ?? '');

    if ($name === '' || $relation === '') {
        apiResponse(false, "Name and Relation are required.", null, 400);
    }

    /* ===========================================
       CALCULATE AGE
    =========================================== */

    $age = '';

    if (!empty($dob)) {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
    }

    /* ===========================================
       ESCAPE VALUES
    =========================================== */

    $nameEsc       = str_replace("'", "''", ucfirst($name));
    $relationEsc   = str_replace("'", "''", $relation);
    $aadhaarEsc    = str_replace("'", "''", $aadhaar);
    $dependentEsc  = str_replace("'", "''", $dependent);
    $occupationEsc = str_replace("'", "''", $occupation);
    $empCodeEsc    = str_replace("'", "''", $empCode);

    startQry();

    /* ===========================================
       UPDATE
    =========================================== */

    if ($id > 0) {

        executeQry("
            UPDATE EPT_HR_EMP_FAMILY_INFO
            SET
                FM_NAME = '{$nameEsc}',
                FM_RELATION = '{$relationEsc}',
                DOB = TO_DATE('{$dob}','DD-MON-YYYY'),
                AADHAAR = '{$aadhaarEsc}',
                FM_DEP = '{$dependentEsc}',
                FM_OCCUPATION = '{$occupationEsc}',
                AGE = '{$age}',
                CHG_ON = SYSDATE,
                CHG_BY = '{$empCodeEsc}'
            WHERE ID = {$id}
        ");

        if ($qry_____result != 0) {
            forceRollback("Failed to update family member.");
        }

        endQry();

        apiResponse(true, "Family member updated successfully.");
    }

    /* ===========================================
       INSERT
    =========================================== */

    executeQry("
        INSERT INTO EPT_HR_EMP_FAMILY_INFO
        (
            EMP_CODE,
            FM_NAME,
            FM_RELATION,
            FM_DEP,
            DOB,
            AADHAAR,
            FM_OCCUPATION,
            AGE,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '{$empCodeEsc}',
            '{$nameEsc}',
            '{$relationEsc}',
            '{$dependentEsc}',
            TO_DATE('{$dob}','DD-MON-YYYY'),
            '{$aadhaarEsc}',
            '{$occupationEsc}',
            '{$age}',
            SYSDATE,
            '{$empCodeEsc}'
        )
    ");

    if ($qry_____result != 0) {
        forceRollback("Failed to add family member.");
    }

    endQry();

    apiResponse(true, "Family member added successfully.");

} catch (Throwable $e) {

    forceRollback("Save family member failed.");

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveFamilyMember.php"
    );

    apiResponse(false, "Unable to save family member.", null,500);
}