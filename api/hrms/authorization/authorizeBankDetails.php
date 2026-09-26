<?php
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=UTF-8');

$authEmpCode = trim($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '');
if (empty($authEmpCode)) {
    apiResponse(false, 'Unauthorized access.', null, 401);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    /* ==========================================================
       1. GET: FETCH PENDING BANK REQUEST DETAILS
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
                R.BANK_NAME,
                R.BANK_BRANCH,
                R.BANK_IFSC,
                R.BANK_ACNO,
                R.BANK_NOMINEE,
                R.NEW_BANK_NAME,
                R.NEW_BANK_BRANCH,
                R.NEW_BANK_IFSC,
                R.NEW_BANK_ACNO,
                R.NEW_BANK_NOMINEE,
                TO_CHAR(R.CHG_ON, 'DD-Mon-YYYY HH24:MI') AS REQUESTED_ON,
                R.STATUS AS REQ_STATUS,
                T.ID AS USER_TASK_ID,
                T.TRAN_DESC,
                T.STATUS AS TASK_STATUS
            FROM HR_EMP_BANK_REQ R
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
            apiResponse(false, 'Bank authorization record not found.', null, 404);
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
        $selSql = "SELECT * FROM HR_EMP_BANK_REQ WHERE ID = :req_id";
        $selStmt = oci_parse($sql___func___con, $selSql);
        oci_bind_by_name($selStmt, ':req_id', $reqId);
        oci_execute($selStmt);
        $req = oci_fetch_assoc($selStmt);
        oci_free_statement($selStmt);

        if (!$req) {
            apiResponse(false, 'Request record not found.', null, 404);
        }

        if (strtoupper(trim($req['STATUS'] ?? '')) === 'C' || strtoupper(trim($req['STATUS'] ?? '')) === 'X') {
            apiResponse(false, 'This bank request has already been processed.', null, 400);
        }

        $targetStatus = ($decision === 'A') ? 'C' : 'X';
        $empCode      = trim((string)($req['EMP_CODE'] ?? ''));

        if (empty($empCode)) {
            apiResponse(false, 'Employee code is missing in the bank request.', null, 400);
        }

        $debugInfo = [
            'emp_code'          => $empCode,
            'decision'          => $decision,
            'bank_det_action'   => 'none',
            'bank_det_rows'     => 0,
            'bcs_employee_rows' => 0
        ];

        /* ------------------------------------------------------
           STEP A: IF ACCEPTED, UPDATE MASTER & PROFILE TABLES
        ------------------------------------------------------ */
        if ($decision === 'A') {
            $newBankName    = strtoupper(trim((string)($req['NEW_BANK_NAME'] ?? '')));
            $newBankBranch  = strtoupper(trim((string)($req['NEW_BANK_BRANCH'] ?? '')));
            $newBankIfsc    = strtoupper(trim((string)($req['NEW_BANK_IFSC'] ?? '')));
            $newBankAcno    = trim((string)($req['NEW_BANK_ACNO'] ?? ''));
            $newBankNominee = trim((string)($req['NEW_BANK_NOMINEE'] ?? ''));

            /* 1. Update/Insert HR_EMP_BANK_DET */
            $checkDetSql = "SELECT COUNT(*) AS CNT FROM HR_EMP_BANK_DET WHERE TRIM(EMP_CODE) = TRIM(:emp_code)";
            $checkStmt   = oci_parse($sql___func___con, $checkDetSql);
            oci_bind_by_name($checkStmt, ':emp_code', $empCode);
            oci_execute($checkStmt);
            $cntRow = oci_fetch_assoc($checkStmt);
            oci_free_statement($checkStmt);

            $existsInDet = intval($cntRow['CNT'] ?? 0) > 0;

            if ($existsInDet) {
                $debugInfo['bank_det_action'] = 'UPDATE';
                $updDetSql = "
                    UPDATE HR_EMP_BANK_DET
                    SET 
                        BANK_NAME     = :bank_name,
                        BANK_BRANCH   = :bank_branch,
                        BANK_IFSC     = :bank_ifsc,
                        BANK_ACNO     = :bank_acno,
                        BANK_NOMINEE  = :bank_nominee,
                        CHG_ON        = SYSDATE,
                        CHG_BY        = :chg_by
                    WHERE TRIM(EMP_CODE) = TRIM(:emp_code)
                ";
                $detStmt = oci_parse($sql___func___con, $updDetSql);
            } else {
                $debugInfo['bank_det_action'] = 'INSERT';
                $insDetSql = "
                    INSERT INTO HR_EMP_BANK_DET
                    (
                        EMP_CODE,
                        BANK_NAME,
                        BANK_BRANCH,
                        BANK_IFSC,
                        BANK_ACNO,
                        BANK_NOMINEE,
                        CHG_ON,
                        CHG_BY
                    )
                    VALUES
                    (
                        TRIM(:emp_code),
                        :bank_name,
                        :bank_branch,
                        :bank_ifsc,
                        :bank_acno,
                        :bank_nominee,
                        SYSDATE,
                        :chg_by
                    )
                ";
                $detStmt = oci_parse($sql___func___con, $insDetSql);
            }

            oci_bind_by_name($detStmt, ':bank_name', $newBankName);
            oci_bind_by_name($detStmt, ':bank_branch', $newBankBranch);
            oci_bind_by_name($detStmt, ':bank_ifsc', $newBankIfsc);
            oci_bind_by_name($detStmt, ':bank_acno', $newBankAcno);
            oci_bind_by_name($detStmt, ':bank_nominee', $newBankNominee);
            oci_bind_by_name($detStmt, ':chg_by', $authEmpCode);
            oci_bind_by_name($detStmt, ':emp_code', $empCode);

            if (!oci_execute($detStmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($detStmt);
                oci_rollback($sql___func___con);
                apiResponse(false, 'Failed to update HR_EMP_BANK_DET: ' . ($err['message'] ?? 'Unknown error'), null, 500);
            }

            $debugInfo['bank_det_rows'] = oci_num_rows($detStmt);
            oci_free_statement($detStmt);

            /* 2. Update HR_BCS_EMPLOYEE (Master Employee Table) */
            $updBcsSql = "
                UPDATE HR_BCS_EMPLOYEE
                SET 
                    BANK_NAME      = :bank_name,
                    AC_BRANCH_NAME = :bank_branch,
                    AC_IFSC_NO     = :bank_ifsc,
                    BANK_ACCT      = :bank_acno,
                    BANK_NOMINEE   = :bank_nominee,
                    CHG_ON         = SYSDATE,
                    CHG_BY         = :chg_by
                WHERE TRIM(EMP_CODE) = TRIM(:emp_code)
            ";
            $bcsStmt = oci_parse($sql___func___con, $updBcsSql);
            oci_bind_by_name($bcsStmt, ':bank_name', $newBankName);
            oci_bind_by_name($bcsStmt, ':bank_branch', $newBankBranch);
            oci_bind_by_name($bcsStmt, ':bank_ifsc', $newBankIfsc);
            oci_bind_by_name($bcsStmt, ':bank_acno', $newBankAcno);
            oci_bind_by_name($bcsStmt, ':bank_nominee', $newBankNominee);
            oci_bind_by_name($bcsStmt, ':chg_by', $authEmpCode);
            oci_bind_by_name($bcsStmt, ':emp_code', $empCode);

            if (!oci_execute($bcsStmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($bcsStmt);
                oci_rollback($sql___func___con);
                apiResponse(false, 'Failed to update BCS_EMPLOYEE: ' . ($err['message'] ?? 'Unknown error'), null, 500);
            }

            $debugInfo['bcs_employee_rows'] = oci_num_rows($bcsStmt);
            oci_free_statement($bcsStmt);
        }

        /* ------------------------------------------------------
           STEP B: UPDATE HR_EMP_BANK_REQ
        ------------------------------------------------------ */
        $updReqSql = "
            UPDATE HR_EMP_BANK_REQ
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
            apiResponse(false, 'Failed to update bank request status: ' . ($err['message'] ?? 'Unknown error'), null, 500);
        }
        oci_free_statement($updReqStmt);

        /* ------------------------------------------------------
           STEP C: UPDATE HR_USER_TASKS
        ------------------------------------------------------ */
        // Ensure both ID and TRAN_CODE conditions handle padding/type conversions
        if ($userTaskId > 0) {
            $updTaskSql = "
                UPDATE HR_USER_TASKS
                SET 
                    STATUS  = :status,
                    AUTH_ON = SYSDATE,
                    AUTH_BY = :auth_by,
                    REMARKS = :remarks
                WHERE ID = :task_id
            ";
            $updTaskStmt = oci_parse($sql___func___con, $updTaskSql);
            oci_bind_by_name($updTaskStmt, ':task_id', $userTaskId);
        } else {
            $updTaskSql = "
                UPDATE HR_USER_TASKS
                SET 
                    STATUS  = :status,
                    AUTH_ON = SYSDATE,
                    AUTH_BY = :auth_by,
                    REMARKS = :remarks
                WHERE TRIM(TRAN_CODE) = TRIM(TO_CHAR(:req_id))
                   OR REF_TASK_ID = :req_id
            ";
            $updTaskStmt = oci_parse($sql___func___con, $updTaskSql);
            oci_bind_by_name($updTaskStmt, ':req_id', $reqId);
        }

        oci_bind_by_name($updTaskStmt, ':status', $targetStatus);
        oci_bind_by_name($updTaskStmt, ':auth_by', $authEmpCode);
        oci_bind_by_name($updTaskStmt, ':remarks', $remarks);

        if (!oci_execute($updTaskStmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($updTaskStmt);
            oci_rollback($sql___func___con);
            apiResponse(false, 'Failed to update user task: ' . ($err['message'] ?? 'Unknown error'), null, 500);
        }

        // Verify that the task was actually closed
        $taskRowsAffected = oci_num_rows($updTaskStmt);
        oci_free_statement($updTaskStmt);

        if ($taskRowsAffected === 0) {
            // Task record wasn't found by user_task_id or TRAN_CODE, attempt update via TASK_ID on REQ
            $fallbackSql = "
                UPDATE HR_USER_TASKS
                SET STATUS = :status, AUTH_ON = SYSDATE, AUTH_BY = :auth_by, REMARKS = :remarks
                WHERE ID = (SELECT TASK_ID FROM HR_EMP_BANK_REQ WHERE ID = :req_id)
            ";
            $fbStmt = oci_parse($sql___func___con, $fallbackSql);
            oci_bind_by_name($fbStmt, ':status', $targetStatus);
            oci_bind_by_name($fbStmt, ':auth_by', $authEmpCode);
            oci_bind_by_name($fbStmt, ':remarks', $remarks);
            oci_bind_by_name($fbStmt, ':req_id', $reqId);
            oci_execute($fbStmt, OCI_NO_AUTO_COMMIT);
            oci_free_statement($fbStmt);
        }

        /* ------------------------------------------------------
           STEP D: COMMIT TRANSACTION
        ------------------------------------------------------ */
        oci_commit($sql___func___con);

        $actionWord = ($decision === 'A') ? 'approved' : 'rejected';
        apiResponse(
            true, 
            "Bank details update request {$actionWord} successfully.", 
            $debugInfo, 
            200
        );
    }
} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        oci_rollback($sql___func___con);
    }
    apiResponse(false, 'An internal error occurred: ' . $e->getMessage(), null, 500);
}