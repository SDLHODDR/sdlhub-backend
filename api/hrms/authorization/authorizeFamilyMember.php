<?php
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=UTF-8');

// Prevent indefinite hangs from deadlocks
set_time_limit(20);

$authEmpCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($authEmpCode)) {
    apiResponse(false, 'Unauthorized access.', null, 401);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    /* ==========================================================
       1. GET: FETCH FAMILY REQUEST DETAILS
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
                TO_CHAR(R.ASON_DATE, 'DD-Mon-YYYY') AS ASON_DATE,
                R.EMP_CODE,
                R.OLD_ID,
                R.FM_NAME,
                R.FM_RELATION,
                R.FM_CONTACT,
                R.FM_DEP,
                R.FM_OCCUPATION,
                TO_CHAR(R.DOB, 'DD-Mon-YYYY') AS DOB,
                R.AADHAAR,
                R.NEW_FM_NAME,
                R.NEW_FM_RELATION,
                R.NEW_FM_CONTACT,
                R.NEW_FM_DEP,
                R.NEW_FM_OCCUPATION,
                TO_CHAR(R.NEW_DOB, 'DD-Mon-YYYY') AS NEW_DOB,
                R.NEW_AADHAAR,
                R.DOC_NAME1,
                R.DOC_PATH1,
                R.REQ_ACTION,
                R.STATUS AS REQ_STATUS,
                T.ID AS USER_TASK_ID,
                T.TRAN_DESC,
                T.STATUS AS TASK_STATUS
            FROM HR_EMP_FAMILY_REQ R
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
            apiResponse(false, 'Family authorization record not found.', null, 404);
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
        $decision   = strtoupper(trim($input['decision'] ?? ''));
        $remarks    = trim($input['remarks'] ?? '');

        if ($reqId <= 0 || !in_array($decision, ['A', 'R'], true)) {
            apiResponse(false, 'Invalid authorization parameters.', null, 400);
        }

        if ($decision === 'R' && empty($remarks)) {
            apiResponse(false, 'Remarks are required when rejecting a request.', null, 400);
        }

        // --- STEP 1: SELECT REQ ---
        $selSql = "
            SELECT 
                ID,
                EMP_CODE,
                OLD_ID,
                REQ_ACTION,
                STATUS,
                NEW_FM_NAME,
                NEW_FM_RELATION,
                NEW_FM_CONTACT,
                NEW_FM_DEP,
                NEW_FM_OCCUPATION,
                TO_CHAR(NEW_DOB, 'YYYY-MM-DD') AS NEW_DOB_STR,
                NEW_AADHAAR
            FROM HR_EMP_FAMILY_REQ 
            WHERE ID = :req_id
        ";
        $selStmt = oci_parse($sql___func___con, $selSql);
        oci_bind_by_name($selStmt, ':req_id', $reqId);
        if (!oci_execute($selStmt)) {
            $err = oci_error($selStmt);
            apiResponse(false, 'Step 1 Select Failed: ' . $err['message'], null, 500);
        }
        $req = oci_fetch_assoc($selStmt);
        oci_free_statement($selStmt);

        if (!$req) {
            apiResponse(false, 'Request record not found.', null, 404);
        }

        if (in_array(strtoupper(trim($req['STATUS'])), ['C', 'X'], true)) {
            apiResponse(false, 'This task has already been processed.', null, 400);
        }

        $targetReqStatus  = ($decision === 'A') ? 'C' : 'X';
        $targetTaskStatus = ($decision === 'A') ? 'C' : 'X';

        /* ------------------------------------------------------
           STEP A: IF ACCEPTED, APPLY CHANGES TO HR_EMP_FAMILY_INFO
        ------------------------------------------------------ */
        if ($decision === 'A') {
            $reqAction = strtoupper(trim($req['REQ_ACTION']));
            $empCode   = trim($req['EMP_CODE']);
            $newDobStr = !empty($req['NEW_DOB_STR']) ? $req['NEW_DOB_STR'] : null;

            // Fetch EMP_ID to maintain table integrity
            $empOfficeSql = "SELECT EMP_ID FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :emp_code";
            $empOfficeStmt = oci_parse($sql___func___con, $empOfficeSql);
            oci_bind_by_name($empOfficeStmt, ':emp_code', $empCode);
            oci_execute($empOfficeStmt);
            $officeRow = oci_fetch_assoc($empOfficeStmt);
            oci_free_statement($empOfficeStmt);
            $empId = !empty($officeRow['EMP_ID']) ? intval($officeRow['EMP_ID']) : null;

            // 1. ADD NEW MEMBER ('A')
            if ($reqAction === 'A') {
                $insInfoSql = "
                    INSERT INTO HR_EMP_FAMILY_INFO
                    (
                        EMP_ID,
                        EMP_CODE,
                        FM_NAME,
                        FM_RELATION,
                        FM_CONTACT,
                        FM_DEP,
                        FM_OCCUPATION,
                        DOB,
                        AADHAAR,
                        CHG_ON,
                        CHG_BY,
                        STATUS
                    )
                    VALUES
                    (
                        :emp_id,
                        :emp_code,
                        :fm_name,
                        :fm_relation,
                        :fm_contact,
                        :fm_dep,
                        :fm_occupation,
                        CASE WHEN :dob_str IS NOT NULL THEN TO_DATE(:dob_str, 'YYYY-MM-DD') ELSE NULL END,
                        :aadhaar,
                        SYSDATE,
                        :chg_by,
                        '1'
                    )
                ";
                $insInfoStmt = oci_parse($sql___func___con, $insInfoSql);
                oci_bind_by_name($insInfoStmt, ':emp_id', $empId);
                oci_bind_by_name($insInfoStmt, ':emp_code', $empCode);
                oci_bind_by_name($insInfoStmt, ':fm_name', $req['NEW_FM_NAME']);
                oci_bind_by_name($insInfoStmt, ':fm_relation', $req['NEW_FM_RELATION']);
                oci_bind_by_name($insInfoStmt, ':fm_contact', $req['NEW_FM_CONTACT']);
                oci_bind_by_name($insInfoStmt, ':fm_dep', $req['NEW_FM_DEP']);
                oci_bind_by_name($insInfoStmt, ':fm_occupation', $req['NEW_FM_OCCUPATION']);
                oci_bind_by_name($insInfoStmt, ':dob_str', $newDobStr);
                oci_bind_by_name($insInfoStmt, ':aadhaar', $req['NEW_AADHAAR']);
                oci_bind_by_name($insInfoStmt, ':chg_by', $authEmpCode);

                if (!oci_execute($insInfoStmt, OCI_NO_AUTO_COMMIT)) {
                    $err = oci_error($insInfoStmt);
                    oci_rollback($sql___func___con);
                    oci_free_statement($insInfoStmt);
                    apiResponse(false, 'Failed Step A (Insert Member): ' . $err['message'], null, 500);
                }
                oci_free_statement($insInfoStmt);
            }

            // 2. EDIT EXISTING MEMBER ('E')
            elseif ($reqAction === 'E') {
                $oldId = intval($req['OLD_ID']);
                $updInfoSql = "
                    UPDATE HR_EMP_FAMILY_INFO
                    SET
                        FM_NAME       = NVL(:fm_name, FM_NAME),
                        FM_RELATION   = NVL(:fm_relation, FM_RELATION),
                        FM_CONTACT    = :fm_contact,
                        FM_DEP        = :fm_dep,
                        FM_OCCUPATION = :fm_occupation,
                        DOB           = CASE WHEN :dob_str IS NOT NULL THEN TO_DATE(:dob_str, 'YYYY-MM-DD') ELSE DOB END,
                        AADHAAR       = NVL(:aadhaar, AADHAAR),
                        CHG_ON        = SYSDATE,
                        CHG_BY        = :chg_by
                    WHERE ID = :old_id
                ";
                $updInfoStmt = oci_parse($sql___func___con, $updInfoSql);
                oci_bind_by_name($updInfoStmt, ':fm_name', $req['NEW_FM_NAME']);
                oci_bind_by_name($updInfoStmt, ':fm_relation', $req['NEW_FM_RELATION']);
                oci_bind_by_name($updInfoStmt, ':fm_contact', $req['NEW_FM_CONTACT']);
                oci_bind_by_name($updInfoStmt, ':fm_dep', $req['NEW_FM_DEP']);
                oci_bind_by_name($updInfoStmt, ':fm_occupation', $req['NEW_FM_OCCUPATION']);
                oci_bind_by_name($updInfoStmt, ':dob_str', $newDobStr);
                oci_bind_by_name($updInfoStmt, ':aadhaar', $req['NEW_AADHAAR']);
                oci_bind_by_name($updInfoStmt, ':chg_by', $authEmpCode);
                oci_bind_by_name($updInfoStmt, ':old_id', $oldId);

                if (!oci_execute($updInfoStmt, OCI_NO_AUTO_COMMIT)) {
                    $err = oci_error($updInfoStmt);
                    oci_rollback($sql___func___con);
                    oci_free_statement($updInfoStmt);
                    apiResponse(false, 'Failed Step A (Update Member): ' . $err['message'], null, 500);
                }
                oci_free_statement($updInfoStmt);
            }

            // 3. REMOVE EXISTING MEMBER ('D') -> Sets STATUS = 'd'
            elseif ($reqAction === 'D') {
                $oldId = intval($req['OLD_ID']);
                $delInfoSql = "
                    UPDATE HR_EMP_FAMILY_INFO
                    SET 
                        STATUS = 'd',
                        CHG_ON = SYSDATE,
                        CHG_BY = :chg_by
                    WHERE ID = :old_id
                ";
                $delInfoStmt = oci_parse($sql___func___con, $delInfoSql);
                oci_bind_by_name($delInfoStmt, ':chg_by', $authEmpCode);
                oci_bind_by_name($delInfoStmt, ':old_id', $oldId);

                if (!oci_execute($delInfoStmt, OCI_NO_AUTO_COMMIT)) {
                    $err = oci_error($delInfoStmt);
                    oci_rollback($sql___func___con);
                    oci_free_statement($delInfoStmt);
                    apiResponse(false, 'Failed Step A (Delete Member): ' . $err['message'], null, 500);
                }
                oci_free_statement($delInfoStmt);
            }
        }

        /* ------------------------------------------------------
           STEP B: UPDATE HR_EMP_FAMILY_REQ
        ------------------------------------------------------ */
        $updReqSql = "
            UPDATE HR_EMP_FAMILY_REQ
            SET 
                STATUS  = :status,
                AUTH_ON = SYSDATE,
                AUTH_BY = :auth_by
            WHERE ID = :req_id
        ";
        $updReqStmt = oci_parse($sql___func___con, $updReqSql);
        oci_bind_by_name($updReqStmt, ':status', $targetReqStatus);
        oci_bind_by_name($updReqStmt, ':auth_by', $authEmpCode);
        oci_bind_by_name($updReqStmt, ':req_id', $reqId);

        if (!oci_execute($updReqStmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($updReqStmt);
            oci_rollback($sql___func___con);
            oci_free_statement($updReqStmt);
            apiResponse(false, 'Failed Step B (Update Request): ' . $err['message'], null, 500);
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
        oci_bind_by_name($updTaskStmt, ':status', $targetTaskStatus);
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
            oci_free_statement($updTaskStmt);
            apiResponse(false, 'Failed Step C (Update Task): ' . $err['message'], null, 500);
        }
        oci_free_statement($updTaskStmt);

        /* ------------------------------------------------------
           STEP D: COMMIT TRANSACTION
        ------------------------------------------------------ */
        if (!oci_commit($sql___func___con)) {
            $err = oci_error($sql___func___con);
            oci_rollback($sql___func___con);
            apiResponse(false, 'Failed Step D (Commit): ' . $err['message'], null, 500);
        }

        $actionWord = ($decision === 'A') ? 'approved' : 'rejected';
        apiResponse(true, "Family member change request {$actionWord} successfully.", null, 200);
    }
} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        oci_rollback($sql___func___con);
    }
    apiResponse(false, 'An internal error occurred: ' . $e->getMessage(), null, 500);
}