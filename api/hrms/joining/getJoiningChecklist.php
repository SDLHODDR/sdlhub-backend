<?php
/* GET ?user_task_id= - a Joining Activity (4), Employee Induction (15) or Employee Verification (14) task. */
require_once __DIR__ . '/joiningChecklist.php';

try {
    $task = joiningLoadChecklistTask($_GET['user_task_id'] ?? 0, $empCode);
    $employee = $task['EMPLOYEE'];
    $office = joiningRecord("SELECT GET_DEPT_NAME(O.DEPT_ID) AS DEPARTMENT, GET_DESIGN_NAME(O.DESI_ID) AS DESIGNATION,
            GET_DIVISION_NAME(O.DIVSN_ID) AS DIVISION, GET_ORG_LOC_NAME(O.ORG_LOC_ID) AS LOCATION
        FROM HR_EMP_OFFICE_DET O
        -- Current office; a new joiner's office starts on a future joining date, so fall back to the latest.
        WHERE O.ID = (SELECT MAX(X.ID) KEEP (DENSE_RANK FIRST ORDER BY
                CASE WHEN SYSDATE BETWEEN X.EFFEC_FROM AND NVL(X.EFFEC_TO, DATE '3000-03-01') THEN 0 ELSE 1 END, X.EFFEC_FROM DESC)
            FROM HR_EMP_OFFICE_DET X WHERE X.EMP_CODE = :emp_code)",
        [':emp_code' => $employee['EMP_CODE']]);
    $config = JOINING_CHECKLIST_TASKS[(string)$task['TASK_ID']];
    $taskDept = joiningRecord("SELECT GET_DEPT_NAME(:dept_id) AS NAME FROM DUAL", [':dept_id' => (string)$task['DEPT_ID']]);

    apiResponse(true, 'Task fetched successfully.', [
        'taskId' => (string)$task['TASK_ID'],
        'title' => $config['title'],
        'employee' => [
            'code' => $employee['EMP_CODE'],
            'name' => ucwords(strtolower(joiningEmployeeName($employee))),
            'doj' => $employee['DOJ_ISO'] ? date('d-M-Y', strtotime($employee['DOJ_ISO'])) : '',
            'department' => $office['DEPARTMENT'] ?? '',
            'designation' => $office['DESIGNATION'] ?? '',
            'division' => $office['DIVISION'] ?? '',
            'location' => $office['LOCATION'] ?? '',
        ],
        'taskDepartment' => $taskDept['NAME'] ?? '',
        'statuses' => array_map(static fn($value, $label) => ['value' => $value, 'label' => $label],
            array_keys($config['statuses']), $config['statuses']),
        'rows' => joiningChecklistRows($task),
    ], 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getJoiningChecklist.php');
    apiResponse(false, 'Unable to load the task.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
