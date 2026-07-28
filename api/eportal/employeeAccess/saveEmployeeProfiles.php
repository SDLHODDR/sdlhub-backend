<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/emp_func.php";

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
       READ REQUEST
    =========================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request data.");
    }

    /* ===========================================
       SAVE EMPLOYEE PROFILES
    =========================================== */

    startQry();

    foreach ($data as $row) {

        $employeeCode = trim($row['empCode'] ?? '');
        $profiles = $row['profiles'] ?? [];

        if ($employeeCode === '') {
            continue;
        }

        /* Delete Existing Profiles */

        executeQry("
            DELETE FROM EPT_EMP_PROFILE
            WHERE EMP_CODE = '{$employeeCode}'
        ");

        /* Insert Selected Profiles */

        if (!empty($profiles) && is_array($profiles)) {

            foreach ($profiles as $profileId) {

                $profileId = (int)$profileId;

                if ($profileId <= 0) {
                    continue;
                }

                executeQry("
                    INSERT INTO EPT_EMP_PROFILE
                    (
                        EMP_CODE,
                        PROFILE_ID
                    )
                    VALUES
                    (
                        '{$employeeCode}',
                        {$profileId}
                    )
                ");
            }
        }
    }

    endQry();

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(true, "Employee profiles saved successfully.");

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveEmployeeProfiles.php"
    );

    apiResponse(false, "Unable to save employee profiles.", null, 500 );
}