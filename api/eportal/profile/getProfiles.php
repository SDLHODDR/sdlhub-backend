<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$conn = db_eportal();
$sql___func___con = $conn;

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

if (!$conn) {
    apiResponse(false, "Database connection failed.", null, 500);
}

/* ===========================================
   SESSION VALIDATION
=========================================== */

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

try {

    /* ===========================================
       GET ACTIVE PROFILES
    =========================================== */

    $sql = "
        SELECT
            PROFILE_ID,
            PROFILE_DESC
        FROM EPT_PROFILES
        WHERE STATUS = 'A'
        ORDER BY PROFILE_DESC
    ";

    $profiles = multiRec($sql);
    apiResponse( true, "Profiles loaded successfully.", $profiles);

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getProfiles.php"
    );

    apiResponse(false,  "Unable to load profiles.", null, 500);

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }
}
