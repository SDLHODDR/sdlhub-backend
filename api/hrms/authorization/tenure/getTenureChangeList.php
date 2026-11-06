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
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";
require_once __DIR__ . "/../../../config/env.php";
require_once __DIR__ . "/../../../config/hr_func.php";
require_once __DIR__ . "/../../../config/hrmsAccess.php";

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

    loadHrmsAccess($_SESSION["emp_code"]);

    // Nothing below writes to the session. Release its lock so this list does not hold up
    // the user's other requests.
    session_write_close();

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

    // $emp_days = 200;
    $emp_days = 15;

    /* ==========================================================
       FETCH UPCOMING TENURE EMPLOYEES
    ========================================================== */

    // One query for the whole list: the database is several hundred milliseconds away from
    // some machines, so a query per employee takes many minutes.
    //
    // Uses each employee's latest tenure period. The period must end within the next
    // $emp_days days or have ended within the last 365.
    // Employees are hidden while a tenure task is open. HR_EMP_TENURE_CHANGE.STATUS cannot
    // show this: legacy records stay A or N after all their tasks are closed.
    // They are also hidden when a tenure change is later than the current period, because
    // it was never written to HR_EMP_TENURE. A change that was applied starts on the
    // current period's EFF_FROM, so the employee comes back when that period is due.
    //
    // The appraisal manager comes from Division Document Mapping: the position mapped to
    // the Confirmation Letter (document 14) for the employee's company, division,
    // department and designation.
    $rows = multiRec("
        SELECT L.*,
            CASE WHEN L.APPR_LOC_ID IS NOT NULL THEN get_org_loc_emp_code(L.APPR_LOC_ID, SYSDATE) END AS MGRCODE,
            CASE WHEN L.APPR_LOC_ID IS NOT NULL THEN GET_EMP_NAME(get_org_loc_emp_code(L.APPR_LOC_ID, SYSDATE)) END AS MGRNAME
        FROM (
            SELECT
                A.EMP_CODE,
                GET_EMP_NAME(A.EMP_CODE) AS ENAME,
                A.EFF_TO AS EFF_TO_SORT,
                TO_CHAR(A.EFF_TO, 'DD-Mon-YYYY') AS EFF_TO,
                C.EMP_TYPE,
                CO.SH_DESC AS COMPANY,
                O.ORG_ID,
                GET_ORG_LOC_NAME(O.ORG_LOC_ID) AS ORG_LOC_NAME,
                GET_DESIGN_NAME(O.DESI_ID) AS DESI,
                DDMONYYYY(NVL(EI.DOJ, O.EFFEC_FROM)) AS DOJ,
                (
                    SELECT COUNT(*)
                    FROM HR_JD_QUESTIONS Q
                    INNER JOIN HR_QUESTION_MASTER QM ON Q.QUESTION_ID = QM.ID
                    WHERE Q.JD_ID = OG.JD_ID AND Q.QGRP_TYPE = 'T'
                ) AS TENURE_QUESTIONS,
                (
                    SELECT MIN(HJD.ORG_LOC_ID)
                    FROM HR_DIVSN_DOC HJD
                    INNER JOIN HR_DOC_TYPES HDT ON HJD.DOC_ID = HDT.DOCTYP_ID
                    WHERE HDT.DOC_TYPE = 'D'
                      AND HDT.DOCTYP_ID = '14'
                      AND TO_CHAR(HJD.COMP_ID) = TO_CHAR(OG.COMPANY)
                      AND TO_CHAR(HJD.DIVSN_ID) = TO_CHAR(OG.DIVSN_ID)
                      AND TO_CHAR(HJD.DEPT_ID) = TO_CHAR(OG.DEPT_ID)
                      AND TO_CHAR(HJD.DESI_ID) = TO_CHAR(OG.DESI_ID)
                ) AS APPR_LOC_ID
            FROM (
                SELECT EMP_CODE, ETYPE_ID, EFF_FROM, EFF_TO,
                    ROW_NUMBER() OVER (PARTITION BY EMP_CODE ORDER BY EFF_FROM DESC, ID DESC) AS RN
                FROM HR_EMP_TENURE
            ) A
            INNER JOIN HR_EMP_TYPE C ON C.ETYPE_ID = A.ETYPE_ID
            INNER JOIN HR_EMPLOYEE_INFO EI ON A.EMP_CODE = EI.EMP_CODE
            LEFT JOIN HR_COMPANY CO ON CO.COMP_ID = EI.COMP_ID
            LEFT JOIN (
                SELECT EMP_CODE, ORG_ID, ORG_LOC_ID, DESI_ID, EFFEC_FROM,
                    ROW_NUMBER() OVER (PARTITION BY EMP_CODE ORDER BY EFFEC_FROM DESC, ID DESC) AS RN
                FROM HR_EMP_OFFICE_DET
                WHERE SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, TO_DATE('01-MAR-3000', 'DD-MON-YYYY'))
            ) O ON O.EMP_CODE = A.EMP_CODE AND O.RN = 1
            LEFT JOIN HR_ORGANOGRAM OG ON OG.ID = O.ORG_ID
            WHERE A.RN = 1
              AND A.ETYPE_ID IN (2, 5, 6)
              AND EI.STATUS = 'A'
              {$compCondition}
              AND NOT EXISTS (
                  SELECT 1 FROM HR_USER_TASKS UT
                  WHERE UT.TRAN_CODE = A.EMP_CODE
                    AND UT.TASK_ID IN (25, 26, 27, 32)
                    AND UT.STATUS = 'O'
              )
              AND NOT EXISTS (
                  SELECT 1 FROM HR_EMP_TENURE_CHANGE TC
                  WHERE TC.EMP_CODE = A.EMP_CODE
                    AND NVL(TC.CTC_EFF_FROM, TC.EFF_FROM) > A.EFF_FROM
              )
              AND A.EMP_CODE NOT IN (
                  SELECT EMP_CODE FROM HR_EMP_TENURE
                  WHERE ETYPE_ID = '1'
                    AND SYSDATE BETWEEN EFF_FROM AND NVL(EFF_TO, TO_DATE('01-MAR-3000', 'DD-MON-YYYY'))
              )
              AND TRUNC(A.EFF_TO) BETWEEN TRUNC(SYSDATE - 365) AND TRUNC(SYSDATE + {$emp_days})
        ) L
        ORDER BY L.EFF_TO_SORT ASC
    ");

    $list = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $managerName = trim((string)($row['MGRNAME'] ?? ''));
        $managerCode = trim((string)($row['MGRCODE'] ?? ''));

        if ($managerName !== '') {
            $approvalText = $managerCode !== '' ? "{$managerName} ({$managerCode})" : $managerName;
        } elseif ($managerCode !== '') {
            $approvalText = "-Not Set ({$managerCode})";
        } else {
            $approvalText = '';
        }

        $list[] = [
            'empCode'          => $row['EMP_CODE'],
            'empName'          => ucwords(strtolower($row['ENAME'] ?? '')),
            'company'          => $row['COMPANY'] ?? '',
            'designation'      => $row['DESI'] ?? '',
            'location'         => $row['ORG_LOC_NAME'] ?? '',
            'empType'          => ucwords(strtolower($row['EMP_TYPE'] ?? '')),
            'doj'              => $row['DOJ'] ?? '',
            'tenureDue'        => $row['EFF_TO'] ?? '',
            'orgId'            => $row['ORG_ID'] ?? '',
            'approvalLevels'   => [],
            'approvalText'     => $approvalText,
            'appraisalManager' => $managerName,
            'questions_set'    => (int)($row['TENURE_QUESTIONS'] ?? 0) > 0 ? 1 : 0,
            'manager'          => [
                'empCode'      => $managerCode !== '' ? $managerCode : 'Vacant',
                'empName'      => $managerName,
                'manager_code' => $managerCode !== '' ? $managerCode : '-',
            ],
        ];
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
