<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=UTF-8');

$authEmpCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($authEmpCode)) {
    apiResponse(false, 'Unauthorized access.', null, 401);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    /* ==========================================================
       1. GET: FETCH PENDING ADDRESS CHANGE DETAILS
    ========================================================== */
    if ($method === 'GET') {
        $taskId = intval($_GET['task_id'] ?? 0);
        $reqId  = intval($_GET['req_id'] ?? 0);

        if ($taskId <= 0 && $reqId <= 0) {
            apiResponse(false, 'Task or Request identifier missing.', null, 400);
        }

        $sql = "
            SELECT 
                R.ID AS REQ_ID,
                R.EMP_CODE,
                TO_CHAR(R.ASON_DATE, 'DD-Mon-YYYY HH24:MI') AS REQUESTED_ON,
                R.ADDRESS,
                R.CITY,
                R.STATE,
                R.PINCODE,
                R.PERMNT_ADDRESS,
                R.PERMNT_CITY,
                R.PERMNT_STATE,
                R.PERMNT_PINCODE,
                R.NEW_ADDRESS,
                R.NEW_CITY,
                R.NEW_STATE,
                R.NEW_PINCODE,
                R.NEW_PERMNT_ADDRESS,
                R.NEW_PERMNT_CITY,
                R.NEW_PERMNT_STATE,
                R.NEW_PERMNT_PINCODE,
                R.DOC_NAME1,
                R.DOC_PATH1,
                R.STATUS AS REQ_STATUS,
                T.ID AS USER_TASK_ID,
                T.TRAN_DESC,
                T.STATUS AS TASK_STATUS
            FROM HR_EMP_INFO_REQ R
            LEFT JOIN HR_USER_TASKS T ON T.TRAN_CODE = TO_CHAR(R.ID)
            WHERE " . ($taskId > 0 ? "T.ID = :taskId" : "R.ID = :reqId");

        $stmt = oci_parse($sql___func___con, $sql);
        if ($taskId > 0) {
            oci_bind_by_name($stmt, ':taskId', $taskId);
        } else {
            oci_bind_by_name($stmt, ':reqId', $reqId);
        }
        oci_execute($stmt);
        $row = oci_fetch_assoc($stmt);
        oci_free_statement($stmt);

        if (!$row) {
            apiResponse(false, 'Address authorization record not found.', null, 404);
        }

        apiResponse(true, 'Data fetched successfully.', $row, 200);
    }

    /* ==========================================================
       2. POST: ACCEPT OR REJECT REQUEST
    ========================================================== */
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $userTaskId = intval($input['user_task_id'] ?? 0);
        $reqId      = intval($input['req_id'] ?? 0);
        $decision   = strtoupper(trim($input['decision'] ?? '')); // 'A' (Accept) or 'R' (Reject)
        $remarks    = trim($input['remarks'] ?? '');

        if ($reqId <= 0 || !in_array($decision, ['A', 'R'], true)) {
            apiResponse(false, 'Invalid authorization parameters.', null, 400);
        }

        if ($decision === 'R' && empty($remarks)) {
            apiResponse(false, 'Remarks are required when rejecting a request.', null, 400);
        }

        // Fetch request details
        $selSql = "SELECT * FROM HR_EMP_INFO_REQ WHERE ID = :req_id";
        $selStmt = oci_parse($sql___func___con, $selSql);
        oci_bind_by_name($selStmt, ':req_id', $reqId);
        oci_execute($selStmt);
        $req = oci_fetch_assoc($selStmt);
        oci_free_statement($selStmt);

        if (!$req) {
            apiResponse(false, 'Request record not found.', null, 404);
        }

        if (strtoupper($req['STATUS']) === 'C' || strtoupper($req['STATUS']) === 'X') {
            apiResponse(false, 'This address request has already been processed.', null, 400);
        }

        $targetStatus = ($decision === 'A') ? 'C' : 'X';
        $empCode = $req['EMP_CODE'];

        /* ------------------------------------------------------
           STEP A: IF ACCEPTED, UPDATE MASTER (HR_EMPLOYEE_INFO)
        ------------------------------------------------------ */
        if ($decision === 'A') {
            $updMasterSql = "
                UPDATE HR_EMPLOYEE_INFO
                SET
                    ADDRESS        = :new_address,
                    CITY           = :new_city,
                    STATE          = :new_state,
                    PINCODE        = :new_pincode,
                    PERMNT_ADDRESS = :new_permnt_address,
                    PERMNT_CITY    = :new_permnt_city,
                    PERMNT_STATE   = :new_permnt_state,
                    PERMNT_PINCODE = :new_permnt_pincode,
                    CHG_ON         = SYSDATE,
                    CHG_BY         = :chg_by
                WHERE EMP_CODE = :emp_code
            ";
            $updMasterStmt = oci_parse($sql___func___con, $updMasterSql);
            oci_bind_by_name($updMasterStmt, ':new_address', $req['NEW_ADDRESS']);
            oci_bind_by_name($updMasterStmt, ':new_city', $req['NEW_CITY']);
            oci_bind_by_name($updMasterStmt, ':new_state', $req['NEW_STATE']);
            oci_bind_by_name($updMasterStmt, ':new_pincode', $req['NEW_PINCODE']);
            oci_bind_by_name($updMasterStmt, ':new_permnt_address', $req['NEW_PERMNT_ADDRESS']);
            oci_bind_by_name($updMasterStmt, ':new_permnt_city', $req['NEW_PERMNT_CITY']);
            oci_bind_by_name($updMasterStmt, ':new_permnt_state', $req['NEW_PERMNT_STATE']);
            oci_bind_by_name($updMasterStmt, ':new_permnt_pincode', $req['NEW_PERMNT_PINCODE']);
            oci_bind_by_name($updMasterStmt, ':chg_by', $authEmpCode);
            oci_bind_by_name($updMasterStmt, ':emp_code', $empCode);

            if (!oci_execute($updMasterStmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($updMasterStmt);
                oci_rollback($sql___func___con);
                apiResponse(false, 'Failed to update HR_EMPLOYEE_INFO: ' . $err['message'], null, 500);
            }
            oci_free_statement($updMasterStmt);
        }

        /* ------------------------------------------------------
           STEP B: UPDATE HR_EMP_INFO_REQ
        ------------------------------------------------------ */
        $updReqSql = "
            UPDATE HR_EMP_INFO_REQ
            SET 
                STATUS  = :status,
                AUTH_ON = SYSDATE,
                AUTH_BY = :auth_by
            WHERE ID = :req_id
        ";
        $updReqStmt = oci_parse($sql___func___con, $updReqSql);
        oci_bind_by_name($updReqStmt, ':status', $targetStatus);
        oci_bind_by_name($updReqStmt, ':auth_by', $authEmpCode);
        oci_bind_by_name($updReqStmt, ':req_id', $reqId);

        if (!oci_execute($updReqStmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($updReqStmt);
            oci_rollback($sql___func___con);
            apiResponse(false, 'Failed to update request status: ' . $err['message'], null, 500);
        }
        oci_free_statement($updReqStmt);

        /* ------------------------------------------------------
           STEP C: UPDATE HR_USER_TASKS
        ------------------------------------------------------ */
        $taskWhere = ($userTaskId > 0) ? "ID = :task_id" : "TRAN_CODE = TO_CHAR(:req_id)";
        $updTaskSql = "
            UPDATE HR_USER_TASKS
            SET 
                STATUS  = :status,
                AUTH_ON = SYSDATE,
                AUTH_BY = :auth_by,
                REMARKS = :remarks
            WHERE $taskWhere
        ";
        $updTaskStmt = oci_parse($sql___func___con, $updTaskSql);
        oci_bind_by_name($updTaskStmt, ':status', $targetStatus);
        oci_bind_by_name($updTaskStmt, ':auth_by', $authEmpCode);
        oci_bind_by_name($updTaskStmt, ':remarks', $remarks);
        if ($userTaskId > 0) {
            oci_bind_by_name($updTaskStmt, ':task_id', $userTaskId);
        } else {
            oci_bind_by_name($updTaskStmt, ':req_id', $reqId);
        }

        if (!oci_execute($updTaskStmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($updTaskStmt);
            oci_rollback($sql___func___con);
            apiResponse(false, 'Failed to update user task: ' . $err['message'], null, 500);
        }
        oci_free_statement($updTaskStmt);

        /* ------------------------------------------------------
           STEP D: COMMIT TRANSACTION
        ------------------------------------------------------ */
        oci_commit($sql___func___con);

        $actionWord = ($decision === 'A') ? 'approved' : 'rejected';
        apiResponse(true, "Address change request {$actionWord} successfully.", null, 200);
    }
} catch (Throwable $e) {
    if ($sql___func___con) {
        oci_rollback($sql___func___con);
    }
    apiResponse(false, 'An internal error occurred: ' . $e->getMessage(), null, 500);
}