<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_eportal();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? $_SESSION['EMP_CODE'] ?? $_SESSION['employee_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, 'Unauthorized access.', null, 401);
    }

    /* ===========================================
       READ & PARSE INPUT (Supports FormData & JSON)
    =========================================== */

    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];
    }

    if (!is_array($data)) {
        apiResponse(false, 'Invalid request data.', null, 400);
    }

    /* ===========================================
       READ NEW BANK DETAILS
    =========================================== */

    $newBankName = strtoupper(trim((string) ($data['bank_name'] ?? '')));
    $newBranch   = strtoupper(trim((string) ($data['bank_branch'] ?? '')));
    $newIfsc     = strtoupper(trim((string) ($data['bank_ifsc'] ?? '')));
    $newAcno     = trim((string) ($data['bank_acno'] ?? ''));
    $newNominee  = trim((string) ($data['bank_nominee'] ?? ''));

    /* ===========================================
       VALIDATION
    =========================================== */

    if (
        $newBankName === '' ||
        $newBranch === '' ||
        $newIfsc === '' ||
        $newAcno === ''
    ) {
        apiResponse(false, 'Please enter all required bank details.', null, 400);
    }

    if (!preg_match('/^[A-Z0-9]+$/', $newIfsc) || strlen($newIfsc) !== 11) {
        apiResponse(false, 'IFSC must be exactly 11 alphanumeric characters.', null, 400);
    }

    if (!preg_match('/^[0-9]+$/', $newAcno)) {
        apiResponse(false, 'Account number should contain digits only.', null, 400);
    }

    /* ===========================================
       PRE-FETCH TASK MASTER & OFFICE DETAILS
    =========================================== */

    $task = singRec("
        SELECT
            t.*,
            TO_CHAR(TRUNC(SYSDATE) + t.EXPIRY_DAYS, 'YYYY-MM-DD HH24:MI:SS') AS EXPDT
        FROM EPT_HR_TASK_MASTER t
        WHERE TASK_LABEL = 'Change Bank Info'
    ");

    if (empty($task) || empty($task['ID']) || empty($task['EXPDT'])) {
        apiResponse(false, 'Task configuration not found or incomplete for bank details authorization.', null, 500);
    }

    $empOfficeInfo = singRec("
        SELECT o.DIVSN_ID, o.DEPT_ID
        FROM EPT_HR_EMP_OFFICE_DET o
        WHERE o.EMP_CODE = '{$empCode}'
    ");

    $divsnId = !empty($empOfficeInfo['DIVSN_ID']) ? (int) $empOfficeInfo['DIVSN_ID'] : 0;
    $deptId  = !empty($empOfficeInfo['DEPT_ID'])  ? (int) $empOfficeInfo['DEPT_ID']  : 0;

    /* ===========================================
       FETCH CURRENT BANK DETAILS
    =========================================== */

    $oldSql = "
        SELECT
            BANK_NAME,
            AC_BRANCH_NAME,
            AC_IFSC_NO,
            BANK_ACCT,
            BANK_NOMINEE
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = :emp_code
    ";

    $stmtOld = oci_parse($sql___func___con, $oldSql);
    oci_bind_by_name($stmtOld, ':emp_code', $empCode);
    oci_execute($stmtOld);
    $oldRec = oci_fetch_assoc($stmtOld);
    oci_free_statement($stmtOld);

    if (!$oldRec) {
        apiResponse(false, 'Employee profile record not found.', null, 404);
    }

    $oldBankName = strtoupper(trim((string) ($oldRec['BANK_NAME'] ?? '')));
    $oldBranch   = strtoupper(trim((string) ($oldRec['AC_BRANCH_NAME'] ?? '')));
    $oldIfsc     = strtoupper(trim((string) ($oldRec['AC_IFSC_NO'] ?? '')));
    $oldAcno     = trim((string) ($oldRec['BANK_ACCT'] ?? ''));
    $oldNominee  = trim((string) ($oldRec['BANK_NOMINEE'] ?? ''));

    /* ===========================================
       CHECK FOR EXISTING PENDING REQUEST
    =========================================== */

    $checkPendingSql = "
        SELECT
            ID,
            NEW_BANK_NAME,
            NEW_BANK_BRANCH,
            NEW_BANK_IFSC,
            NEW_BANK_ACNO,
            NEW_BANK_NOMINEE,
            DOC_NAME1,
            TO_CHAR(CHG_ON, 'DD-Mon-YYYY HH24:MI') AS REQ_DATE
        FROM EPT_HR_EMP_BANK_REQ
        WHERE EMP_CODE = :emp_code
          AND STATUS IN ('N', 'T')
        ORDER BY ID DESC
    ";

    $stmtPending = oci_parse($sql___func___con, $checkPendingSql);
    oci_bind_by_name($stmtPending, ':emp_code', $empCode);
    oci_execute($stmtPending);
    $pendingRec = oci_fetch_assoc($stmtPending);
    oci_free_statement($stmtPending);

    if ($pendingRec) {
        apiResponse(
            false,
            'A bank details update request is already pending for authorization.',
            [
                'pending_req_id' => $pendingRec['ID'],
                'req_date'       => $pendingRec['REQ_DATE'],
                'pending_data'   => [
                    'bank_name'    => $pendingRec['NEW_BANK_NAME'] ?? '',
                    'bank_branch'  => $pendingRec['NEW_BANK_BRANCH'] ?? '',
                    'bank_ifsc'    => $pendingRec['NEW_BANK_IFSC'] ?? '',
                    'bank_acno'    => $pendingRec['NEW_BANK_ACNO'] ?? '',
                    'bank_nominee' => $pendingRec['NEW_BANK_NOMINEE'] ?? '',
                    'document'     => $pendingRec['DOC_NAME1'] ?? '',
                ]
            ],
            409
        );
    }

    /* ===========================================
       HANDLE DOCUMENT UPLOAD (1MB Max Limit)
    =========================================== */

    $docName1 = null;
    $docPath1 = null;

    if (isset($_FILES['document']) && $_FILES['document']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            apiResponse(false, 'Document upload error occurred.', null, 400);
        }

        $fileTmpPath     = $_FILES['document']['tmp_name'];
        $docOriginalName = basename($_FILES['document']['name']);
        $fileSize        = $_FILES['document']['size'];
        $fileExtension   = strtolower(pathinfo($docOriginalName, PATHINFO_EXTENSION));

        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($fileExtension, $allowedExtensions, true)) {
            apiResponse(false, 'Invalid file format. Allowed types: ' . implode(', ', $allowedExtensions), null, 400);
        }

        // 1MB Max Upload Size
        if ($fileSize > 1 * 1024 * 1024) {
            apiResponse(false, 'Uploaded document cannot exceed 1MB.', null, 400);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $fileTmpPath);
        finfo_close($finfo);

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($mime, $allowedMimes, true)) {
            apiResponse(false, 'Invalid file type detected.', null, 400);
        }

        $uploadDir = '/mnt/documents/uploads/bank_document/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // DOC_NAME1 is VARCHAR2(20 BYTE)
        $docName1 = substr(pathinfo($docOriginalName, PATHINFO_FILENAME), 0, 15) . '.' . $fileExtension;

        // DOC_PATH1 is VARCHAR2(50 BYTE)
        // Format: "B_00575_1726918293_ab12.pdf" (approx 26-28 chars)
        $docPath1 = 'B_' . $empCode . '_' . time() . '_' . substr(bin2hex(random_bytes(2)), 0, 4) . '.' . $fileExtension;
        $destPath = $uploadDir . $docPath1;

        if (!move_uploaded_file($fileTmpPath, $destPath)) {
            apiResponse(false, 'Failed to save uploaded document.', null, 500);
        }
    }

    /* ===========================================
       CHECK FOR DATA CHANGES
    =========================================== */

    $bankNameChanged = ($oldBankName !== $newBankName);
    $branchChanged   = ($oldBranch !== $newBranch);
    $ifscChanged     = ($oldIfsc !== $newIfsc);
    $accountChanged  = ($oldAcno !== $newAcno);
    $nomineeChanged  = ($oldNominee !== $newNominee);
    $hasNewDocument  = ($docName1 !== null);

    $hasChange = (
        $bankNameChanged ||
        $branchChanged ||
        $ifscChanged ||
        $accountChanged ||
        $nomineeChanged ||
        $hasNewDocument
    );

    if (!$hasChange) {
        apiResponse(
            false,
            'No changes found in bank details.',
            [
                'bank_name_changed' => $bankNameChanged,
                'branch_changed'    => $branchChanged,
                'ifsc_changed'      => $ifscChanged,
                'account_changed'   => $accountChanged,
                'nominee_changed'   => $nomineeChanged
            ],
            400
        );
    }

    /* ===========================================
       RESOLVE CLIENT IP ADDRESS
    =========================================== */

    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['HTTP_CLIENT_IP'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? 'UNKNOWN';

    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }
    $clientIp = substr(trim($clientIp), 0, 100);

    /* ===========================================
       STEP 1: INSERT INTO EPT_HR_EMP_BANK_REQ
    =========================================== */

    $reqId = null;
    $status = 'N';

    $insertReqSql = "
        INSERT INTO EPT_HR_EMP_BANK_REQ
        (
            EMP_CODE,
            BANK_NAME,
            BANK_BRANCH,
            BANK_IFSC,
            BANK_ACNO,
            BANK_NOMINEE,
            NEW_BANK_NAME,
            NEW_BANK_BRANCH,
            NEW_BANK_IFSC,
            NEW_BANK_ACNO,
            NEW_BANK_NOMINEE,
            DOC_NAME1,
            DOC_PATH1,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            :emp_code,
            :old_bank_name,
            :old_bank_branch,
            :old_bank_ifsc,
            :old_bank_acno,
            :old_bank_nominee,
            :new_bank_name,
            :new_bank_branch,
            :new_bank_ifsc,
            :new_bank_acno,
            :new_bank_nominee,
            :doc_name1,
            :doc_path1,
            :status,
            SYSDATE,
            :chg_by
        )
        RETURNING ID INTO :req_id
    ";

    $stmtReq = oci_parse($sql___func___con, $insertReqSql);
    if (!$stmtReq) {
        $err = oci_error($sql___func___con);
        logOracleError($err, $insertReqSql);
        apiResponse(false, 'Failed to prepare bank request statement.', null, 500);
    }

    oci_bind_by_name($stmtReq, ':emp_code', $empCode);
    oci_bind_by_name($stmtReq, ':old_bank_name', $oldBankName);
    oci_bind_by_name($stmtReq, ':old_bank_branch', $oldBranch);
    oci_bind_by_name($stmtReq, ':old_bank_ifsc', $oldIfsc);
    oci_bind_by_name($stmtReq, ':old_bank_acno', $oldAcno);
    oci_bind_by_name($stmtReq, ':old_bank_nominee', $oldNominee);

    oci_bind_by_name($stmtReq, ':new_bank_name', $newBankName);
    oci_bind_by_name($stmtReq, ':new_bank_branch', $newBranch);
    oci_bind_by_name($stmtReq, ':new_bank_ifsc', $newIfsc);
    oci_bind_by_name($stmtReq, ':new_bank_acno', $newAcno);
    oci_bind_by_name($stmtReq, ':new_bank_nominee', $newNominee);

    oci_bind_by_name($stmtReq, ':doc_name1', $docName1);
    oci_bind_by_name($stmtReq, ':doc_path1', $docPath1);
    oci_bind_by_name($stmtReq, ':status', $status);
    oci_bind_by_name($stmtReq, ':chg_by', $empCode);
    oci_bind_by_name($stmtReq, ':req_id', $reqId, 32);

    if (!oci_execute($stmtReq, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtReq);
        logOracleError($err, $insertReqSql);
        oci_rollback($sql___func___con);
        oci_free_statement($stmtReq);
        apiResponse(false, 'Unable to record bank update request: ' . ($err['message'] ?? ''), null, 500);
    }
    oci_free_statement($stmtReq);

    if (empty($reqId)) {
        oci_rollback($sql___func___con);
        apiResponse(false, 'Failed to generate authorization request ID.', null, 500);
    }

    /* ===========================================
       STEP 2: INSERT INTO EPT_HR_USER_TASKS
    =========================================== */

    $tranDesc = "Bank details update request submitted by employee {$empCode} for authorization.";
    $userTaskId = null;

    $insertTaskSql = "
        INSERT INTO EPT_HR_USER_TASKS
        (
            TASK_ID,
            STATUS,
            TRAN_CODE,
            TASK_TYPE,
            TRAN_DESC,
            TASK_GRP_DESC,
            EMP_CODE_FOR,
            REMARKS,
            COMP_ID,
            DIVSN_ID,
            DEPT_ID,
            EXPIRE_ON,
            CREATED_ON,
            CREATED_BY,
            IP_ADDR
        )
        VALUES
        (
            :task_id,
            'O',
            :tran_code,
            'A',
            :tran_desc,
            :task_grp_desc,
            :emp_code_for,
            'SENT FOR CONFIRMATION',
            1,
            :divsn_id,
            :dept_id,
            TO_DATE(:expire_on, 'YYYY-MM-DD HH24:MI:SS'),
            SYSDATE,
            :created_by,
            :ip_addr
        )
        RETURNING ID INTO :user_task_id
    ";

    $stmtTask = oci_parse($sql___func___con, $insertTaskSql);
    if (!$stmtTask) {
        $err = oci_error($sql___func___con);
        logOracleError($err, $insertTaskSql);
        oci_rollback($sql___func___con);
        apiResponse(false, 'Failed to prepare authorization task statement.', null, 500);
    }

    oci_bind_by_name($stmtTask, ':task_id', $task['ID']);
    oci_bind_by_name($stmtTask, ':tran_code', $reqId);
    oci_bind_by_name($stmtTask, ':tran_desc', $tranDesc);
    oci_bind_by_name($stmtTask, ':task_grp_desc', $task['TASK_LABEL']);
    oci_bind_by_name($stmtTask, ':emp_code_for', $empCode);
    oci_bind_by_name($stmtTask, ':divsn_id', $divsnId);
    oci_bind_by_name($stmtTask, ':dept_id', $deptId);
    oci_bind_by_name($stmtTask, ':expire_on', $task['EXPDT']);
    oci_bind_by_name($stmtTask, ':created_by', $empCode);
    oci_bind_by_name($stmtTask, ':ip_addr', $clientIp);
    oci_bind_by_name($stmtTask, ':user_task_id', $userTaskId, 32);

    if (!oci_execute($stmtTask, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtTask);
        logOracleError($err, $insertTaskSql);
        oci_rollback($sql___func___con);
        oci_free_statement($stmtTask);
        apiResponse(false, 'Unable to create workflow authorization task.', null, 500);
    }
    oci_free_statement($stmtTask);

    if (empty($userTaskId)) {
        oci_rollback($sql___func___con);
        apiResponse(false, 'Failed to generate workflow task ID.', null, 500);
    }

    /* ===========================================
       STEP 3: LINK TASK_ID BACK TO REQUEST
    =========================================== */

    $updateLinkSql = "
        UPDATE EPT_HR_EMP_BANK_REQ
        SET TASK_ID = :user_task_id
        WHERE ID = :req_id
    ";

    $stmtLink = oci_parse($sql___func___con, $updateLinkSql);
    if (!$stmtLink) {
        $err = oci_error($sql___func___con);
        logOracleError($err, $updateLinkSql);
        oci_rollback($sql___func___con);
        apiResponse(false, 'Failed to prepare task linkage statement.', null, 500);
    }

    oci_bind_by_name($stmtLink, ':user_task_id', $userTaskId);
    oci_bind_by_name($stmtLink, ':req_id', $reqId);

    if (!oci_execute($stmtLink, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtLink);
        logOracleError($err, $updateLinkSql);
        oci_rollback($sql___func___con);
        oci_free_statement($stmtLink);
        apiResponse(false, 'Unable to link task ID with bank request.', null, 500);
    }
    oci_free_statement($stmtLink);

    /* ===========================================
       STEP 4: COMMIT TRANSACTION
    =========================================== */

    if (!oci_commit($sql___func___con)) {
        $err = oci_error($sql___func___con);
        logOracleError($err, 'Commit failed for EPT_HR_EMP_BANK_REQ');
        oci_rollback($sql___func___con);
        apiResponse(false, 'Unable to finalize bank request submission.', null, 500);
    }

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        'Bank details update request submitted successfully for authorization.',
        [
            'request_id'   => $reqId,
            'user_task_id' => $userTaskId,
            'doc_name'     => $docName1
        ],
        200
    );
} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        oci_rollback($sql___func___con);
    }

    logOracleError(['message' => $e->getMessage()], 'saveBankDetails.php');
    apiResponse(false, 'An internal error occurred while processing your request.', null, 500);
}