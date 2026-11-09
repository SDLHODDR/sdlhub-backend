<?php
/*
 * GET. HRMS dashboard (legacy hradmin/dashboard.php).
 * Widgets are given to profiles in HR_PROFILE_DASHBOARD (HR_DASH_MASTER.DASH_GRP);
 * a user with several profiles gets every widget any of them allows. Only the
 * data for those widgets is loaded, limited to the profile's company / division /
 * department scope. New Joinees and Upcoming Tenure Change are counted on the
 * frontend from their own pages' endpoints, so the numbers always match.
 */
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/hrmsAccess.php';

header('Content-Type: application/json; charset=UTF-8');

$empCode = trim((string)($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? ''));
if ($empCode === '') {
    apiResponse(false, 'Session expired. Please login again.', null, 401);
}
if (!$sql___func___con) {
    apiResponse(false, 'Database connection failed.', null, 500);
}

// Number of complete months shown in Joiners vs Exit (legacy: 8).
const DASHBOARD_MONTHS = 8;
// Requisitions listed under New Applicants (legacy: 14).
const DASHBOARD_REQUISITIONS = 14;

try {
    loadHrmsAccess($empCode);
    session_write_close();

    $widgets = array_column(multiRec("SELECT M.DASH_GRP, MIN(M.DISP_SEQ) AS SEQ
        FROM HR_EMP_PROFILE EP
        INNER JOIN HR_PROFILE_DASHBOARD PD ON PD.PROFILE_ID = EP.PROFILE_ID AND NVL(PD.STATUS, 'A') = 'A'
        INNER JOIN HR_DASH_MASTER M ON M.ID = PD.DASH_ID AND M.STATUS = 'A'
        WHERE EP.EMP_CODE = :emp_code
          AND SYSDATE BETWEEN EP.EFFEC_FROM AND NVL(EP.EFFEC_TO, DATE '3000-03-01')
        GROUP BY M.DASH_GRP
        ORDER BY 2", [':emp_code' => $empCode]) ?: [], 'DASH_GRP');
    $has = static fn($widget) => in_array($widget, $widgets, true);

    $in = static fn($values) => implode(',', array_map('intval', $values ?: [-1]));
    $companies = $in($_SESSION['compId'] ?? []);
    $divisions = $in($_SESSION['divId'] ?? []);
    $departments = $in($_SESSION['deptId'] ?? []);
    // Employee in scope by their latest office record (current, or the last one for leavers).
    $employeeInScope = static fn($empAlias) => "{$empAlias}.COMP_ID IN ({$companies}) AND EXISTS (
            SELECT 1 FROM HR_EMP_OFFICE_DET O
            WHERE O.EMP_CODE = {$empAlias}.EMP_CODE
              AND O.DIVSN_ID IN ({$divisions}) AND O.DEPT_ID IN ({$departments})
              AND O.ID = (SELECT MAX(O2.ID) KEEP (DENSE_RANK LAST ORDER BY O2.EFFEC_FROM)
                          FROM HR_EMP_OFFICE_DET O2 WHERE O2.EMP_CODE = {$empAlias}.EMP_CODE))";

    $data = ['widgets' => $widgets];

    if ($has('IntrvCnt')) {
        // Interviews assigned to the user (tasks 9 and 17).
        $row = singRec("SELECT COUNT(DISTINCT ID) AS CNT FROM HR_USER_TASKS
            WHERE EMP_CODE_FOR = :emp_code AND STATUS = 'O' AND TASK_ID IN (9, 17)", [':emp_code' => $empCode]);
        $data['pendingInterviews'] = (int)($row['CNT'] ?? 0);
    }

    if ($has('CandCnt')) {
        // Candidates are not tied to a company, so this count is not scoped.
        $row = singRec("SELECT COUNT(ID) AS CNT FROM HR_CANDIDATE_INFO WHERE STATUS = 'N'");
        $data['newCandidates'] = (int)($row['CNT'] ?? 0);
    }

    if ($has('EmpTypeCnt')) {
        $data['employeeTypes'] = array_map(static fn($row) => [
            'typeId' => $row['ETYPE_ID'],
            'type' => $row['EMP_TYPE'],
            'count' => (int)$row['CNT'],
        ], multiRec("SELECT C.ETYPE_ID, C.EMP_TYPE, COUNT(DISTINCT B.EMP_CODE) AS CNT
            FROM HR_EMP_TENURE A
            INNER JOIN HR_EMPLOYEE_INFO B ON B.EMP_CODE = A.EMP_CODE
            INNER JOIN HR_EMP_TYPE C ON C.ETYPE_ID = A.ETYPE_ID
            WHERE SYSDATE BETWEEN A.EFF_FROM AND NVL(A.EFF_TO, DATE '3000-03-01')
              AND B.STATUS = 'A' AND " . $employeeInScope('B') . "
            GROUP BY C.ETYPE_ID, C.EMP_TYPE
            ORDER BY C.ETYPE_ID") ?: []);
    }

    if ($has('JoinVsExit')) {
        // Every month is listed, including months with no joiners or exits.
        $counts = multiRec("WITH MONTHS AS (
                SELECT ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -LEVEL) AS MONTH_START
                FROM DUAL CONNECT BY LEVEL <= " . DASHBOARD_MONTHS . ")
            SELECT TO_CHAR(M.MONTH_START, 'Mon-YY') AS MONTH_LABEL,
                (SELECT COUNT(*) FROM HR_EMPLOYEE_INFO E
                  WHERE E.DOJ >= M.MONTH_START AND E.DOJ < ADD_MONTHS(M.MONTH_START, 1)
                    AND " . $employeeInScope('E') . ") AS JOINERS,
                (SELECT COUNT(*) FROM HR_EMPLOYEE_INFO E
                  WHERE E.DOL >= M.MONTH_START AND E.DOL < ADD_MONTHS(M.MONTH_START, 1)
                    AND " . $employeeInScope('E') . ") AS EXITS
            FROM MONTHS M
            ORDER BY M.MONTH_START") ?: [];
        $data['joinersVsExit'] = array_map(static fn($row) => [
            'month' => $row['MONTH_LABEL'],
            'joiners' => (int)$row['JOINERS'],
            'exits' => (int)$row['EXITS'],
        ], $counts);
    }

    if ($has('NewApps')) {
        // Latest open requisitions that have applications (legacy took 14 before sorting).
        $requisitions = multiRec("SELECT * FROM (
                SELECT R.ID, R.REC_TITLE, GET_ORG_LOC_NAME(R.ORG_LOC_ID) AS LOC
                FROM HR_RECRUITMENT R
                INNER JOIN HR_ORGANOGRAM G ON G.ID = R.ORG_ID
                WHERE NVL(R.STATUS, 'N') NOT IN ('C', 'R', 'X')
                  AND G.COMPANY IN ({$companies}) AND R.DIVSN_ID IN ({$divisions}) AND R.DEPT_ID IN ({$departments})
                  AND EXISTS (SELECT 1 FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID)
                ORDER BY R.ID DESC)
            WHERE ROWNUM <= " . DASHBOARD_REQUISITIONS) ?: [];
        $applicants = [];
        if ($requisitions) {
            $ids = $in(array_column($requisitions, 'ID'));
            foreach (multiRec("SELECT A.REQ_ID, TO_CHAR(A.APL_DATE, 'DD-Mon-YYYY') AS APL_DATE,
                    TO_CHAR(A.APL_DATE, 'YYYY-MM-DD') AS APL_ISO, GET_CANDIDATE_NM(A.CAN_ID) AS NAME,
                    B.CURR_CTC, B.EXPT_CTC, B.CND_LOCALITY
                FROM HR_CAN_APPLICATION_DETAILS A
                INNER JOIN HR_CANDIDATE_INFO B ON B.ID = A.CAN_ID
                WHERE A.REQ_ID IN ({$ids})
                ORDER BY A.APL_DATE DESC, A.ID DESC") ?: [] as $row) {
                $applicants[$row['REQ_ID']][] = [
                    'applicationDate' => $row['APL_DATE'],
                    'applicationIso' => $row['APL_ISO'],
                    'name' => $row['NAME'],
                    'currentCtc' => $row['CURR_CTC'],
                    'expectedCtc' => $row['EXPT_CTC'],
                    'locality' => $row['CND_LOCALITY'],
                ];
            }
        }
        $data['newApplicants'] = array_map(static fn($row) => [
            'id' => $row['ID'],
            // Legacy screens stored some titles HTML-encoded; React escapes text itself.
            'title' => html_entity_decode(trim($row['REC_TITLE'] . ($row['LOC'] ? ' - ' . $row['LOC'] : '')), ENT_QUOTES, 'UTF-8'),
            'applicants' => $applicants[$row['ID']] ?? [],
        ], $requisitions);
    }

    apiResponse(true, 'Dashboard fetched successfully.', $data, 200);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getDashboard.php');
    apiResponse(false, 'Unable to load the dashboard.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
