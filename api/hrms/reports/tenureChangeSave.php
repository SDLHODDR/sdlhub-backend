<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

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
                ORDER BY CASE WHEN STATUS = 'A' THEN 1 ELSE 2 END, ID DESC
             ) WHERE ROWNUM = 1"
        );
        oci_bind_by_name($tenureStmt, ':emp_code', $empCode);
        if (oci_execute($tenureStmt, OCI_NO_AUTO_COMMIT)) {
            $row = oci_fetch_assoc($tenureStmt);
            $tenureChangeId = (int)($row['ID'] ?? 0);
        }
        oci_free_statement($tenureStmt);
    }

    // Target task row in HR_USER_TASKS:
    // In legacy code: $task_id in taskUpdate() represents HR_USER_TASKS.ID (or TASK_ID)
    $targetUserTaskId = $authId ?: $taskId;

    if (!$tenureChangeId && !$targetUserTaskId) {
        closeTaskResponse(400, [
            'status'  => false,
            'success' => false,
            'message' => 'Tenure Change ID or Task ID is required to close the task.'
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
    if ($targetUserTaskId) {
        // First attempt updating by HR_USER_TASKS.ID
        $updateTaskSql = "UPDATE HR_USER_TASKS 
                          SET STATUS   = 'C',
                              AUTH_BY  = :auth_by,
                              AUTH_ON  = SYSDATE,
                              REMARKS  = :remarks,
                              IP_ADDR  = :ip_addr
                          WHERE ID = :user_task_id";

        $stmtTask = oci_parse($sql___func___con, $updateTaskSql);
        oci_bind_by_name($stmtTask, ':auth_by', $upperLoggedIn);
        oci_bind_by_name($stmtTask, ':remarks', $remarks);
        oci_bind_by_name($stmtTask, ':ip_addr', $ipAddress);
        oci_bind_by_name($stmtTask, ':user_task_id', $targetUserTaskId);

        if (!oci_execute($stmtTask, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTask);
            oci_free_statement($stmtTask);
            throw new RuntimeException($err['message'] ?? 'Failed to update HR_USER_TASKS.');
        }

        $rowsAffected = oci_num_rows($stmtTask);
        oci_free_statement($stmtTask);

        // If no row was updated by ID, check if targetUserTaskId was TASK_ID and update by TRAN_CODE/TASK_ID
        if ($rowsAffected === 0 && $empCode !== '') {
            $updateAltTaskSql = "UPDATE HR_USER_TASKS 
                                 SET STATUS   = 'C',
                                     AUTH_BY  = :auth_by,
                                     AUTH_ON  = SYSDATE,
                                     REMARKS  = :remarks,
                                     IP_ADDR  = :ip_addr
                                 WHERE (TRAN_CODE = :emp_code OR EMP_CODE_FOR = :emp_code)
                                   AND (TASK_ID = :task_id OR ID = :task_id)
                                   AND STATUS = 'O'";

            $stmtAltTask = oci_parse($sql___func___con, $updateAltTaskSql);
            oci_bind_by_name($stmtAltTask, ':auth_by', $upperLoggedIn);
            oci_bind_by_name($stmtAltTask, ':remarks', $remarks);
            oci_bind_by_name($stmtAltTask, ':ip_addr', $ipAddress);
            oci_bind_by_name($stmtAltTask, ':emp_code', $empCode);
            oci_bind_by_name($stmtAltTask, ':task_id', $taskId);

            oci_execute($stmtAltTask, OCI_NO_AUTO_COMMIT);
            oci_free_statement($stmtAltTask);
        }
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