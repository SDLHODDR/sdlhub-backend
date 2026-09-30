<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=UTF-8');

$authEmpCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if ($authEmpCode === '') {
    apiResponse(false, 'Unauthorized access.', null, 401);
}
if (!$sql___func___con) {
    apiResponse(false, 'Unable to connect to HRMS database.', null, 500);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $userTaskId = (int)($input['user_task_id'] ?? 0);
    $jobId = trim((string)($input['jd_id'] ?? ''));
    $decision = strtoupper(trim((string)($input['decision'] ?? '')));
    $remarks = trim((string)($input['remarks'] ?? ''));

    if ($userTaskId <= 0 || $jobId === '' || !in_array($decision, ['A', 'R'], true)) {
        apiResponse(false, 'Task, Job Description, and a valid decision are required.', null, 400);
    }
    if ($decision === 'R' && $remarks === '') {
        apiResponse(false, 'A remark is required when rejecting a Job Description.', null, 400);
    }

    $taskMaster = singRec("SELECT ID FROM HR_TASK_MASTER WHERE TASK_GRP = 'AUTH_JD'");
    if (empty($taskMaster['ID'])) {
        apiResponse(false, 'Job Description authorization task is not configured.', null, 400);
    }

    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
                     FROM HR_USER_TASKS
                     WHERE ID = '" . $userTaskId . "'");
    if (empty($task['ID']) || (string)$task['TASK_ID'] !== (string)$taskMaster['ID'] ||
        (string)$task['TRAN_CODE'] !== $jobId) {
        apiResponse(false, 'Job Description authorization task not found.', null, 404);
    }
    if (strtoupper((string)$task['STATUS']) !== 'O') {
        apiResponse(false, 'This authorization task has already been processed.', null, 400);
    }
    $userCompIds = array_map('strval', $_SESSION['compId'] ?? []);
    $userDivisionIds = array_map('strval', $_SESSION['divId'] ?? []);
    $userDepartmentIds = array_map('strval', $_SESSION['deptId'] ?? []);
    $hasTaskScope = in_array((string)$task['COMP_ID'], $userCompIds, true)
        && in_array((string)$task['DIVSN_ID'], $userDivisionIds, true)
        && in_array((string)$task['DEPT_ID'], $userDepartmentIds, true);
    $isDirectlyAssigned = !empty($task['EMP_CODE_FOR'])
        && (string)$task['EMP_CODE_FOR'] === (string)$authEmpCode;
    if (!$hasTaskScope || (!empty($task['EMP_CODE_FOR']) && !$isDirectlyAssigned && (string)$task['TASK_ID'] !== '57')) {
        apiResponse(false, 'You are not authorized to process this task.', null, 403);
    }

    $taskStatus = $decision === 'A' ? 'C' : 'X';
    $jobStatus = $decision === 'A' ? 'A' : 'R';

    $taskSql = "UPDATE HR_USER_TASKS
                SET STATUS = :task_status,
                    AUTH_ON = SYSDATE,
                    AUTH_BY = :auth_by,
                    REMARKS = :remarks
                WHERE ID = :task_id AND STATUS = 'O'";
    $taskStmt = oci_parse($sql___func___con, $taskSql);
    oci_bind_by_name($taskStmt, ':task_status', $taskStatus);
    oci_bind_by_name($taskStmt, ':auth_by', $authEmpCode);
    oci_bind_by_name($taskStmt, ':remarks', $remarks);
    oci_bind_by_name($taskStmt, ':task_id', $userTaskId);
    if (!oci_execute($taskStmt, OCI_NO_AUTO_COMMIT) || oci_num_rows($taskStmt) !== 1) {
        $error = oci_error($taskStmt);
        oci_rollback($sql___func___con);
        apiResponse(false, 'Unable to update authorization task status.' . (!empty($error['message']) ? ' ' . $error['message'] : ''), null, 500);
    }
    oci_free_statement($taskStmt);

    $jobSql = "UPDATE HR_JD SET STATUS = :job_status WHERE ID = :job_id";
    $jobStmt = oci_parse($sql___func___con, $jobSql);
    oci_bind_by_name($jobStmt, ':job_status', $jobStatus);
    oci_bind_by_name($jobStmt, ':job_id', $jobId);
    if (!oci_execute($jobStmt, OCI_NO_AUTO_COMMIT) || oci_num_rows($jobStmt) !== 1) {
        $error = oci_error($jobStmt);
        oci_rollback($sql___func___con);
        apiResponse(false, 'Unable to update Job Description status.' . (!empty($error['message']) ? ' ' . $error['message'] : ''), null, 500);
    }
    oci_free_statement($jobStmt);

    oci_commit($sql___func___con);
    $word = $decision === 'A' ? 'accepted' : 'rejected';
    apiResponse(true, "Job Description {$word} successfully.", ['status' => $jobStatus], 200);
} catch (Throwable $error) {
    if (!empty($sql___func___con)) {
        oci_rollback($sql___func___con);
    }
    apiResponse(false, 'Unable to process Job Description authorization: ' . $error->getMessage(), null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
