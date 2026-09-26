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
    $newBranch   = trim((string) ($data['bank_branch'] ?? ''));
    $newIfsc     = strtoupper(trim((string) ($data['bank_ifsc'] ?? '')));
    $newAcno     = trim((string) ($data['bank_acno'] ?? ''));
    $newNominee  = trim((string) ($data['bank_nominee'] ?? ''));

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
       RESOLVE CLIENT IP ADDRESS
       ========================================================== */

    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['HTTP_CLIENT_IP'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? 'UNKNOWN';

    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    $clientIpEsc = str_replace("'", "''", substr(trim($clientIp), 0, 100));

    /* ==========================================================
       ESCAPE EMPLOYEE CODE
       ========================================================== */

    $empCodeEsc = str_replace("'", "''", $empCode);

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
        forceRollback('Failed to fetch current bank details.');
    }

    if (empty($employeeRows)) {
        forceRollback('Employee bank details not found.');
    }

    $oldData = $employeeRows[0];

    $oldBankName = strtoupper(trim((string) ($oldData['BANK_NAME'] ?? '')));
    $oldBranch   = strtoupper(trim((string) ($oldData['AC_BRANCH_NAME'] ?? '')));
    $oldIfsc     = strtoupper(trim((string) ($oldData['AC_IFSC_NO'] ?? '')));
    $oldAcno     = trim((string) ($oldData['BANK_ACCT'] ?? ''));
    $oldNominee  = '';

    /* ==========================================================
       NORMALIZE NEW VALUES
       ========================================================== */

    $newBankName = strtoupper($newBankName);
    $newBranch   = strtoupper($newBranch);
    $newIfsc     = strtoupper($newIfsc);
    $newAcno     = trim($newAcno);
    $newNominee  = trim($newNominee);

    $bankNameChanged = $oldBankName !== $newBankName;
    $branchChanged   = $oldBranch !== $newBranch;
    $ifscChanged     = $oldIfsc !== $newIfsc;
    $accountChanged  = $oldAcno !== $newAcno;
    $nomineeChanged  = $oldNominee !== $newNominee;

    $hasChange =
        $bankNameChanged ||
        $branchChanged ||
        $ifscChanged ||
        $accountChanged ||
        $nomineeChanged;

    if (!$hasChange) {
        endQry();
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

    /* ==========================================================
       ESCAPE STRINGS
       ========================================================== */

    $oldBankNameEsc = str_replace("'", "''", $oldBankName);
    $oldBranchEsc   = str_replace("'", "''", $oldBranch);
    $oldIfscEsc     = str_replace("'", "''", $oldIfsc);
    $oldAcnoEsc     = str_replace("'", "''", $oldAcno);
    $oldNomineeEsc  = str_replace("'", "''", $oldNominee);

    $newBankNameEsc = str_replace("'", "''", $newBankName);
    $newBranchEsc   = str_replace("'", "''", $newBranch);
    $newIfscEsc     = str_replace("'", "''", $newIfsc);
    $newAcnoEsc     = str_replace("'", "''", $newAcno);
    $newNomineeEsc  = str_replace("'", "''", $newNominee);

    /* ==========================================================
       CHECK EXISTING PENDING REQUEST
       ========================================================== */

    $pendingRows = executeSelectQry("
        SELECT
            ID,
            NEW_BANK_NAME,
            NEW_BANK_BRANCH,
            NEW_BANK_IFSC,
            NEW_BANK_ACNO,
            NEW_BANK_NOMINEE,
            TO_CHAR(CHG_ON, 'DD-Mon-YYYY HH24:MI') AS REQ_DATE
        FROM EPT_HR_EMP_BANK_REQ
        WHERE EMP_CODE = '{$empCodeEsc}'
          AND STATUS IN ('N', 'T')
        ORDER BY ID DESC
    ");

    if ($pendingRows === false) {
        forceRollback('Failed to check existing pending bank request.');
    }

    if (!empty($pendingRows)) {
        endQry();
        $pendingRec = $pendingRows[0];
        apiResponse(
            false,
            'A bank details update request is already pending for authorization.',
            [
                'pending_req_id' => $pendingRec['ID'] ?? null,
                'req_date'       => $pendingRec['REQ_DATE'] ?? null,
                'pending_data'   => [
                    'bank_name'    => $pendingRec['NEW_BANK_NAME'] ?? '',
                    'bank_branch'  => $pendingRec['NEW_BANK_BRANCH'] ?? '',
                    'bank_ifsc'    => $pendingRec['NEW_BANK_IFSC'] ?? '',
                    'bank_acno'    => $pendingRec['NEW_BANK_ACNO'] ?? '',
                    'bank_nominee' => $pendingRec['NEW_BANK_NOMINEE'] ?? '',
                ]
            ],
            409
        );
    }

    /* ==========================================================
       INSERT BANK UPDATE REQUEST
       ========================================================== */

    $requestId = execQry([
        'type'  => 'insert',
        'table' => 'EPT_HR_EMP_BANK_REQ',
        'data'  => [
            'EMP_CODE'         => $empCodeEsc,
            'BANK_NAME'        => $oldBankNameEsc,
            'BANK_BRANCH'      => $oldBranchEsc,
            'BANK_IFSC'        => $oldIfscEsc,
            'BANK_ACNO'        => $oldAcnoEsc,
            'BANK_NOMINEE'     => $oldNomineeEsc,

            'NEW_BANK_NAME'    => $newBankNameEsc,
            'NEW_BANK_BRANCH'  => $newBranchEsc,
            'NEW_BANK_IFSC'    => $newIfscEsc,
            'NEW_BANK_ACNO'    => $newAcnoEsc,
            'NEW_BANK_NOMINEE' => $newNomineeEsc,

            'STATUS'           => 'N',
            'CHG_ON'           => 'SYSDATE',
            'CHG_BY'           => $empCodeEsc
        ],
        'return' => 'ID',
        'print'  => 0
    ]);

    if (!$requestId) {
        forceRollback('Failed to submit bank details update request.');
        return;
    }

    /* ==========================================================
       GET TASK MASTER
       ========================================================== */

    $task = singRec("
        SELECT
            t.*,
            TRUNC(SYSDATE) + t.EXPIRY_DAYS AS EXPDT
        FROM EPT_HR_TASK_MASTER t
        WHERE TASK_LABEL = 'Change Bank Info'
    ");

    if (empty($task) || empty($task['ID'])) {
        forceRollback('Task configuration not found for bank details update.');
        return;
    }

    /* ==========================================================
       GET EMPLOYEE OFFICE INFO
       ========================================================== */

    $empOfficeInfo = singRec("
        SELECT o.DIVSN_ID, o.DEPT_ID 
        FROM EPT_HR_EMP_OFFICE_DET o 
        WHERE o.EMP_CODE = '{$empCodeEsc}'        
    ");

    $DIVSN_ID = !empty($empOfficeInfo['DIVSN_ID']) ? $empOfficeInfo['DIVSN_ID'] : 0;
    $DEPT_ID  = !empty($empOfficeInfo['DEPT_ID'])  ? $empOfficeInfo['DEPT_ID']  : 0;

    $tran_desc = "Bank details update request submitted by employee {$empCodeEsc} for authorization.";

    /* ==========================================================
       INSERT USER TASK
       ========================================================== */

    $userTaskId = execQry([
        'type'  => 'insert',
        'table' => 'EPT_HR_USER_TASKS',
        'data'  => [
            'TASK_ID'       => $task['ID'],
            'STATUS'        => 'O',
            'TRAN_CODE'     => (string) $requestId,
            'TASK_TYPE'     => 'A',
            'TRAN_DESC'     => trim($tran_desc),
            'TASK_GRP_DESC' => $task['TASK_LABEL'],
            'EMP_CODE_FOR'  => $empCodeEsc,
            'REMARKS'       => 'SENT FOR CONFIRMATION',
            'COMP_ID'       => 1,
            'DIVSN_ID'      => $DIVSN_ID,
            'DEPT_ID'       => $DEPT_ID,
            'EXPIRE_ON'     => $task['EXPDT'],
            'CREATED_ON'    => 'SYSDATE',
            'CREATED_BY'    => $empCodeEsc,
            'IP_ADDR'       => $clientIpEsc
        ],
        'return' => 'ID',
        'print'  => 0
    ]);

    if (!$userTaskId) {
        forceRollback('Failed to create authorization task for bank details update.');
        return;
    }

    /* ==========================================================
       UPDATE TASK ID INTO BANK REQUEST TABLE
       ========================================================== */

    executeQry("
        UPDATE EPT_HR_EMP_BANK_REQ
        SET TASK_ID = {$userTaskId}
        WHERE ID = {$requestId}
    ");

    if ($qry_____result != 0) {
        forceRollback('Failed to link task ID with bank details update request.');
        return;
    }

    /* ==========================================================
       COMMIT
       ========================================================== */

    endQry();

    /* ==========================================================
       SUCCESS RESPONSE
       ========================================================== */

    apiResponse(
        true,
        'Bank details update request submitted successfully for authorization.',
        [
            'request_id'   => $requestId,
            'user_task_id' => $userTaskId,
            'master_task'  => $task['ID']
        ],
        200
    );
} catch (Throwable $e) {
    forceRollback('Save bank details request failed.');

    logOracleError(
        [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ],
        'saveBankDetails.php'
    );

    apiResponse(
        false,
        'Unable to submit bank details update request.',
        [
            'error_detail' => $e->getMessage()
        ],
        500
    );
}