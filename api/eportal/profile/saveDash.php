<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$conn = db_eportal();
$sql___func___con = $conn;

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/emp_func.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

if (!$conn) {
    apiResponse(false, "Database connection failed.", null, 500);
}

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       READ REQUEST BODY
    =========================================== */

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        apiResponse(false, "Invalid request payload.");
    }

    $profileId = (int)($input['profile'] ?? 0);
    $dashboards = $input['dashboards'] ?? [];

    if ($profileId <= 0) {
        apiResponse(false, "Invalid profile ID.");
    }

    /* ===========================================
       DELETE EXISTING DASHBOARD ACCESS
    =========================================== */

    executeQry("
        DELETE FROM EPT_PROFILE_DASH
        WHERE PROFILE_ID = {$profileId}
    ");

    /* ===========================================
       INSERT DASHBOARD ACCESS
    =========================================== */

    if (!empty($dashboards)) {

        foreach ($dashboards as $dashboardId) {

            $dashboardId = (int)$dashboardId;

            executeQry("
                INSERT INTO EPT_PROFILE_DASH
                (
                    PROFILE_ID,
                    DASH_ID
                )
                VALUES
                (
                    {$profileId},
                    {$dashboardId}
                )
            ");
        }
    }

    /* ===========================================
       COMMIT
    =========================================== */

    executeQry("COMMIT");

    /* ===========================================
       RESPONSE
    =========================================== */

    apiResponse(true,"Dashboard access saved successfully.");

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveProfileDashboardAccess.php"
    );

    apiResponse(false,"Something went wrong while saving dashboard access.", null,500);

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }
}