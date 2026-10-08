<?php

/*
|--------------------------------------------------------------------------
| File        : getTenureChangeList.php
| Module      : HRMS
| Description : Fetch upcoming employee tenure change listing
|--------------------------------------------------------------------------
*/

ob_start();
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/emp_func.php";

header("Content-Type: application/json; charset=UTF-8");

try {

    /* ==========================================================
       SESSION VALIDATION
    ========================================================== */

    if (!isset($_SESSION["emp_code"]) || empty($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    /* ==========================================================
       REQUEST METHOD
    ========================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* ==========================================================
       COMPANY ACCESS / IDS FILTER
    ========================================================== */

    // HRMS stores compId as an array; legacy sessions may contain a comma-separated string.
    $rawCompanyIds = $_SESSION["compId"] ?? [];
    if (is_array($rawCompanyIds)) {
        $companyIds = $rawCompanyIds;
    } else {
        $companyIds = preg_split('/\s*,\s*/', trim((string)$rawCompanyIds, " \"'"), -1, PREG_SPLIT_NO_EMPTY);
    }
    $companyIds = array_values(array_unique(array_filter(array_map(
        static fn($id) => trim((string)$id, " \"'"),
        $companyIds
    ), static fn($id) => $id !== '')));

    if (empty($companyIds) && function_exists('getUserCompanyIds')) {
        $empCode = trim((string)($_SESSION["emp_code"] ?? $_SESSION["EmpCode"] ?? ''));
        $companyIds = getUserCompanyIds($empCode);
        $companyIds = array_values(array_unique(array_filter(array_map(
            static fn($id) => trim((string)$id),
            $companyIds
        ), static fn($id) => $id !== '')));
    }

    // If company access cannot be resolved, fail closed and return no company rows.
    $compIdsString = "'" . implode("', '", array_map(
        static fn($id) => str_replace("'", "''", $id),
        $companyIds
    )) . "'";
    $compCondition = empty($companyIds)
        ? " AND 1 = 0 "
        : " AND EI.COMP_ID IN ($compIdsString) ";


    /* ==========================================================
       UPCOMING TENURE WINDOW
    ========================================================== */

    $emp_days = 200;
    // $emp_days = 15;

    /* ==========================================================
       FETCH UPCOMING TENURE EMPLOYEES
    ========================================================== */

    $sqljoin = multiRec("
        SELECT 
            A.EMP_CODE, 
            GET_EMP_NAME(A.EMP_CODE) AS ENAME,
            A.ETYPE_ID, 
            TO_CHAR(A.EFF_TO, 'DD-MON-YYYY') AS EFF_TO, 
            C.EMP_TYPE
        FROM (
            SELECT EMP_CODE, ETYPE_ID, MAX(EFF_TO) AS EFF_TO 
            FROM (
                SELECT * FROM HR_EMP_TENURE ORDER BY EFF_TO DESC
            ) 
            GROUP BY EMP_CODE, ETYPE_ID
        ) A
        INNER JOIN HR_EMP_TYPE C ON C.ETYPE_ID = A.ETYPE_ID
        INNER JOIN HR_EMPLOYEE_INFO EI ON A.EMP_CODE = EI.EMP_CODE
        WHERE A.ETYPE_ID IN (2, 5, 6)
          AND EI.STATUS = 'A'
          {$compCondition}
          AND A.EMP_CODE NOT IN (SELECT EMP_CODE FROM HR_EMP_TENURE_CHANGE)
          AND A.EMP_CODE NOT IN (
              SELECT EMP_CODE FROM HR_EMP_TENURE 
              WHERE ETYPE_ID = '1' 
                AND SYSDATE BETWEEN EFF_FROM AND NVL(EFF_TO, TO_DATE('01-MAR-3000', 'DD-MON-YYYY'))
          )
          AND A.EMP_CODE IN (SELECT EMP_CODE FROM HR_EMP_TENURE WHERE ETYPE_ID IN (2, 5, 6))
          AND TRUNC(A.EFF_TO) >= TRUNC(SYSDATE)
          AND TRUNC(A.EFF_TO) <= TRUNC(SYSDATE + {$emp_days})
        ORDER BY A.EFF_TO ASC
    ");

    $list = [];

    if (!empty($sqljoin) && is_array($sqljoin)) {
        foreach ($sqljoin as $tenure) {
            $empCode = $tenure['EMP_CODE'];

            // 1. Office details (Designation, Location, DOJ)
            $offDet = singRec("
                SELECT 
                    GET_ORG_LOC_NAME(ORG_LOC_ID) AS ORG_LOC_NAME,
                    DDMONYYYY(EFFEC_FROM) AS EFFEC_FROM,
                    GET_DESIGN_NAME(DESI_ID) AS DESI,
                    ORG_ID 
                FROM HR_EMP_OFFICE_DET 
                WHERE EMP_CODE = '{$empCode}'
            ");

            // 2. Job Description Assessment check
            $jd_id = singRec("SELECT JD_ID FROM HR_ORGANOGRAM WHERE ID = '" . ($offDet['ORG_ID'] ?? '') . "'");
            
            $jd = null;
            if (!empty($jd_id['JD_ID'])) {
                $jd = singRec("
                    SELECT 
                        A.SH_DESC, 
                        COUNT(B.ID) AS CNT,
                        A.ID
                    FROM HR_JD A 
                    INNER JOIN HR_JD_QUESTIONS B ON A.ID = B.JD_ID
                    WHERE A.ID = '{$jd_id['JD_ID']}' 
                      AND B.QGRP_TYPE = 'A'
                    GROUP BY A.SH_DESC, A.ID 
                    ORDER BY A.ID
                ");
            }

            // 3. Company code / short desc
            $empDet = singRec("SELECT COMP_ID FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = '{$empCode}'");
            $comp = singRec("SELECT SH_DESC FROM HR_COMPANY WHERE COMP_ID = '" . ($empDet['COMP_ID'] ?? '') . "'");

            // 4. Approval hierarchy calculation
            $org_id = function_exists('getEmpOffice') ? getEmpOffice($empCode) : ['ORG_ID' => ($offDet['ORG_ID'] ?? '')];
            $orgDetRec = singRec("SELECT GET_EMP_PARENTAL('{$empCode}', SYSDATE) AS ORG FROM DUAL");
            $orgDet = $orgDetRec['ORG'] ?? '';

            $result = [];
            if (!empty($orgDet)) {
                $entries = explode('#', rtrim($orgDet, '#'));
                foreach ($entries as $entry) {
                    $parts = explode(',', $entry);
                    if (count($parts) === 3) {
                        $result[] = [
                            'parent_orgid' => $parts[0],
                            'org_loc_id'   => $parts[1],
                            'emp_code'     => $parts[2]
                        ];
                    }
                }
            }

            $currentOrgId = $org_id['ORG_ID'] ?? '';
            $apprLvl = multiRec("
                SELECT APPR_LEVEL, APPR_ORGID
                FROM HR_ORG_APPR_LEVELS 
                WHERE ORG_ID = '{$currentOrgId}'
                  AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, TO_DATE('01-MAR-3000', 'DD-MON-YYYY'))
            ");

            if (!empty($apprLvl) && count($apprLvl) > 1) {
                $apprLvl = array_slice($apprLvl, 0, -1);
            }

            $apprOrgIds = array_column($apprLvl, 'APPR_ORGID');
            $apprLevels = array_column($apprLvl, 'APPR_LEVEL');

            $orgEmpMap = [];
            if (!empty($result)) {
                foreach ($result as $row) {
                    $orgEmpMap[$row['parent_orgid']] = $row['emp_code'];
                }
            }

            $approvalLevelsList = [];
            $firstMgrEmpCode = 'Vacant';
            $firstMgrName = '';
            $counter = 1;

            foreach ($apprLevels as $index => $levelNum) {
                $parentOrgId = $apprOrgIds[$index] ?? null;

                if ($parentOrgId && isset($orgEmpMap[$parentOrgId])) {
                    $approverCode = $orgEmpMap[$parentOrgId];

                    if (strtolower($approverCode) !== 'vacant') {
                        $approverName = function_exists('getEmpInfoByCode') ? getEmpInfoByCode($approverCode) : $approverCode;
                        $approvalLevelsList[] = [
                            'level'    => $counter,
                            'empCode'  => $approverCode,
                            'empName'  => $approverName,
                            'display'  => "{$counter}. {$approverName}"
                        ];

                        if ($firstMgrEmpCode === 'Vacant') {
                            $firstMgrEmpCode = $approverCode;
                            $firstMgrName = $approverName;
                        }

                        $counter++;
                    }
                }
            }

            // Determine action mode
            $hasJdQuestions = isset($jd['CNT']) && (int)$jd['CNT'] > 0;

            $list[] = [
                'empCode'       => $tenure['EMP_CODE'],
                'empName'       => ucwords(strtolower($tenure['ENAME'] ?? '')),
                'company'       => $comp['SH_DESC'] ?? '',
                'designation'   => $offDet['DESI'] ?? '',
                'location'      => $offDet['ORG_LOC_NAME'] ?? '',
                'empType'       => ucwords(strtolower($tenure['EMP_TYPE'] ?? '')),
                'doj'           => $offDet['EFFEC_FROM'] ?? '',
                'tenureDue'     => $tenure['EFF_TO'] ?? '',
                'orgId'         => $currentOrgId,
                'approvalLevels'=> $approvalLevelsList,
                'approvalText'  => !empty($approvalLevelsList) ? implode("\n", array_column($approvalLevelsList, 'display')) : 'Not Set',
                'manager'       => [
                    'empCode' => $firstMgrEmpCode,
                    'empName' => $firstMgrName
                ],
                'actionType'    => $hasJdQuestions ? 'ADD_JD' : 'SEND_REQUEST',
                'jd'            => [
                    'id'    => $jd_id['JD_ID'] ?? null,
                    'title' => $jd['SH_DESC'] ?? ''
                ]
            ];
        }
    }

    if (isset($sql___func___con)) {
        oci_close($sql___func___con);
    }

    apiResponse(true, "Tenure change list fetched successfully.", $list, 200);

} catch (Throwable $e) {

    if (isset($sql___func___con)) {
        @oci_rollback($sql___func___con);
        @oci_close($sql___func___con);
    }

    logOracleError([
        "message" => $e->getMessage(),
        "file"    => $e->getFile(),
        "line"    => $e->getLine()
    ]);

    apiResponse(
        false,
        "Unable to fetch tenure change list.",
        null,
        500,
        [$e->getMessage()]
    );
}