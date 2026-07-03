<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/emp_func.php";
require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

try {

    $data = json_decode(file_get_contents("php://input"), true);

    $comp = $data['company'] ?? '';
    $div  = $data['division'] ?? '';
    $dept = $data['department'] ?? '';

    if (!$comp || !$div) {
        throw new Exception("Company and Division are required");
    }

    /* ------------------ PROFILES ------------------ */

    $profiles = multiRec("
        SELECT PROFILE_ID, PROFILE_DESC
        FROM EPT_PROFILES
        WHERE STATUS = 'A'
        ORDER BY PROFILE_DESC
    ");

    /* ------------------ EMPLOYEES ------------------ */

    if (!empty($dept)) {

        /*
        ==========================================
        OLD LOGIC (Department selected)
        ==========================================
        */

        $employees = multiRec("
            SELECT 
                e.EMP_CODE,
                e.PROC_GROUP
            FROM EPT_BCS_EMPLOYEE e
            WHERE e.EMP_CODE IN (
                SELECT b.EMP_CODE
                FROM ept_hr_emp_office_det a
                INNER JOIN ept_hr_employee_info b
                    ON a.emp_code = b.emp_code
                WHERE b.status = 'A'
                  AND COMP_ID = '$comp'
                  AND DIVSN_ID = '$div'
                  AND DEPT_ID = '$dept'
                  AND SYSDATE BETWEEN A.EFFEC_FROM
                  AND NVL(a.EFFEC_TO, TO_DATE('01-JAN-3000','DD-MON-YYYY'))
            )
            ORDER BY e.PROC_GROUP, e.EMP_CODE
        ");

    } else {

        /*
        ==========================================
        NEW LOGIC (No Department selected)
        Show only employees who already have access
        ==========================================
        */

        $employees = multiRec("
            SELECT DISTINCT
                e.EMP_CODE,
                e.PROC_GROUP
            FROM EPT_BCS_EMPLOYEE e
            INNER JOIN EPT_EMP_PROFILE ep
                ON e.EMP_CODE = ep.EMP_CODE
            WHERE e.EMP_CODE IN (
                SELECT b.EMP_CODE
                FROM ept_hr_emp_office_det a
                INNER JOIN ept_hr_employee_info b
                    ON a.emp_code = b.emp_code
                WHERE b.status = 'A'
                  AND COMP_ID = '$comp'
                  AND DIVSN_ID = '$div'
                  AND SYSDATE BETWEEN A.EFFEC_FROM
                  AND NVL(a.EFFEC_TO, TO_DATE('01-JAN-3000','DD-MON-YYYY'))
            )
            ORDER BY e.PROC_GROUP, e.EMP_CODE
        ");
    }

    /* ------------------ GROUPS ------------------ */

    $groupMap = [];

    foreach ($employees as $emp) {

        $empCode = $emp['EMP_CODE'];
        $grp     = $emp['PROC_GROUP'];

        $empProfiles = multiRec("
            SELECT PROFILE_ID
            FROM EPT_EMP_PROFILE
            WHERE EMP_CODE = '$empCode'
        ");

        $profileIds = [];

        foreach ($empProfiles as $p) {
            $profileIds[] = $p['PROFILE_ID'];
        }

        if (!isset($groupMap[$grp])) {

            $grpInfo = singRec("
                SELECT PGRP_DESC
                FROM EPT_BCS_PAYROLL_GROUPS
                WHERE PGRP_CODE = '$grp'
            ");

            $groupMap[$grp] = [
                "groupCode" => $grp,
                "groupName" => $grpInfo['PGRP_DESC'] ?? $grp,
                "employees" => []
            ];
        }

        $groupMap[$grp]['employees'][] = [
            "empName" => getEmpInfoByCode($empCode),
            "empCode" => $empCode,
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
