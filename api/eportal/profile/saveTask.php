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

try {

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

    /* ===========================================
       READ INPUT
    =========================================== */

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        apiResponse(false, "Invalid request data.", null, 400);
    }

    $profile = (int)($input['profile'] ?? 0);
    $tasks = $input['tasks'] ?? [];

    if ($profile <= 0) {
        apiResponse(false, "Invalid profile.", null, 400);
    }

    /* ===========================================
       DELETE EXISTING TASK ACCESS
    =========================================== */

    startQry();

    executeQry("
        DELETE FROM EPT_PROFILE_TASK
        WHERE PROFILE_ID = {$profile}
    ");

    /* ===========================================
       INSERT TASK ACCESS
    =========================================== */

    if (!empty($tasks)) {

        foreach ($tasks as $taskId) {

            $taskId = (int)$taskId;

            executeQry("
                INSERT INTO EPT_PROFILE_TASK
                (
                    PROFILE_ID,
                    TASK_ID
                )
                VALUES
                (
                    {$profile},
                    {$taskId}
                )
            ");
        }
    }

    endQry();

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(true,"Task access saved successfully.");

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveProfileTaskAccess.php"
    );

    apiResponse(false, "Something went wrong while saving task access.", null, 500
    );

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }

}