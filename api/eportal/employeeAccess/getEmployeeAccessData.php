<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/emp_func.php";
require_once __DIR__ . "/../../config/functions.php";

header("Content-Type: application/json");

try {

    $data = json_decode(file_get_contents("php://input"), true);

    $comp = trim($data["company"] ?? "");
    $div  = trim($data["division"] ?? "");
    $dept = trim($data["department"] ?? "");
    $emp  = trim($data["employee"] ?? "");

    if (empty($comp)) {
       throw new Exception("Please select Company");
    }

    /* ---------------- PROFILES ---------------- */

    $profiles = multiRec("
        SELECT PROFILE_ID, PROFILE_DESC
        FROM EPT_PROFILES
        WHERE STATUS='A'
        ORDER BY PROFILE_DESC
    ");

    /* ---------------- BUILD EMPLOYEE FILTER ---------------- */

    $officeWhere = "
        b.STATUS='A'
        AND COMP_ID='$comp'
    ";

    if (!empty($div)) {
        $officeWhere .= " AND DIVSN_ID='$div'";
    }

    if (!empty($dept)) {
        $officeWhere .= " AND DEPT_ID='$dept'";
    }

    if (!empty($emp)) {
        $officeWhere .= " AND b.EMP_CODE='$emp'";
    }

    $officeWhere .= "
        AND SYSDATE BETWEEN a.EFFEC_FROM
        AND NVL(a.EFFEC_TO, TO_DATE('01-JAN-3000','DD-MON-YYYY'))
    ";

    /* ---------------- EMPLOYEES ---------------- */

    if (!empty($dept)) {

        // Department selected -> show all employees

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
                    ON a.EMP_CODE=b.EMP_CODE
                WHERE $officeWhere
            )
            ORDER BY e.PROC_GROUP,e.EMP_CODE
        ");

    } else {

        // No department -> show employees having profile access

        $employees = multiRec("
            SELECT DISTINCT
                e.EMP_CODE,
                e.PROC_GROUP
            FROM EPT_BCS_EMPLOYEE e
            INNER JOIN EPT_EMP_PROFILE ep
                ON ep.EMP_CODE=e.EMP_CODE
            WHERE e.EMP_CODE IN
            (
                SELECT b.EMP_CODE
                FROM EPT_HR_EMP_OFFICE_DET a
                INNER JOIN EPT_HR_EMPLOYEE_INFO b
                    ON a.EMP_CODE=b.EMP_CODE
                WHERE $officeWhere
            )
            ORDER BY e.PROC_GROUP,e.EMP_CODE
        ");
    }

    /* ---------------- GROUP DATA ---------------- */

    $groupMap = [];

    foreach ($employees as $employee) {

        $empCode = $employee["EMP_CODE"];
        $group   = $employee["PROC_GROUP"];

        $empProfiles = multiRec("
            SELECT PROFILE_ID
            FROM EPT_EMP_PROFILE
            WHERE EMP_CODE='$empCode'
        ");

        $profileIds = [];

        foreach ($empProfiles as $profile) {
            $profileIds[] = $profile["PROFILE_ID"];
        }

        if (!isset($groupMap[$group])) {

            $groupInfo = singRec("
                SELECT PGRP_DESC
                FROM EPT_BCS_PAYROLL_GROUPS
                WHERE PGRP_CODE='$group'
            ");

            $groupMap[$group] = [
                "groupCode" => $group,
                "groupName" => $groupInfo["PGRP_DESC"] ?? $group,
                "employees" => []
            ];
        }

        $groupMap[$group]["employees"][] = [
            "empCode" => $empCode,
            "empName" => getEmpInfoByCode($empCode),
            "profiles" => $profileIds
        ];
    }

    echo json_encode([
        "status" => true,
        "profiles" => $profiles,
        "groups" => array_values($groupMap)
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}