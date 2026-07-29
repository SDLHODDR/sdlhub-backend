<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/emp_func.php";
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
       READ REQUEST
    =========================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request data.");
    }

    $comp = trim($data["company"] ?? "");
    $div  = trim($data["division"] ?? "");
    $dept = trim($data["department"] ?? "");
    $emp  = trim($data["employee"] ?? "");

    if (empty($comp)) {
        apiResponse(false, "Please select Company.");
    }

    /* ===========================================
       GET PROFILES
    =========================================== */

    $profiles = multiRec("
        SELECT
            PROFILE_ID,
            PROFILE_DESC
        FROM EPT_PROFILES
        WHERE STATUS = 'A'
        ORDER BY PROFILE_DESC
    ");

    /* ===========================================
       BUILD EMPLOYEE FILTER
    =========================================== */

    $officeWhere = "
        b.STATUS = 'A'
        AND COMP_ID = '{$comp}'
    ";

    if (!empty($div)) {
        $officeWhere .= " AND DIVSN_ID = '{$div}'";
    }

    if (!empty($dept)) {
        $officeWhere .= " AND DEPT_ID = '{$dept}'";
    }

    if (!empty($emp)) {
        $officeWhere .= " AND b.EMP_CODE = '{$emp}'";
    }

    $officeWhere .= "
        AND SYSDATE BETWEEN a.EFFEC_FROM
        AND NVL(a.EFFEC_TO, TO_DATE('01-JAN-3000','DD-MON-YYYY'))
    ";

    /* ===========================================
       GET EMPLOYEES
    =========================================== */

    if (!empty($dept)) {

        $employees = multiRec("
            SELECT
                e.EMP_CODE,
                e.PROC_GROUP
            FROM EPT_BCS_EMPLOYEE e
            WHERE e.EMP_CODE IN
            (
                SELECT b.EMP_CODE
                FROM EPT_HR_EMP_OFFICE_DET a
                INNER JOIN EPT_HR_EMPLOYEE_INFO b
                    ON a.EMP_CODE = b.EMP_CODE
                WHERE $officeWhere
            )
            ORDER BY e.PROC_GROUP, e.EMP_CODE
        ");

    } else {
        // No department -> show employees having profile access
        $employees = multiRec("
            SELECT DISTINCT
                e.EMP_CODE,
                e.PROC_GROUP
            FROM EPT_BCS_EMPLOYEE e
            INNER JOIN EPT_EMP_PROFILE ep
                ON ep.EMP_CODE = e.EMP_CODE
            WHERE e.EMP_CODE IN
            (
                SELECT b.EMP_CODE
                FROM EPT_HR_EMP_OFFICE_DET a
                INNER JOIN EPT_HR_EMPLOYEE_INFO b
                    ON a.EMP_CODE = b.EMP_CODE
                WHERE $officeWhere
            )
            ORDER BY e.PROC_GROUP, e.EMP_CODE
        ");
    }

    /* ===========================================
       GROUP EMPLOYEES
    =========================================== */

    $groupMap = [];

    foreach ($employees as $employee) {

        $employeeCode = $employee["EMP_CODE"];
        $groupCode = $employee["PROC_GROUP"];

        $empProfiles = multiRec("
            SELECT PROFILE_ID
            FROM EPT_EMP_PROFILE
            WHERE EMP_CODE = '{$employeeCode}'
        ");

        $profileIds = [];

        foreach ($empProfiles as $profile) {
            $profileIds[] = $profile["PROFILE_ID"];
        }

        if (!isset($groupMap[$groupCode])) {

            $groupInfo = singRec("
                SELECT PGRP_DESC
                FROM EPT_BCS_PAYROLL_GROUPS
                WHERE PGRP_CODE = '{$groupCode}'
            ");

            $groupMap[$groupCode] = [
                "groupCode" => $groupCode,
                "groupName" => $groupInfo["PGRP_DESC"] ?? $groupCode,
                "employees" => []
            ];
        }

        $groupMap[$groupCode]["employees"][] = [
            "empCode"  => $employeeCode,
            "empName"  => getEmpInfoByCode($employeeCode),
            "profiles" => $profileIds
        ];
    }

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Employee profiles fetched successfully.",
        [
            "profiles" => $profiles,
            "groups"   => array_values($groupMap)
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getEmployeeProfiles.php"
    );

    apiResponse(false, "Unable to fetch employee profiles.", null, 500);
}