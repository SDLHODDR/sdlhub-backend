<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? $_SESSION['EMP_CODE'] ?? $_SESSION['employee_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       READ & PARSE INPUT
    =========================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request data.", null, 400);
    }

    $action = strtoupper(trim($data['action'] ?? ''));
    if (!in_array($action, ['A', 'E', 'D'], true)) {
        apiResponse(false, "Invalid request action. Must be 'A' (Add), 'E' (Edit), or 'D' (Delete).", null, 400);
    }

    $id = (int)($data['id'] ?? 0);

    /* ===========================================
       PRE-FETCH TASK MASTER & OFFICE DETAILS
    =========================================== */

    $task = singRec("
        SELECT
            t.*,
            TO_CHAR(TRUNC(SYSDATE) + t.EXPIRY_DAYS, 'YYYY-MM-DD HH24:MI:SS') AS EXPDT
        FROM EPT_HR_TASK_MASTER t
        WHERE TASK_LABEL = 'Change Personal Info'
    ");

    if (empty($task) || empty($task['ID']) || empty($task['EXPDT'])) {
        apiResponse(false, "Task configuration not found or incomplete for family details authorization.", null, 500);
    }

    $empOfficeInfo = singRec("
        SELECT o.DIVSN_ID, o.DEPT_ID
        FROM EPT_HR_EMP_OFFICE_DET o
        WHERE o.EMP_CODE = '{$empCode}'
    ");

    $divsnId = !empty($empOfficeInfo['DIVSN_ID']) ? (int)$empOfficeInfo['DIVSN_ID'] : 0;
    $deptId  = !empty($empOfficeInfo['DEPT_ID'])  ? (int)$empOfficeInfo['DEPT_ID']  : 0;

    /* ===========================================
       HANDLE DELETE ACTION ('D')
    =========================================== */

    if ($action === 'D') {
        if ($id <= 0) {
            apiResponse(false, "Invalid family record identifier.", null, 400);
        }

        // Fetch existing family record
        $oldSql = "
            SELECT ID, FM_NAME, FM_RELATION, FM_CONTACT, FM_DEP, FM_OCCUPATION, 
                   TO_CHAR(DOB, 'YYYY-MM-DD') AS DOB, AADHAAR
            FROM EPT_HR_EMP_FAMILY_INFO
            WHERE ID = :id AND EMP_CODE = :emp_code
        ";
        $stmt = oci_parse($sql___func___con, $oldSql);
        oci_bind_by_name($stmt, ':id', $id);
        oci_bind_by_name($stmt, ':emp_code', $empCode);
        oci_execute($stmt);
        $oldRec = oci_fetch_assoc($stmt);
        oci_free_statement($stmt);

        if (!$oldRec) {
            apiResponse(false, "Family member record not found.", null, 404);
        }

        $oldId          = $oldRec['ID'];
        $oldName        = $oldRec['FM_NAME'];
        $oldRelation    = $oldRec['FM_RELATION'];
        $oldContact     = $oldRec['FM_CONTACT'] ?? null;
        $oldDep         = $oldRec['FM_DEP'] ?? null;
        $oldOccupation  = $oldRec['FM_OCCUPATION'] ?? null;
        $oldDob         = $oldRec['DOB'] ?? null;
        $oldAadhaar     = $oldRec['AADHAAR'] ?? null;

        // New values are null on deletion
        $newName = null; $newRelation = null; $newContact = null;
        $newDep = null; $newOccupation = null; $newDob = null; $newAadhaar = null;

        $tranDesc = "Family member removal request ({$oldName} - {$oldRelation}) submitted by employee {$empCode}.";
    }

    /* ===========================================
       HANDLE ADD ('A') & EDIT ('E') ACTIONS
    =========================================== */

    if ($action === 'A' || $action === 'E') {
        $newName       = trim($data['name'] ?? '');
        $newRelation   = trim($data['relation'] ?? '');
        $newContact    = trim($data['contact'] ?? '');
        $newDep        = trim($data['dependent'] ?? '');
        $newOccupation = trim($data['occupation'] ?? '');
        $newDob        = trim($data['dob'] ?? '');
        $newAadhaar    = trim($data['aadhaar'] ?? '');

        if ($newName === '' || $newRelation === '') {
            apiResponse(false, "Name and Relation are required.", null, 400);
        }

        // DOB Validation
        if ($newDob !== '') {
            $dobDate = DateTime::createFromFormat('Y-m-d', $newDob);
            $dateErrors = DateTime::getLastErrors();

            if (
                !$dobDate ||
                ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
                $dobDate->format('Y-m-d') !== $newDob
            ) {
                apiResponse(false, "Invalid date of birth. Format must be YYYY-MM-DD.", null, 400);
            }

            $today = new DateTime('today');
            if ($dobDate > $today) {
                apiResponse(false, "Date of birth cannot be in the future.", null, 400);
            }
        }

        // Aadhaar validation
        if ($newAadhaar !== '') {
            if (!preg_match('/^\d{12}$/', $newAadhaar)) {
                apiResponse(false, "Aadhaar must contain exactly 12 digits.", null, 400);
            }
            if (preg_match('/^[01]/', $newAadhaar)) {
                apiResponse(false, "Invalid Aadhaar number.", null, 400);
            }
        }

        if ($action === 'E') {
            if ($id <= 0) {
                apiResponse(false, "Invalid record identifier for update.", null, 400);
            }

            $oldSql = "
                SELECT ID, FM_NAME, FM_RELATION, FM_CONTACT, FM_DEP, FM_OCCUPATION, 
                       TO_CHAR(DOB, 'YYYY-MM-DD') AS DOB, AADHAAR
                FROM EPT_HR_EMP_FAMILY_INFO
                WHERE ID = :id AND EMP_CODE = :emp_code
            ";
            $stmt = oci_parse($sql___func___con, $oldSql);
            oci_bind_by_name($stmt, ':id', $id);
            oci_bind_by_name($stmt, ':emp_code', $empCode);
            oci_execute($stmt);
            $oldRec = oci_fetch_assoc($stmt);
            oci_free_statement($stmt);

            if (!$oldRec) {
                apiResponse(false, "Original family member record not found.", null, 404);
            }

            $oldId         = $oldRec['ID'];
            $oldName       = $oldRec['FM_NAME'];
            $oldRelation   = $oldRec['FM_RELATION'];
            $oldContact    = $oldRec['FM_CONTACT'] ?? null;
            $oldDep        = $oldRec['FM_DEP'] ?? null;
            $oldOccupation = $oldRec['FM_OCCUPATION'] ?? null;
            $oldDob        = $oldRec['DOB'] ?? null;
            $oldAadhaar    = $oldRec['AADHAAR'] ?? null;

            $tranDesc = "Family member update request ({$newName} - {$newRelation}) submitted by employee {$empCode}.";
        } else {
            // Action 'A' (New Member)
            $oldId = null; $oldName = null; $oldRelation = null; $oldContact = null;
            $oldDep = null; $oldOccupation = null; $oldDob = null; $oldAadhaar = null;

            $tranDesc = "New family member request ({$newName} - {$newRelation}) submitted by employee {$empCode}.";
        }
    }

    /* ===========================================
       SANITIZE INPUTS (CONVERT EMPTY STRINGS TO NULL)
    =========================================== */

    $oldId         = !empty($oldId) ? (int)$oldId : null;
    $oldContact    = !empty($oldContact) ? $oldContact : null;
    $oldDep        = !empty($oldDep) ? $oldDep : null;
    $oldOccupation = !empty($oldOccupation) ? $oldOccupation : null;
    $oldDob        = !empty($oldDob) ? $oldDob : null;
    $oldAadhaar    = !empty($oldAadhaar) ? $oldAadhaar : null;

    $newContact    = !empty($newContact) ? $newContact : null;
    $newDep        = !empty($newDep) ? $newDep : null;
    $newOccupation = !empty($newOccupation) ? $newOccupation : null;
    $newDob        = !empty($newDob) ? $newDob : null;
    $newAadhaar    = !empty($newAadhaar) ? $newAadhaar : null;

    /* ===========================================
       STEP 1: INSERT INTO EPT_HR_EMP_FAMILY_REQ
    =========================================== */

    $reqId = null;
    $status = 'N';

    $insertReqSql = "
        INSERT INTO EPT_HR_EMP_FAMILY_REQ
        (
            ASON_DATE,
            EMP_CODE,
            OLD_ID,
            FM_NAME,
            FM_RELATION,
            FM_CONTACT,
            FM_DEP,
            FM_OCCUPATION,
            DOB,
            AADHAAR,
            NEW_FM_NAME,
            NEW_FM_RELATION,
            NEW_FM_CONTACT,
            NEW_FM_DEP,
            NEW_FM_OCCUPATION,
            NEW_DOB,
            NEW_AADHAAR,
            REQ_ACTION,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            SYSDATE,
            :emp_code,
            :old_id,
            :old_name,
            :old_relation,
            :old_contact,
            :old_dep,
            :old_occupation,
            TO_DATE(:old_dob, 'YYYY-MM-DD'),
            :old_aadhaar,
            :new_name,
            :new_relation,
            :new_contact,
            :new_dep,
            :new_occupation,
            TO_DATE(:new_dob, 'YYYY-MM-DD'),
            :new_aadhaar,
            :req_action,
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
        apiResponse(false, "Failed to prepare request statement: " . ($err['message'] ?? ''), null, 500);
    }

    oci_bind_by_name($stmtReq, ':emp_code', $empCode);
    oci_bind_by_name($stmtReq, ':old_id', $oldId);
    oci_bind_by_name($stmtReq, ':old_name', $oldName);
    oci_bind_by_name($stmtReq, ':old_relation', $oldRelation);
    oci_bind_by_name($stmtReq, ':old_contact', $oldContact);
    oci_bind_by_name($stmtReq, ':old_dep', $oldDep);
    oci_bind_by_name($stmtReq, ':old_occupation', $oldOccupation);
    oci_bind_by_name($stmtReq, ':old_dob', $oldDob);
    oci_bind_by_name($stmtReq, ':old_aadhaar', $oldAadhaar);

    oci_bind_by_name($stmtReq, ':new_name', $newName);
    oci_bind_by_name($stmtReq, ':new_relation', $newRelation);
    oci_bind_by_name($stmtReq, ':new_contact', $newContact);
    oci_bind_by_name($stmtReq, ':new_dep', $newDep);
    oci_bind_by_name($stmtReq, ':new_occupation', $newOccupation);
    oci_bind_by_name($stmtReq, ':new_dob', $newDob);
    oci_bind_by_name($stmtReq, ':new_aadhaar', $newAadhaar);

    oci_bind_by_name($stmtReq, ':req_action', $action);
    oci_bind_by_name($stmtReq, ':status', $status);
    oci_bind_by_name($stmtReq, ':chg_by', $empCode);
    oci_bind_by_name($stmtReq, ':req_id', $reqId, 32);

    if (!oci_execute($stmtReq, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtReq);
        logOracleError($err, $insertReqSql);
        oci_rollback($sql___func___con);
        oci_free_statement($stmtReq);
        // Include actual Oracle message for direct debugging:
        apiResponse(false, "Unable to record authorization request: " . ($err['message'] ?? ''), null, 500);
    }
    oci_free_statement($stmtReq);

    if (empty($reqId)) {
        oci_rollback($sql___func___con);
        apiResponse(false, "Failed to generate authorization request ID.", null, 500);
    }

    /* ===========================================
       STEP 2: INSERT INTO EPT_HR_USER_TASKS
    =========================================== */

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
            CREATED_BY
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
            :created_by
        )
        RETURNING ID INTO :user_task_id
    ";

    $stmtTask = oci_parse($sql___func___con, $insertTaskSql);
    if (!$stmtTask) {
        $err = oci_error($sql___func___con);
        logOracleError($err, $insertTaskSql);
        oci_rollback($sql___func___con);
        apiResponse(false, "Failed to prepare workflow task.", null, 500);
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
    oci_bind_by_name($stmtTask, ':user_task_id', $userTaskId, 10);

    if (!oci_execute($stmtTask, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtTask);
        logOracleError($err, $insertTaskSql);
        oci_rollback($sql___func___con);
        oci_free_statement($stmtTask);
        apiResponse(false, "Unable to create authorization task.", null, 500);
    }
    oci_free_statement($stmtTask);

    if (empty($userTaskId)) {
        oci_rollback($sql___func___con);
        apiResponse(false, "Failed to generate workflow task ID.", null, 500);
    }

    /* ===========================================
       STEP 3: LINK TASK_ID BACK TO REQUEST
    =========================================== */

    $updateLinkSql = "
        UPDATE EPT_HR_EMP_FAMILY_REQ
        SET TASK_ID = :user_task_id
        WHERE ID = :req_id
    ";

    $stmtLink = oci_parse($sql___func___con, $updateLinkSql);
    if (!$stmtLink) {
        $err = oci_error($sql___func___con);
        logOracleError($err, $updateLinkSql);
        oci_rollback($sql___func___con);
        apiResponse(false, "Failed to prepare request-task linkage.", null, 500);
    }

    oci_bind_by_name($stmtLink, ':user_task_id', $userTaskId);
    oci_bind_by_name($stmtLink, ':req_id', $reqId);

    if (!oci_execute($stmtLink, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtLink);
        logOracleError($err, $updateLinkSql);
        oci_rollback($sql___func___con);
        oci_free_statement($stmtLink);
        apiResponse(false, "Unable to link task ID with authorization request.", null, 500);
    }
    oci_free_statement($stmtLink);

    /* ===========================================
       STEP 4: COMMIT TRANSACTION
    =========================================== */

    if (!oci_commit($sql___func___con)) {
        $err = oci_error($sql___func___con);
        logOracleError($err, "Commit failed for EPT_HR_EMP_FAMILY_REQ");
        oci_rollback($sql___func___con);
        apiResponse(false, "Unable to finalize request submission.", null, 500);
    }

    $successMessages = [
        'A' => "Family member addition request submitted for authorization.",
        'E' => "Family member update request submitted for authorization.",
        'D' => "Family member removal request submitted for authorization."
    ];

    apiResponse(true, $successMessages[$action], [
        "request_id"   => $reqId,
        "user_task_id" => $userTaskId,
        "req_action"   => $action
    ], 200);

} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        oci_rollback($sql___func___con);
    }

    logOracleError(["message" => $e->getMessage()], "saveFamilyMember.php");
    apiResponse(false, "An internal error occurred while processing your request.", null, 500);
}