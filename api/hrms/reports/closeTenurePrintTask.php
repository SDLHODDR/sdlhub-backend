<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=utf-8');

function closeTenureResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function closeTenureQuery(string $sql, array $binds = []): array
{
    global $sql___func___con;

    $stmt = oci_parse($sql___func___con, $sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare database query.');
    }

    foreach ($binds as $key => &$value) {
        oci_bind_by_name($stmt, $key, $value);
    }
    unset($value);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException($error['message'] ?? 'Database query failed.');
    }

    $rows = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = $row;
    }

    oci_free_statement($stmt);

    return $rows;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        closeTenureResponse(405, [
            'success' => false,
            'message' => 'Only POST requests are allowed.'
        ]);
    }

    $loggedInEmp = trim((string)(
        $_SESSION['emp_code'] ??
        $_SESSION['EmpCode'] ??
        ''
    ));

    if ($loggedInEmp === '') {
        closeTenureResponse(401, [
            'success' => false,
            'message' => 'Your session has expired. Please log in again.'
        ]);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $empCode = trim((string)($input['EMP_CODE'] ?? ''));

    $tenureId = filter_var(
        $input['TENURE_CHG_ID'] ?? '',
        FILTER_VALIDATE_INT
    );

    $taskId = filter_var(
        $input['TASK_ID'] ?? '',
        FILTER_VALIDATE_INT
    );

    if ($empCode === '' || !$tenureId || !$taskId) {
        closeTenureResponse(400, [
            'success' => false,
            'message' => 'Employee code, tenure change ID and task ID are required.'
        ]);
    }

    /*
     * Verify the approved tenure record.
     * FOR UPDATE prevents concurrent changes while this transaction runs.
     */
    $tenureRows = closeTenureQuery("
        SELECT ID, EMP_CODE, STATUS
        FROM HR_EMP_TENURE_CHANGE
        WHERE ID = :tenure_id
          AND EMP_CODE = :emp_code
          AND STATUS = 'A'
        FOR UPDATE
    ", [
        ':tenure_id' => $tenureId,
        ':emp_code' => $empCode
    ]);

    if (!$tenureRows) {
        closeTenureResponse(409, [
            'success' => false,
            'message' => 'The approved tenure record was not found or has already been closed.'
        ]);
    }

    /*
     * Verify that this task belongs to the specified employee and tenure record.
     */
    $taskRows = closeTenureQuery("
        SELECT ID, TENURE_CHG_ID, EMP_CODE, TASK_ID, STATUS
        FROM HR_EMP_TENURE_MGR
        WHERE TASK_ID = :task_id
          AND TENURE_CHG_ID = :tenure_id
          AND EMP_CODE = :emp_code
        FOR UPDATE
    ", [
        ':task_id' => $taskId,
        ':tenure_id' => $tenureId,
        ':emp_code' => $empCode
    ]);

    if (!$taskRows) {
        closeTenureResponse(404, [
            'success' => false,
            'message' => 'The task is not linked to this employee and tenure record.'
        ]);
    }

    /*
     * IMPORTANT:
     * Replace the status condition below with your real task-open status
     * once the permitted statuses in HR_EMP_TENURE_MGR are confirmed.
     */

    if (!oci_execute(oci_parse($sql___func___con, 'SELECT 1 FROM DUAL'), OCI_NO_AUTO_COMMIT)) {
        throw new RuntimeException('Unable to verify database connection.');
    }

    /*
     * Start the transaction before making either update.
     */
    $tenureUpdate = oci_parse($sql___func___con, "
        UPDATE HR_EMP_TENURE_CHANGE
        SET STATUS = 'C'
        WHERE ID = :tenure_id
          AND EMP_CODE = :emp_code
          AND STATUS = 'A'
    ");

    oci_bind_by_name($tenureUpdate, ':tenure_id', $tenureId);
    oci_bind_by_name($tenureUpdate, ':emp_code', $empCode);

    if (!oci_execute($tenureUpdate, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($tenureUpdate);
        throw new RuntimeException($error['message'] ?? 'Unable to close tenure record.');
    }

    if (oci_num_rows($tenureUpdate) !== 1) {
        throw new RuntimeException('The tenure record could not be closed.');
    }

    oci_free_statement($tenureUpdate);

    /*
     * Use the existing taskUpdate() helper, which is part of your legacy
     * task workflow. It must use the same Oracle connection/transaction.
     */
    taskUpdate('C', '', $taskId, null, null);

    if (!oci_commit($sql___func___con)) {
        throw new RuntimeException('Unable to commit tenure task closure.');
    }

    closeTenureResponse(200, [
        'success' => true,
        'message' => 'Tenure letter print task closed successfully.',
        'tenure_change_id' => $tenureId,
        'task_id' => $taskId,
        'status' => 'C'
    ]);

} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        @oci_rollback($sql___func___con);
    }

    if (function_exists('logOracleError')) {
        logOracleError($e->getMessage());
    }

    closeTenureResponse(500, [
        'success' => false,
        'message' => 'Unable to close the tenure print task.'
    ]);
}