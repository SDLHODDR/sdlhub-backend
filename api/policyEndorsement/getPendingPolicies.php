<?php

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

$policySql = "";

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(
            false,
            "Session expired",
            null,
            401
        );
        exit;
    }

    /* ===========================================
       GET EMPLOYEE DIVISION & DEPARTMENT
    =========================================== */

    $empCodeEsc = str_replace("'", "''", $empCode);

    $employeeSql = "
        SELECT
            EMP_CODE,
            DIVSN_ID,
            DEPT_ID
        FROM EPT_HR_EMP_OFFICE_DET
        WHERE EMP_CODE = '{$empCodeEsc}'
    ";

    $employee = singRec($employeeSql);

    if (empty($employee) || !is_array($employee)) {
        apiResponse(
            false,
            "Employee office details not found.",
            null,
            404
        );
        exit;
    }

    $divisionId = (int)($employee['DIVSN_ID'] ?? 0);
    $departmentId = (int)($employee['DEPT_ID'] ?? 0);

    if ($divisionId <= 0 || $departmentId <= 0) {
        apiResponse(
            false,
            "Employee division or department is missing.",
            null,
            400
        );
        exit;
    }

    /* ===========================================
       GET PENDING MANDATORY POLICIES
    =========================================== */

    $policySql = "
        SELECT
            P.POLI_ID,
            P.POLICY_NAME,
            P.DOC_PATH,
            P.POLICY_DESC,
            TO_CHAR(P.START_DATE, 'DD-MON-YYYY') AS START_DATE,
            TO_CHAR(P.END_DATE, 'DD-MON-YYYY') AS END_DATE

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
    ";

    $policies = multiRec($policySql);

    /* ===========================================
       VALIDATE QUERY RESULT
    =========================================== */

    if (!is_array($policies)) {

        throw new RuntimeException(
            "Policy query did not return an array. " .
            "Check multiRec() and the Oracle query."
        );
    }

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Pending policies fetched successfully.",
        [
            "count" => count($policies),
            "policies" => $policies
        ]
    );

    exit;

} catch (Throwable $e) {

    /* ===========================================
       LOG ORIGINAL ERROR
    =========================================== */

    /*logOracleError(
        $e,
        $policySql
    );*/

      logOracleError(
        $e,
        $policySql ?? "Policy SQL not initialized"
    );

    /* ===========================================
       ERROR RESPONSE
    =========================================== */

    /*apiResponse(
        false,
        "Unable to fetch pending policies.",
        null,
        500
    );*/

    apiResponse(
        false,
        "DEBUG: " . $e->getMessage(),
        [
            "error_type" => get_class($e),
            "error_file" => basename($e->getFile()),
            "error_line" => $e->getLine()
        ],
        500
    );

    exit;
}