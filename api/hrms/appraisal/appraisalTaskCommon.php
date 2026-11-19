<?php
/*
|--------------------------------------------------------------------------
| APPRAISAL FOLLOW-UP TASKS (47-52) - COMMON
|--------------------------------------------------------------------------
| Raised by Final Appraisal (processFinalAppraisal.php); TRAN_CODE is the employee code.
|   47 APPR_CHG_LTR       CTC Change          (legacy appr_chg_letter.php)      -> 48
|   48 AUTH_APPR_CHG_LTR  CTC Approval        (appr_chg_letter_auth.php)        -> payroll; 52 when not promoted
|   49 APPR_EMP_PROMO     Employee Promotion  (appr_promo_request.php)          -> 50
|   50 APPR_EMP_PROMO_AUTH Promotion Auth     (appr_promo_req_auth.php)         -> office change; 51
|   51 PRT_PROMO_LTR      Print Promotion Letter (print_promotion_letter.php)
|   52 PRT_INCR_LTR       Print Increment Letter (print_increment_letter.php)
| The appraisal row is the employee's HR_APPR_ORG_EMP of the latest approved appraisal (status A,
| or C once the promotion is authorized); its ID is the REF_ID of the salary heads (SALARY_TYPE A).
*/

require_once __DIR__ . '/employeeAppraisalCommon.php';

const APPRAISAL_FOLLOW_UP_TASKS = [
    47 => 'Appraisal - CTC Change',
    48 => 'Appraisal - CTC Approval',
    49 => 'Appraisal - Employee Promotion',
    50 => 'Appraisal - Employee Promotion Auth',
    51 => 'Print Promotion Letter',
    52 => 'Print Increment Letter',
];

/** The open follow-up task (one of $taskIds) the user can act on, with the employee's appraisal row. */
function appraisalLoadFollowUpTask($userTaskId, $sessionEmp, array $taskIds)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new AppraisalRequestException('A valid task is required.', 422);
    }
    $task = appraisalRow("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID, CREATED_BY
        FROM HR_USER_TASKS WHERE ID = :id AND STATUS = 'O'", [':id' => (string)$userTaskId]);
    if (empty($task['ID']) || !in_array((int)$task['TASK_ID'], $taskIds, true)) {
        throw new AppraisalRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new AppraisalRequestException('You are not authorized to act on this task.', 403);
    }
    $code = (string)$task['TRAN_CODE'];
    // Legacy: the latest approved appraiser row gives the appraisal and the promotion decision.
    $manager = appraisalRow("SELECT ID, APPR_ID, RECO_PROMO, EMP_CODE_APPR, APPR_REMARKS, TO_CHAR(NXT_APPR_DUE, 'YYYY-MM-DD') AS NXT_DUE
        FROM HR_APPR_EMP_MGR WHERE EMP_CODE = :code AND STATUS = 'A' ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => $code]);
    $row = appraisalRow("SELECT ID, APPR_ID, EMP_CODE, ORG_ID, ORG_LOC_ID, STATUS, CURR_CTC, NEW_CTC, NEW_ORG_ID, NEW_ORG_LOC_ID,
            TO_CHAR(INCR_EFFEC_FROM, 'YYYY-MM-DD') AS INCR_FROM, TO_CHAR(PROMO_EFFEC_FROM, 'YYYY-MM-DD') AS PROMO_FROM
        FROM HR_APPR_ORG_EMP WHERE EMP_CODE = :code AND STATUS IN ('A', 'C') AND APPR_ID = :appr
        ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => $code, ':appr' => (string)($manager['APPR_ID'] ?? '')]);
    if (empty($row['ID'])) {
        throw new AppraisalRequestException('The approved appraisal of this employee was not found.', 404);
    }
    $task['MANAGER'] = $manager;
    $task['APPR'] = $row;
    return $task;
}

/** Employee summary shown at the top of every follow-up task. */
function appraisalFollowUpEmployee($code)
{
    $row = appraisalRow("SELECT E.EMP_CODE, E.ID AS EMP_ID, E.COMP_ID,
            INITCAP(TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' '))) AS NAME,
            TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ, O.ORG_ID, O.ORG_LOC_ID, O.DIVSN_ID, O.DEPT_ID,
            GET_DESIGN_NAME(O.DESI_ID) AS DESIGNATION, GET_DEPT_NAME(O.DEPT_ID) AS DEPARTMENT,
            GET_DIVISION_NAME(O.DIVSN_ID) AS DIVISION, GET_ORG_LOC_NAME(O.ORG_LOC_ID) AS LOCATION,
            GET_TOTAL_CTC(E.EMP_CODE, SYSDATE) AS CTC
        FROM HR_EMPLOYEE_INFO E
        LEFT JOIN HR_EMP_OFFICE_DET O ON O.ID = (SELECT MAX(X.ID) KEEP (DENSE_RANK FIRST ORDER BY
                CASE WHEN SYSDATE BETWEEN X.EFFEC_FROM AND NVL(X.EFFEC_TO, DATE '3000-03-01') THEN 0 ELSE 1 END, X.EFFEC_FROM DESC)
            FROM HR_EMP_OFFICE_DET X WHERE X.EMP_CODE = E.EMP_CODE)
        WHERE E.EMP_CODE = :code", [':code' => (string)$code]);
    if (empty($row['EMP_CODE'])) {
        throw new AppraisalRequestException('Employee not found.', 404);
    }
    return $row;
}

/** A display date (DD-Mon-YYYY) of YYYY-MM-DD, or ''. */
function appraisalDisplayDate($iso)
{
    return $iso ? date('d-M-Y', strtotime($iso)) : '';
}

/** Raise the next follow-up task, unassigned, in the employee's current unit (legacy generateTask). */
function appraisalRaiseFollowUp($group, $code, $description, $sessionEmp)
{
    $unit = appraisalRow("SELECT G.COMPANY, G.DIVSN_ID, G.DEPT_ID FROM HR_EMP_OFFICE_DET D
        INNER JOIN HR_ORGANOGRAM G ON G.ID = D.ORG_ID
        WHERE D.EMP_CODE = :code AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
        ORDER BY D.ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => (string)$code]);
    $taskId = generateTaskHR($group, (string)$code, $description, '', 'A', 'O', null, $sessionEmp,
        $unit['COMPANY'] ?? '', $unit['DIVSN_ID'] ?? '', $unit['DEPT_ID'] ?? '');
    if (empty($taskId)) {
        throw new RuntimeException("Could not create the {$group} task.");
    }
    return $taskId;
}
