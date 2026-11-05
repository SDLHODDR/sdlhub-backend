<?php
/*
 * GET ?task_id=<workflow task>&user_task_id=<HR_USER_TASKS.ID>
 * Details for the Exit task modals (legacy separation_edit.php, exit_int.php, exit_act.php).
 */
require_once __DIR__ . '/exitCommon.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new ExitRequestException('Invalid request method.', 405);
    }
    $workflowTaskId = (int)($_GET['task_id'] ?? 0);
    if (!in_array($workflowTaskId, [EXIT_TASK_SEPARATION, EXIT_TASK_ACTIVITY, EXIT_TASK_INTERVIEW, EXIT_TASK_DEACTIVATE, EXIT_TASK_LETTER], true)) {
        throw new ExitRequestException('Invalid exit task.', 422);
    }

    [$task, $separation] = exitLoadTask($workflowTaskId, $_GET['user_task_id'] ?? 0, $empCode);
    $office = exitEmployeeOffice($separation['EMP_CODE'], exitSeparationDate($separation));

    $details = [
        'employee' => exitEmployeeSummary($separation, $office),
        'separation' => [
            'id' => $separation['ID'],
            'resignType' => $separation['RESIGN_TYPE'],
            'resignMode' => $separation['RESIGN_MODE'],
            'recipientEmail' => $separation['RESIGN_RCP_EMAIL'],
            'resignationDate' => $separation['RESIGN_RCP_DATE'],
            'expectedRelievingDate' => $separation['EXP_RELIV_DATE'],
            'expectedRelievingDateIso' => $separation['EXP_RELIV_DATE_ISO'],
            'relievingDate' => $separation['RELEIVING_DATE'],
            'relievingDateIso' => $separation['RELEIVING_DATE_ISO'],
            'employeeComments' => $separation['EMP_COMMENTS'],
            'authorizeComments' => $separation['AUTH_COMMENTS'],
            'handoverTo' => $separation['HANDOVER_TO'],
            'exitComments' => $separation['EXIT_COMMENTS'],
            'retentionFlag' => $separation['RETENTION_FLAG'],
            'status' => $separation['STATUS'],
        ],
    ];

    if ($workflowTaskId === EXIT_TASK_SEPARATION || $workflowTaskId === EXIT_TASK_DEACTIVATE) {
        $details['handoverOptions'] = array_map(static fn($row) => [
            'value' => $row['EMP_CODE'],
            'label' => $row['LABEL'],
        ], multiRec("SELECT EMP_CODE, TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) || ' - ' || EMP_CODE AS LABEL
            FROM HR_EMPLOYEE_INFO WHERE STATUS = 'A' ORDER BY 2") ?: []);
        if (empty($details['separation']['handoverTo'])) {
            // Legacy default: the employee's reporting (parent organogram) employee.
            $details['separation']['handoverTo'] = findParentOrgEmp($separation['EMP_CODE']) ?: '';
        }
    }

    if ($workflowTaskId === EXIT_TASK_DEACTIVATE) {
        $details['clearances'] = array_map(static fn($row) => [
            'department' => $row['DEPT'],
            'status' => $row['STATUS'] === 'O' ? 'Open' : 'Closed',
            'doneBy' => $row['AUTH_BY'] ? getEmpInfoByCodeHR($row['AUTH_BY']) : '',
        ], multiRec("SELECT GET_DEPT_NAME(DEPT_ID) AS DEPT, STATUS, AUTH_BY
            FROM HR_USER_TASKS WHERE TRAN_CODE = :ref_id AND TASK_ID = :task_id ORDER BY ID", [
            ':ref_id' => (string)$separation['ID'],
            ':task_id' => (string)EXIT_TASK_ACTIVITY,
        ]) ?: []);
    }

    if ($workflowTaskId === EXIT_TASK_INTERVIEW) {
        $details['jdId'] = $office['JD_ID'] ?? '';
        $details['questions'] = exitInterviewQuestions($office['JD_ID'] ?? '', $separation['ID']);
    }

    if ($workflowTaskId === EXIT_TASK_LETTER) {
        $docs = exitLetterDocs($separation['EMP_CODE'], $separation['ENTER_ON_ISO']);
        $details['letters'] = array_map(static fn($docId) => [
            'docId' => $docId,
            'name' => EXIT_LETTERS[$docId]['name'],
            'reference' => $docs[$docId]['DOC_REF'] ?? '',
            'date' => $docs[$docId]['DOC_DATE'] ?? '',
        ], array_keys(EXIT_LETTERS));
    }

    if ($workflowTaskId === EXIT_TASK_ACTIVITY) {
        $dept = singRec("SELECT GET_DEPT_NAME(:dept_id) AS DEPT FROM DUAL", [':dept_id' => (string)$task['DEPT_ID']]);
        $details['activityDepartment'] = $dept['DEPT'] ?? '';
        $details['activities'] = exitDepartmentActivities($task['DEPT_ID'], $separation['ID']);
    }

    apiResponse(true, 'Exit task details fetched successfully.', $details, 200);
} catch (ExitRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getExitTaskDetails.php');
    apiResponse(false, 'Unable to load the exit task details.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
