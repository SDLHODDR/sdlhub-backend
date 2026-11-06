<?php

require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../cors.php';
require_once __DIR__ . '/../../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/utils.php';
require_once __DIR__ . '/../../../config/hrmsAccess.php';

header('Content-Type: application/json; charset=utf-8');

function closeTaskResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        closeTaskResponse(405, [
            'status'  => false,
            'success' => false,
            'message' => 'Only POST requests are allowed.'
        ]);
    }

    // 1. Session verification
    $loggedInEmp = trim((string)(
        $_SESSION['EmpCode'] ??
        $_SESSION['emp_code'] ??
        $_SESSION['EMP_CODE'] ??
        $_SESSION['user_code'] ??
        $_SESSION['USER_CODE'] ??
        ''
    ));

    if ($loggedInEmp === '') {
        closeTaskResponse(401, [
            'status'  => false,
            'success' => false,
            'message' => 'Your session has expired. Please log in again.'
        ]);
    }

    // 2. Decode incoming payload (handles JSON or POST Form Data)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $empCode        = trim((string)($input['EMP_CODE'] ?? $input['empCode'] ?? ''));
    $tenureChangeId = filter_var($input['TENURE_CHG_ID'] ?? $input['tenureChangeId'] ?? '', FILTER_VALIDATE_INT);
    $taskId         = filter_var($input['TASK_ID'] ?? $input['taskId'] ?? '', FILTER_VALIDATE_INT);
    $authId         = filter_var($input['AUTH_ID'] ?? $input['id'] ?? '', FILTER_VALIDATE_INT);
    $remarks        = trim((string)($input['REMARKS'] ?? 'TENURE CHANGE LETTER PRINTED & CLOSED'));

    // 3. Fallbacks if IDs are not directly passed
    // If tenureChangeId is missing, resolve the latest active tenure record for the employee
    if (!$tenureChangeId && $empCode !== '') {
        $tenureStmt = oci_parse(
            $sql___func___con,
            "SELECT ID FROM (
                SELECT ID FROM HR_EMP_TENURE_CHANGE 
                WHERE EMP_CODE = :emp_code 
                ORDER BY ID DESC
             ) WHERE ROWNUM = 1"
        );
        oci_bind_by_name($tenureStmt, ':emp_code', $empCode);
        if (oci_execute($tenureStmt, OCI_NO_AUTO_COMMIT)) {
            $row = oci_fetch_assoc($tenureStmt);
            $tenureChangeId = (int)($row['ID'] ?? 0);
        }
        oci_free_statement($tenureStmt);
    }

    // Target task row in HR_USER_TASKS is AUTH_ID (the row ID); TASK_ID is the workflow ID (32)
    // and must never be used as a row ID.
    $targetUserTaskId = $authId;

    if (!$targetUserTaskId || $empCode === '') {
        closeTaskResponse(400, [
            'status'  => false,
            'success' => false,
            'message' => 'Employee code and task (AUTH_ID) are required to close the task.'
        ]);
    }

    loadHrmsAccess($loggedInEmp);
    if (!hrmsFindActionableTask(32, $empCode, $loggedInEmp, $targetUserTaskId)) {
        closeTaskResponse(403, [
            'status'  => false,
            'success' => false,
            'message' => 'You are not authorized to close this print task, or it is no longer open.'
        ]);
    }

    $upperLoggedIn = strtoupper($loggedInEmp);
    $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? '';

    /*
     * 4. Update HR_EMP_TENURE_CHANGE -> STATUS = 'C'
     */
    if ($tenureChangeId) {
        $updateTenureSql = "UPDATE HR_EMP_TENURE_CHANGE 
                            SET STATUS = 'C' 
                            WHERE ID = :tenure_id";
        $stmtTenure = oci_parse($sql___func___con, $updateTenureSql);
        if (!$stmtTenure) {
            $err = oci_error($sql___func___con);
            throw new RuntimeException($err['message'] ?? 'Failed to prepare tenure update query.');
        }

        oci_bind_by_name($stmtTenure, ':tenure_id', $tenureChangeId);

        if (!oci_execute($stmtTenure, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTenure);
            oci_free_statement($stmtTenure);
            throw new RuntimeException($err['message'] ?? 'Failed to update HR_EMP_TENURE_CHANGE.');
        }
        oci_free_statement($stmtTenure);
    }

    /*
     * 5. Update HR_USER_TASKS -> STATUS = 'C' (taskUpdate legacy logic)
     */
    $updateTaskSql = "UPDATE HR_USER_TASKS
                      SET STATUS   = 'C',
                          AUTH_BY  = :auth_by,
                          AUTH_ON  = SYSDATE,
                          REMARKS  = :remarks,
                          IP_ADDR  = :ip_addr
                      WHERE ID = :user_task_id
                        AND TASK_ID = 32
                        AND TRAN_CODE = :emp_code
                        AND STATUS = 'O'";

    $stmtTask = oci_parse($sql___func___con, $updateTaskSql);
    oci_bind_by_name($stmtTask, ':auth_by', $upperLoggedIn);
    oci_bind_by_name($stmtTask, ':remarks', $remarks);
    oci_bind_by_name($stmtTask, ':ip_addr', $ipAddress);
    oci_bind_by_name($stmtTask, ':user_task_id', $targetUserTaskId);
    oci_bind_by_name($stmtTask, ':emp_code', $empCode);

    if (!oci_execute($stmtTask, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtTask);
        oci_free_statement($stmtTask);
        throw new RuntimeException($err['message'] ?? 'Failed to update HR_USER_TASKS.');
    }

    $rowsAffected = oci_num_rows($stmtTask);
    oci_free_statement($stmtTask);

    if ($rowsAffected !== 1) {
        oci_rollback($sql___func___con);
        closeTaskResponse(409, [
            'status'  => false,
            'success' => false,
            'message' => 'This print task is not open or does not belong to this employee.'
        ]);
    }

    // 6. Commit transaction
    oci_commit($sql___func___con);

    closeTaskResponse(200, [
        'status'  => true,
        'success' => true,
        'message' => 'Tenure change print task closed successfully.',
        'data'    => [
            'tenure_change_id' => $tenureChangeId,
            'task_id'          => $targetUserTaskId,
            'emp_code'         => $empCode,
            'status'           => 'C'
        ]
    ]);

} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        @oci_rollback($sql___func___con);
    }

    if (function_exists('logOracleError')) {
        logOracleError($e->getMessage());
    }

    closeTaskResponse(500, [
        'status'  => false,
        'success' => false,
        'message' => 'Unable to close tenure print task: ' . $e->getMessage()
    ]);
}