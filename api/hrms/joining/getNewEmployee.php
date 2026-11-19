<?php
/*
 * GET                         -> drafts (status N) in the user's companies + allowed companies
 * GET ?emp_code=X[&user_task_id=Y] -> one employee (personal + office), allowed actions
 *                                     and the open joining task being worked on
 */
require_once __DIR__ . '/joiningCandidate.php';

try {
    loadHrmsAccess($empCode);
    $companies = joiningUserCompanies();
    $companyList = implode(',', array_map('intval', $companies ?: [-1]));
    $employeeCode = trim((string)($_GET['emp_code'] ?? ''));

    if ($employeeCode === '') {
        $drafts = joiningRecords("SELECT E.EMP_CODE,
                TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' ')) AS EMP_NAME,
                C.SH_DESC AS COMPANY, TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ,
                TO_CHAR(E.CHG_ON, 'DD-Mon-YYYY') AS CHG_ON, TO_CHAR(E.CHG_ON, 'YYYYMMDDHH24MISS') AS CHG_SORT,
                (SELECT COUNT(*) FROM HR_EMP_OFFICE_DET O WHERE O.EMP_CODE = E.EMP_CODE) AS OFFICE_COUNT
            FROM HR_EMPLOYEE_INFO E
            LEFT JOIN HR_COMPANY C ON C.COMP_ID = E.COMP_ID
            WHERE E.STATUS = 'N' AND E.COMP_ID IN ({$companyList})
              -- Recruited candidates are activated from their Employee Code Generation task.
              AND NOT EXISTS (SELECT 1 FROM HR_CAN_APPLICATION_DETAILS A WHERE A.EMP_CODE = E.EMP_CODE AND A.STATUS = 'T')
            ORDER BY E.CHG_ON DESC NULLS LAST");
        apiResponse(true, 'New employees fetched successfully.', [
            'drafts' => array_map(static fn($row) => [
                'empCode' => $row['EMP_CODE'],
                'empName' => $row['EMP_NAME'],
                'company' => $row['COMPANY'],
                'doj' => $row['DOJ'],
                'savedOn' => $row['CHG_ON'],
                'savedSort' => $row['CHG_SORT'],
                'hasOffice' => (int)$row['OFFICE_COUNT'] > 0,
            ], $drafts),
            'companies' => $companies,
        ], 200);
        exit;
    }

    $employee = joiningRecord("SELECT ID, EMP_CODE, STATUS, TITLE, FNAME, MNAME, LNAME,
            TO_CHAR(DOB, 'YYYY-MM-DD') AS DOB, ADDRESS, CITY, STATE, PINCODE, COUNTRY,
            PERMNT_ADDRESS, PERMNT_CITY, PERMNT_STATE, PERMNT_PINCODE, PERMNT_COUNTRY,
            PHONE, CELL, PER_EMAIL, COM_EMAIL, TEL_NO_EMERG, BLOOD_GRP, GENDER, COMP_ID,
            TO_CHAR(DOJ, 'YYYY-MM-DD') AS DOJ, TO_CHAR(DOL, 'YYYY-MM-DD') AS DOL, PICS
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    if (empty($employee['ID'])) {
        throw new JoiningRequestException('Employee not found.', 404);
    }

    // Coming from the Joining task list: task 45 authorizes the employee, the
    // detail tasks open their own tab with Save and Submit.
    $authorizeTask = null;
    $detailTask = null;
    $activateTask = null;
    $userTaskId = (int)($_GET['user_task_id'] ?? 0);
    // Employee Code Generation (3) of a recruited candidate: TRAN_CODE is the application.
    if ($userTaskId > 0) {
        $codeTask = singRec("SELECT T.ID, T.TASK_ID, T.TRAN_CODE, T.STATUS, T.EMP_CODE_FOR, T.COMP_ID, T.DIVSN_ID, T.DEPT_ID
            FROM HR_USER_TASKS T
            INNER JOIN HR_CAN_APPLICATION_DETAILS A ON TO_CHAR(A.ID) = T.TRAN_CODE AND A.EMP_CODE = :emp_code AND A.STATUS = 'T'
            WHERE T.ID = :id AND T.TASK_ID = :task_id AND T.STATUS = 'O'", [
            ':id' => (string)$userTaskId,
            ':emp_code' => $employeeCode,
            ':task_id' => (string)JOINING_TASK_CODE_GENERATION,
        ]);
        if (!empty($codeTask['ID']) && hrmsCanActOnTask($codeTask, $empCode)) {
            $activateTask = ['id' => (string)$codeTask['ID'], 'missing' => joiningActivationMissing($employeeCode)];
        }
    }
    if ($userTaskId > 0 && !$activateTask) {
        $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
            FROM HR_USER_TASKS
            WHERE ID = :id AND TRAN_CODE = :emp_code AND STATUS = 'O'", [
            ':id' => (string)$userTaskId,
            ':emp_code' => $employeeCode,
        ]);
        if (!empty($task['ID']) && hrmsCanActOnTask($task, $empCode)) {
            $taskId = (string)$task['TASK_ID'];
            if ($taskId === (string)JOINING_TASK_EMP_AUTH) {
                $authorizeTask = (string)$task['ID'];
            } elseif (isset(JOINING_DETAIL_SECTIONS[$taskId])) {
                $detailTask = [
                    'id' => (string)$task['ID'],
                    'taskId' => $taskId,
                    'section' => JOINING_DETAIL_SECTIONS[$taskId],
                ];
            }
        }
    }

    $inScope = in_array((string)$employee['COMP_ID'], $companies, true);
    if (!$inScope && !$authorizeTask && !$detailTask && !$activateTask) {
        throw new JoiningRequestException('You are not authorized to view this employee.', 403);
    }

    $office = joiningEmployeeOffice($employeeCode);
    $status = (string)$employee['STATUS'];
    $fromCandidate = !empty(joiningCandidateEmployeeApplication($employeeCode)['ID']);
    $canEdit = $status === 'N' && ($inScope || $activateTask);

    apiResponse(true, 'Employee fetched successfully.', [
        'employee' => $employee,
        'office' => !empty($office['ID']) ? $office : null,
        'canEdit' => $canEdit,
        // A recruited candidate is activated from task 3, not sent for authorization.
        'fromCandidate' => $fromCandidate,
        'canSendForAuth' => $status === 'N' && $inScope && !$fromCandidate && !empty($office['ID']),
        'activateTask' => $status === 'N' ? $activateTask : null,
        'authorizeTaskId' => $status === 'T' ? $authorizeTask : null,
        'detailTask' => $detailTask,
    ], 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getNewEmployee.php');
    apiResponse(false, 'Unable to load the employee.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
