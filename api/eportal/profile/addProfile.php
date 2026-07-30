<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

$conn = db_eportal();

if (!$conn) {
    apiResponse(false, "Database connection failed.", null, 500);
}

/*
|--------------------------------------------------------------------------
| Required by singRec(), multiRec(), executeQry()
|--------------------------------------------------------------------------
*/
$sql___func___con = $conn;

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

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $profileName = trim($input['profileName'] ?? '');
    $description = trim($input['description'] ?? '');

    if ($profileName === '') {
        apiResponse(false, "Profile Name is required.");
    }

    /* ===========================================
       CHECK DUPLICATE PROFILE
    =========================================== */

    $profileNameEsc = str_replace("'", "''", $profileName);

    $duplicate = singRec("
        SELECT COUNT(*) CNT
        FROM EPT_PROFILES
        WHERE UPPER(PROFILE_DESC) = UPPER('{$profileNameEsc}')
    ");

    if (($duplicate['CNT'] ?? 0) > 0) {
        apiResponse(false, "Profile already exists.");
    }

    /* ===========================================
       INSERT PROFILE
    =========================================== */

    startQry();

    $descriptionEsc = str_replace("'", "''", $description);
    $empCodeEsc = str_replace("'", "''", $empCode);

    $profileId = executeQry(
        "
        INSERT INTO EPT_PROFILES
        (
            PROFILE_ID,
            PROFILE_DESC,
            PROFILE_DETAIL,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            NULL,
            '{$profileNameEsc}',
            '{$descriptionEsc}',
            'A',
            SYSDATE,
            '{$empCodeEsc}'
        )
        RETURNING PROFILE_ID INTO :newId
        ",
        "newId"
    );

    endQry();

    apiResponse(
        true,
        "Profile created successfully.",
        [
            "profile_id" => $profileId
        ]
    );

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "addProfile.php"
    );

    apiResponse(
        false,
        "Something went wrong while creating the profile.",
        null,
        500
    );

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }

}