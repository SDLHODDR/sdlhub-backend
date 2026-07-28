<?php
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Session expired", null, 401);
    }

    /* ===========================================
       GET EMPLOYEE DIVISION & DEPARTMENT
    =========================================== */

    $empCodeEsc = str_replace("'", "''", $empCode);

    $employee = singRec("
        SELECT
            EMP_CODE,
            DIVSN_ID,
            DEPT_ID
        FROM EPT_HR_EMP_OFFICE_DET
        WHERE EMP_CODE = '{$empCodeEsc}'
    ");

    if (empty($employee)) {
        apiResponse(false, "Employee not found", null, 404);
    }

    $divisionId   = (int)$employee['DIVSN_ID'];
    $departmentId = (int)$employee['DEPT_ID'];

    /* ===========================================
       GET PENDING POLICIES
    =========================================== */

    $policies = multiRec("
        SELECT
            P.POLI_ID,
            P.POLICY_NAME,
            P.DOC_PATH,
            P.POLICY_DESC,
            TO_CHAR(P.START_DATE,'DD-MON-YYYY') START_DATE,
            TO_CHAR(P.END_DATE,'DD-MON-YYYY') END_DATE
        FROM EPT_HR_POLICY P
        WHERE P.IS_MANDAT = 'Y'

          AND TRUNC(SYSDATE)
              BETWEEN TRUNC(P.START_DATE)
                  AND TRUNC(P.END_DATE)

          AND EXISTS (
                SELECT 1
                FROM EPT_HR_POLICY_DIVSN D
                WHERE D.POLICY_ID = P.POLI_ID
                  AND D.DIVSN_ID = {$divisionId}
          )

          AND EXISTS (
                SELECT 1
                FROM EPT_HR_POLICY_DEPT DP
                WHERE DP.POLICY_ID = P.POLI_ID
                  AND DP.DEPT_ID = {$departmentId}
          )

          AND NOT EXISTS (
                SELECT 1
                FROM EPT_USER_POLICY_VIEW_LOG L
                WHERE L.POLICY_ID = P.POLI_ID
                  AND L.EMP_CODE = '{$empCodeEsc}'
          )

        ORDER BY P.START_DATE DESC
    ");

    apiResponse(
        true,
        "Pending policies fetched successfully.",
        [
            "count" => count($policies),
            "policies" => $policies
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getPendingPolicies.php"
    );

    apiResponse(false, "Unable to fetch pending policies.", null, 500);
}