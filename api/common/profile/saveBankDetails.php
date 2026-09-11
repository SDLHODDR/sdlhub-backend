<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_eportal();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json');

try {
    /* ==========================================================
       SESSION VALIDATION
       ========================================================== */

    $empCode = trim($_SESSION['emp_code'] ?? '');

    if ($empCode === '') {
        apiResponse(
            false,
            'Unauthorized access',
            null,
            401
        );
    }

    /* ==========================================================
       READ REQUEST
       ========================================================== */

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        apiResponse(
            false,
            'Invalid request data.',
            null,
            400
        );
    }

    /* ==========================================================
       READ NEW BANK DETAILS
       ========================================================== */

    $newBankName = trim((string) ($data['bank_name'] ?? ''));
    $newBranch = trim((string) ($data['bank_branch'] ?? ''));
    $newIfsc = strtoupper(trim((string) ($data['bank_ifsc'] ?? '')));
    $newAcno = trim((string) ($data['bank_acno'] ?? ''));
    $newNominee = trim((string) ($data['bank_nominee'] ?? ''));

    /* ==========================================================
       VALIDATION
       ========================================================== */

    if (
        $newBankName === '' ||
        $newBranch === '' ||
        $newIfsc === '' ||
        $newAcno === ''
    ) {
        apiResponse(
            false,
            'Please enter all required bank details.',
            null,
            400
        );
    }

    /* ==========================================================
       IFSC VALIDATION
       ========================================================== */

    if (!preg_match('/^[A-Z0-9]+$/', $newIfsc)) {
        apiResponse(
            false,
            'Invalid IFSC.',
            null,
            400
        );
    }

    if (strlen($newIfsc) !== 11) {
        apiResponse(
            false,
            'IFSC must be 11 characters.',
            null,
            400
        );
    }

    /* ==========================================================
       ACCOUNT NUMBER VALIDATION
       ========================================================== */

    if (!preg_match('/^[0-9]+$/', $newAcno)) {
        apiResponse(
            false,
            'Invalid account number.',
            null,
            400
        );
    }

    /* ==========================================================
       ESCAPE EMPLOYEE CODE
       ========================================================== */

    $empCodeEsc = str_replace(
        "'",
        "''",
        $empCode
    );

    /* ==========================================================
       START TRANSACTION
       ========================================================== */

    startQry();

    /* ==========================================================
       GET CURRENT BANK DETAILS
       ========================================================== */

    $employeeRows = executeSelectQry("
        SELECT
            BANK_NAME,
            AC_BRANCH_NAME,
            AC_IFSC_NO,
            BANK_ACCT
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '{$empCodeEsc}'
    ");

    if ($employeeRows === false) {
        forceRollback(
            'Failed to fetch current bank details.'
        );
    }

    if (empty($employeeRows)) {
        forceRollback(
            'Employee bank details not found.'
        );
    }

    $oldData = $employeeRows[0];

    /* ==========================================================
       CURRENT / OLD BANK VALUES
       ========================================================== */

    $oldBankName = strtoupper(
        trim((string) ($oldData['BANK_NAME'] ?? ''))
    );

    $oldBranch = strtoupper(
        trim((string) ($oldData['AC_BRANCH_NAME'] ?? ''))
    );

    $oldIfsc = strtoupper(
        trim((string) ($oldData['AC_IFSC_NO'] ?? ''))
    );

    $oldAcno = trim(
        (string) ($oldData['BANK_ACCT'] ?? '')
    );

    /*
     * BANK_NOMINEE is not being read from EPT_BCS_EMPLOYEE
     * in the current implementation.
     */
    $oldNominee = '';

    /* ==========================================================
       NORMALIZE NEW VALUES
       ========================================================== */

    $newBankName = strtoupper($newBankName);
    $newBranch = strtoupper($newBranch);
    $newIfsc = strtoupper($newIfsc);
    $newAcno = trim($newAcno);
    $newNominee = trim($newNominee);

    /* ==========================================================
       CHECK WHETHER ANY VALUE HAS CHANGED
       ========================================================== */

    $bankNameChanged = $oldBankName !== $newBankName;
    $branchChanged = $oldBranch !== $newBranch;
    $ifscChanged = $oldIfsc !== $newIfsc;
    $accountChanged = $oldAcno !== $newAcno;
    $nomineeChanged = $oldNominee !== $newNominee;

    $hasChange =
        $bankNameChanged ||
        $branchChanged ||
        $ifscChanged ||
        $accountChanged ||
        $nomineeChanged;

    /* ==========================================================
       NO CHANGE
       ========================================================== */

    if (!$hasChange) {
        endQry();

        apiResponse(
            false,
            'No changes found in bank details.',
            [
                'bank_name_changed' => $bankNameChanged,
                'branch_changed' => $branchChanged,
                'ifsc_changed' => $ifscChanged,
                'account_changed' => $accountChanged,
                'nominee_changed' => $nomineeChanged
            ],
            400
        );
    }

    /* ==========================================================
       ESCAPE OLD VALUES
       ========================================================== */

    $oldBankNameEsc = str_replace(
        "'",
        "''",
        $oldBankName
    );

    $oldBranchEsc = str_replace(
        "'",
        "''",
        $oldBranch
    );

    $oldIfscEsc = str_replace(
        "'",
        "''",
        $oldIfsc
    );

    $oldAcnoEsc = str_replace(
        "'",
        "''",
        $oldAcno
    );

    $oldNomineeEsc = str_replace(
        "'",
        "''",
        $oldNominee
    );

    /* ==========================================================
       ESCAPE NEW VALUES
       ========================================================== */

    $newBankNameEsc = str_replace(
        "'",
        "''",
        $newBankName
    );

    $newBranchEsc = str_replace(
        "'",
        "''",
        $newBranch
    );

    $newIfscEsc = str_replace(
        "'",
        "''",
        $newIfsc
    );

    $newAcnoEsc = str_replace(
        "'",
        "''",
        $newAcno
    );

    $newNomineeEsc = str_replace(
        "'",
        "''",
        $newNominee
    );

    /* ==========================================================
       CHECK EXISTING PENDING REQUEST

       We check both T and 1 because existing data/workflow
       may contain either value.
       ========================================================== */

    $pendingRows = executeSelectQry("
        SELECT
            COUNT(*) AS CNT
        FROM EPT_HR_EMP_BANK_REQ
        WHERE EMP_CODE = '{$empCodeEsc}'
        AND STATUS IN ('N', 'T')
    ");

    if ($pendingRows === false) {
        forceRollback(
            'Failed to check existing pending bank request.'
        );
    }

    $pendingCount = 0;

    if (!empty($pendingRows)) {
        $pendingCount = (int) ($pendingRows[0]['CNT'] ?? 0);
    }

    /* ==========================================================
       PENDING REQUEST EXISTS
       ========================================================== */

    if ($pendingCount > 0) {
        endQry();

        apiResponse(
            false,
            'A bank details update request is already pending for authorization.
             Please wait until the existing request is authorized before submitting a new request.',
            null,
            400
        );
    }

    /* ==========================================================
       INSERT BANK UPDATE REQUEST
       ========================================================== */

    $requestId = executeQry("
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

            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '{$empCodeEsc}',
            '{$oldBankNameEsc}',
            '{$oldBranchEsc}',
            '{$oldIfscEsc}',
            '{$oldAcnoEsc}',
            '{$oldNomineeEsc}',

            '{$newBankNameEsc}',
            '{$newBranchEsc}',
            '{$newIfscEsc}',
            '{$newAcnoEsc}',
            '{$newNomineeEsc}',

            'N',
            SYSDATE,
            '{$empCodeEsc}'
        )
    ");

    /* ==========================================================
       CHECK BANK REQUEST INSERT
       ========================================================== */

    if ($qry_____result != 0) {
        forceRollback(
            'Failed to submit bank details update request.'
        );
    }

    /* ==========================================================
       GET TASK MASTER

       IMPORTANT:
       Do NOT COMMIT before this query and task INSERT.
       ========================================================== */

    $task = singRec("
        SELECT
            t.*,
            TRUNC(SYSDATE) + t.EXPIRY_DAYS AS EXPDT
        FROM EPT_HR_TASK_MASTER t
        WHERE TASK_LABEL = 'Change Bank Info'
    ");

    /* ==========================================================
       CHECK TASK MASTER
       ========================================================== */

    if ($task === false) {
        forceRollback(
            'Failed to fetch task configuration for bank details update.'
        );
    }

    if (empty($task)) {
        forceRollback(
            'Task configuration not found for bank details update.'
        );
    }

    /* ==========================================================
       VALIDATE TASK MASTER DATA
       ========================================================== */

    if (!isset($task['ID']) || $task['ID'] === '') {
        forceRollback(
            'Task configuration found, but task ID is missing.'
        );
    }

    if (
        !isset($task['EXPDT']) ||
        $task['EXPDT'] === ''
    ) {
        forceRollback(
            'Task configuration found, but task expiry date is missing.'
        );
    }

    /* ==========================================================
       TRANSACTION DESCRIPTION
       ========================================================== */

    $tran_desc = "Bank details update request submitted by employee {$empCodeEsc} for authorization.";

    /* ==========================================================
       INSERT USER TASK
       ========================================================== */

    $empOfficeInfo = singRec("
              SELECT  o.DIVSN_ID, o.DEPT_ID 
        FROM EPT_HR_EMP_OFFICE_DET o 
        WHERE o.EMP_CODE = '{$empCodeEsc}'        
        ");

    $DIVSN_ID = 0;
    $DEPT_ID = 0;

    if (!empty($empOfficeInfo)) {
        $DIVSN_ID = $empOfficeInfo['DIVSN_ID'] ?? 0;
        $DEPT_ID = $empOfficeInfo['DEPT_ID'] ?? 0;
    }

    $taskInsertResult = execQry(array(
        'type' => 'insert',
        'table' => 'EPT_HR_USER_TASKS',
        'data' => array(
            'ID' => '',
            'TASK_ID' => $task['ID'],
            'STATUS' => 'O',
            'TRAN_CODE' => $requestId,
            'TASK_TYPE' => 'A',
            'TRAN_DESC' => trim($tran_desc),
            'TASK_GRP_DESC' => $task['TASK_LABEL'],
            'EMP_CODE_FOR' => $empCodeEsc,
            'REMARKS' => 'SENT FOR CONFIRMATION',
            'COMP_ID' => 1,
            'DIVSN_ID' => $DIVSN_ID,
            'DEPT_ID' => $DEPT_ID,
            'EXPIRE_ON' => $task['EXPDT'],
            'CREATED_ON' => 'SYSDATE',
            'CREATED_BY' => $created_by
        ),
        'return' => 'ID',
        'print' => 0
    ));

    /* ==========================================================
    CHECK USER TASK INSERT
    ========================================================== */

    if ($taskInsertResult === false) {
        forceRollback(
            'Failed to create authorization task for bank details update.'
        );

        // In case forceRollback() does not terminate execution
        return;
    }

    /* ==========================================================
       COMMIT

       COMMIT ONLY AFTER:
       1. Bank request inserted successfully
       2. Task master found successfully
       3. User task inserted successfully
       ========================================================== */

    endQry();

    /* ==========================================================
       SUCCESS RESPONSE
       ========================================================== */

    apiResponse(
        true,
        'Bank details update request submitted successfully for authorization.',
        [
            'request_id' => $requestId,
            'task_id' => $task['ID']
        ],
        200
    );
} catch (Throwable $e) {
    /* ==========================================================
       ROLLBACK
       ========================================================== */

    forceRollback(
        'Save bank details request failed.'
    );

    /* ==========================================================
       ERROR LOG
       ========================================================== */

    logOracleError(
        [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ],
        'saveBankDetails.php'
    );

    /* ==========================================================
       API RESPONSE
       ========================================================== */

    apiResponse(
        false,
        'Unable to submit bank details update request.',
        null,
        500
    );
}
