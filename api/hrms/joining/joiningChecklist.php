<?php
/*
 * Joining checklist tasks, generated when a recruited employee is activated.
 * TRAN_CODE is the employee code. Each row has a status and remarks:
 *    4 Joining Activity      (legacy dept_access_req.php)  - the task department's joining
 *                              activities (HR_DEPT_JOEX_ACTIVITY, type J) -> HR_EMP_DEPT_ACTIVITY
 *   15 Employee Induction    (legacy employee_induction.php) - the JD's induction areas for the
 *                              task's organogram (HR_JD_INDUCTION) -> HR_EMP_INDUCTION (+ date)
 *   14 Employee Verification (legacy employee_verify.php) - the experience references
 *                              (HR_EMP_REFERENCE): Verified / Not Verified + remarks
 */
require_once __DIR__ . '/joiningCommon.php';

const JOINING_CHECKLIST_TASKS = [
    '4' => ['title' => 'Joining Activity', 'statuses' => ['Y' => 'Yes', 'N' => 'No', 'X' => 'Not Applicable']],
    '15' => ['title' => 'Employee Induction', 'statuses' => ['Y' => 'Yes', 'N' => 'No', 'X' => 'Not Applicable']],
    '14' => ['title' => 'Employee Verification', 'statuses' => ['V' => 'Verified', 'N' => 'Not Verified']],
];
const JOINING_CHECKLIST_REMARK_MAX = 200;

/** The open checklist task the user can act on, with its employee. */
function joiningLoadChecklistTask($userTaskId, $sessionEmp)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new JoiningRequestException('A valid task is required.', 422);
    }
    loadHrmsAccess($sessionEmp);
    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND STATUS = 'O'", [':id' => (string)$userTaskId]);
    if (empty($task['ID']) || !isset(JOINING_CHECKLIST_TASKS[(string)$task['TASK_ID']])) {
        throw new JoiningRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new JoiningRequestException('You are not authorized to act on this task.', 403);
    }
    $task['EMPLOYEE'] = joiningLoadEmployee($task['TRAN_CODE']);
    return $task;
}

/** Rows of the task with what has been saved so far: id, label, extra columns, status, remarks, date. */
function joiningChecklistRows(array $task)
{
    $code = (string)$task['EMPLOYEE']['EMP_CODE'];
    switch ((string)$task['TASK_ID']) {
        case '4':
            $rows = joiningRecords("SELECT A.ID, A.ACT_DESC, A.DISP_SEQ, D.STATUS, D.REMARKS
                FROM HR_DEPT_JOEX_ACTIVITY A
                LEFT JOIN HR_EMP_DEPT_ACTIVITY D ON D.ID = (
                    SELECT MAX(X.ID) FROM HR_EMP_DEPT_ACTIVITY X
                    WHERE X.DEPT_ACT_ID = A.ID AND X.EMP_CODE = :emp_code AND X.DEPT_ID = A.DEPT_ID)
                WHERE A.DEPT_ID = :dept_id AND A.ACT_TYPE = 'J'
                ORDER BY A.DISP_SEQ, A.ID", [':emp_code' => $code, ':dept_id' => (string)$task['DEPT_ID']]);
            return array_map(static fn($row) => [
                'id' => (string)$row['ID'],
                'label' => (string)$row['ACT_DESC'],
                'status' => (string)($row['STATUS'] ?? ''),
                'remarks' => (string)($row['REMARKS'] ?? ''),
            ], $rows);

        case '15':
            $rows = joiningRecords("SELECT HD.INDUC_ID, MAX(HI.INDUC_DESC) AS INDUC_DESC, MIN(HD.DISP_SEQ) AS DISP_SEQ
                FROM HR_JD_INDUCTION HD
                INNER JOIN HR_INDUCTION_AREAS HI ON HI.INDUC_ID = HD.INDUC_ID
                WHERE HD.JD_ID = (SELECT G.JD_ID FROM HR_EMP_OFFICE_DET O INNER JOIN HR_ORGANOGRAM G ON G.ID = O.ORG_ID
                        WHERE O.ID = (SELECT MAX(X.ID) KEEP (DENSE_RANK FIRST ORDER BY
                                CASE WHEN SYSDATE BETWEEN X.EFFEC_FROM AND NVL(X.EFFEC_TO, DATE '3000-03-01') THEN 0 ELSE 1 END,
                                X.EFFEC_FROM DESC)
                            FROM HR_EMP_OFFICE_DET X WHERE X.EMP_CODE = :emp_code))
                  AND HD.ORG_ID IN (SELECT ID FROM HR_ORGANOGRAM WHERE COMPANY = :comp_id AND DIVSN_ID = :divsn_id AND DEPT_ID = :dept_id)
                GROUP BY HD.INDUC_ID
                ORDER BY MIN(HD.DISP_SEQ), HD.INDUC_ID", [
                ':emp_code' => $code, ':comp_id' => (string)$task['COMP_ID'],
                ':divsn_id' => (string)$task['DIVSN_ID'], ':dept_id' => (string)$task['DEPT_ID'],
            ]);
            $saved = [];
            foreach (joiningRecords("SELECT INDUC_ID, STATUS, REMARKS, TO_CHAR(INDUC_ON, 'YYYY-MM-DD') AS INDUC_ON
                FROM HR_EMP_INDUCTION WHERE EMP_CODE = :emp_code ORDER BY ID", [':emp_code' => $code]) as $row) {
                $saved[(string)$row['INDUC_ID']] = $row;
            }
            return array_map(static fn($row) => [
                'id' => (string)$row['INDUC_ID'],
                'label' => (string)$row['INDUC_DESC'],
                'status' => (string)($saved[(string)$row['INDUC_ID']]['STATUS'] ?? ''),
                'remarks' => (string)($saved[(string)$row['INDUC_ID']]['REMARKS'] ?? ''),
                'date' => (string)($saved[(string)$row['INDUC_ID']]['INDUC_ON'] ?? ''),
            ], $rows);

        case '14':
            $rows = joiningRecords("SELECT R.ID, R.REF_NAME, X.ORG_NAME, R.POSITION, R.TEL, R.YEAR_KNOWN, R.STATUS, R.VERIFIC_REMARKS
                FROM HR_EMP_REFERENCE R
                LEFT JOIN HR_EMP_EXPERIENCE X ON X.ID = R.EXP_ID
                WHERE R.EMP_CODE = :emp_code
                ORDER BY R.ID", [':emp_code' => $code]);
            return array_map(static fn($row) => [
                'id' => (string)$row['ID'],
                'label' => (string)$row['REF_NAME'],
                'organisation' => (string)($row['ORG_NAME'] ?? ''),
                'position' => (string)($row['POSITION'] ?? ''),
                'telephone' => (string)($row['TEL'] ?? ''),
                'yearsKnown' => (string)($row['YEAR_KNOWN'] ?? ''),
                // Copied references start as N; only a saved verification counts as answered.
                'status' => trim((string)($row['VERIFIC_REMARKS'] ?? '')) === '' && ($row['STATUS'] ?? '') === 'N'
                    ? '' : (string)($row['STATUS'] ?? ''),
                'remarks' => (string)($row['VERIFIC_REMARKS'] ?? ''),
            ], $rows);
    }
    return [];
}
