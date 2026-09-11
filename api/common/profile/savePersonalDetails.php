<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_eportal();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=UTF-8');

/* ==========================================================
   EMPLOYEE CODE
========================================================== */

$emp_code =
    $_SESSION['emp_code']
        ?? $_SESSION['EMP_CODE']
        ?? $_SESSION['employee_code']
        ?? null;

if (!$emp_code) {
    apiResponse(
        false,
        'Employee session not found.',
        null,
        401
    );
}

/* ==========================================================
   ACTION
========================================================== */

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!$action) {
    apiResponse(
        false,
        'Invalid request action.',
        null,
        400
    );
}

/* ==========================================================
   COMMON INPUTS
========================================================== */

$cell = trim($_POST['cell'] ?? '');
$per_email = trim($_POST['per_email'] ?? '');
$m_status = trim($_POST['m_status'] ?? '');

/* ==========================================================
   SEND OTP
========================================================== */

if ($action === 'send_otp') {

    /* ======================================================
       VALIDATE MOBILE
    ====================================================== */

    if ($cell === '') {
        apiResponse(
            false,
            'Mobile number is required.',
            null,
            400
        );
    }

    if (!preg_match('/^[0-9]{10}$/', $cell)) {
        apiResponse(
            false,
            'Invalid mobile number.',
            null,
            400
        );
    }

    /* ======================================================
       VALIDATE EMAIL
    ====================================================== */

    if ($per_email === '') {
        apiResponse(
            false,
            'Personal email is required.',
            null,
            400
        );
    }

    if (!filter_var($per_email, FILTER_VALIDATE_EMAIL)) {
        apiResponse(
            false,
            'Invalid personal email.',
            null,
            400
        );
    }

    /* ======================================================
       GET CURRENT EMPLOYEE DATA
    ====================================================== */

    $masterSql = '
        SELECT
            EMP_CODE,

            MOBILE_NO AS CELL,
            EMAIL_ID_PER AS PER_EMAIL,

            CUR_ADD1,
            CUR_ADD2,
            CUR_ADD3,

            CUR_CITY AS CITY,
            CUR_STATE AS STATE,
            CUR_PIN AS PINCODE,

            PER_ADD1,
            PER_ADD2,
            PER_ADD3,

            PER_CITY,
            PER_STATE,
            PER_PIN,

            M_STATUS

        FROM EPT_BCS_EMPLOYEE

        WHERE EMP_CODE = :emp_code
    ';

    $stmt = oci_parse(
        $sql___func___con,
        $masterSql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $masterSql
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare employee query.',
            null,
            500,
            $error ?: []
        );
    }

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    if (!oci_execute($stmt)) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $masterSql
        );

        oci_free_statement($stmt);

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to fetch employee details.',
            null,
            500,
            $error ?: []
        );
    }

    $employee = oci_fetch_assoc($stmt);

    oci_free_statement($stmt);

    if (!$employee) {
        apiResponse(
            false,
            'Employee details not found.',
            null,
            404
        );
    }

    /* ======================================================
       GENERATE 5 DIGIT OTP
    ====================================================== */

    $otp = random_int(
        10000,
        99999
    );

    /* ======================================================
       OLD CURRENT ADDRESS
    ====================================================== */

    $address = trim(
        ($employee['CUR_ADD1'] ?? '')
        . ' '
        . ($employee['CUR_ADD2'] ?? '')
        . ' '
        . ($employee['CUR_ADD3'] ?? '')
    );

    /* ======================================================
       OLD PERMANENT ADDRESS
    ====================================================== */

    $permnt_address = trim(
        ($employee['PER_ADD1'] ?? '')
        . ' '
        . ($employee['PER_ADD2'] ?? '')
        . ' '
        . ($employee['PER_ADD3'] ?? '')
    );

    /* ======================================================
       INSERT OTP REQUEST
    ====================================================== */

    $insertSql = "
        INSERT INTO EPT_HR_EMP_INFO_REQ
        (
            ASON_DATE,
            EMP_CODE,

            CELL,
            PER_EMAIL,

            ADDRESS,
            CITY,
            STATE,
            PINCODE,

            PERMNT_ADDRESS,
            PERMNT_CITY,
            PERMNT_STATE,
            PERMNT_PINCODE,

            M_STATUS,

            NEW_CELL,
            NEW_PER_EMAIL,
            NEW_M_STATUS,

            OTP_NO,
            OTP_AUTH,

            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            SYSDATE,
            :emp_code,

            :old_cell,
            :old_email,

            :address,
            :city,
            :state,
            :pincode,

            :permnt_address,
            :permnt_city,
            :permnt_state,
            :permnt_pincode,

            :old_m_status,

            :new_cell,
            :new_email,
            :new_m_status,

            :otp,
            'N',

            SYSDATE,
            :chg_by
        )
        RETURNING ID INTO :request_id
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $insertSql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $insertSql
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare OTP request.',
            null,
            500,
            $error ?: []
        );
    }

    $request_id = null;

    /* ======================================================
       BIND VALUES
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    oci_bind_by_name(
        $stmt,
        ':old_cell',
        $employee['CELL']
    );

    oci_bind_by_name(
        $stmt,
        ':old_email',
        $employee['PER_EMAIL']
    );

    oci_bind_by_name(
        $stmt,
        ':address',
        $address
    );

    oci_bind_by_name(
        $stmt,
        ':city',
        $employee['CITY']
    );

    oci_bind_by_name(
        $stmt,
        ':state',
        $employee['STATE']
    );

    oci_bind_by_name(
        $stmt,
        ':pincode',
        $employee['PINCODE']
    );

    oci_bind_by_name(
        $stmt,
        ':permnt_address',
        $permnt_address
    );

    oci_bind_by_name(
        $stmt,
        ':permnt_city',
        $employee['PER_CITY']
    );

    oci_bind_by_name(
        $stmt,
        ':permnt_state',
        $employee['PER_STATE']
    );

    oci_bind_by_name(
        $stmt,
        ':permnt_pincode',
        $employee['PER_PIN']
    );

    oci_bind_by_name(
        $stmt,
        ':old_m_status',
        $employee['M_STATUS']
    );

    oci_bind_by_name(
        $stmt,
        ':new_cell',
        $cell
    );

    oci_bind_by_name(
        $stmt,
        ':new_email',
        $per_email
    );

    oci_bind_by_name(
        $stmt,
        ':new_m_status',
        $m_status
    );

    oci_bind_by_name(
        $stmt,
        ':otp',
        $otp
    );

    oci_bind_by_name(
        $stmt,
        ':chg_by',
        $emp_code
    );

    oci_bind_by_name(
        $stmt,
        ':request_id',
        $request_id,
        10
    );

    /* ======================================================
       EXECUTE INSERT
    ====================================================== */

    if (!oci_execute(
        $stmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $insertSql
        );

        oci_rollback(
            $sql___func___con
        );

        oci_free_statement($stmt);

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to create OTP request.',
            null,
            500,
            $error ?: []
        );
    }

    oci_free_statement($stmt);

    /* ======================================================
       COMMIT
    ====================================================== */

    if (!oci_commit(
        $sql___func___con
    )) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $insertSql
        );

        oci_rollback(
            $sql___func___con
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to commit OTP request.',
            null,
            500,
            $error ?: []
        );
    }

    /* ======================================================
       TEST MODE OTP
    ====================================================== */

    apiResponse(
        true,
        'OTP sent successfully.',
        [
            'request_id' => $request_id,

            // TEST ONLY
            'test_otp' => $otp
        ],
        200
    );
}

/* ==========================================================
   VERIFY OTP
========================================================== */

if ($action === 'verify_otp') {

    $request_id = intval(
        $_POST['request_id'] ?? 0
    );

    $otp = trim(
        $_POST['otp'] ?? ''
    );

    if ($request_id <= 0) {
        apiResponse(
            false,
            'Invalid OTP request.',
            null,
            400
        );
    }

    if (!preg_match(
        '/^[0-9]{5}$/',
        $otp
    )) {
        apiResponse(
            false,
            'Invalid OTP.',
            null,
            400
        );
    }

    /* ======================================================
       FETCH OTP
    ====================================================== */

    $sql = '
        SELECT
            ID,
            OTP_NO,
            OTP_AUTH

        FROM EPT_HR_EMP_INFO_REQ

        WHERE ID = :request_id
          AND EMP_CODE = :emp_code
    ';

    $stmt = oci_parse(
        $sql___func___con,
        $sql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $sql
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare OTP verification.',
            null,
            500,
            $error ?: []
        );
    }

    oci_bind_by_name(
        $stmt,
        ':request_id',
        $request_id
    );

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    if (!oci_execute($stmt)) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $sql
        );

        oci_free_statement($stmt);

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to verify OTP.',
            null,
            500,
            $error ?: []
        );
    }

    $row = oci_fetch_assoc($stmt);

    oci_free_statement($stmt);

    if (!$row) {
        apiResponse(
            false,
            'OTP request not found.',
            null,
            404
        );
    }

    /* ======================================================
       ALREADY VERIFIED
    ====================================================== */

    if (
        strtoupper(
            trim(
                $row['OTP_AUTH'] ?? ''
            )
        ) === 'Y'
    ) {

        apiResponse(
            true,
            'OTP already verified.',
            [
                'request_id' => $request_id
            ],
            200
        );
    }

    /* ======================================================
       VERIFY OTP VALUE
    ====================================================== */

    if (
        intval($row['OTP_NO']) !==
        intval($otp)
    ) {

        apiResponse(
            false,
            'Invalid OTP.',
            null,
            400
        );
    }

    /* ======================================================
       MARK OTP VERIFIED
    ====================================================== */

    $updateSql = "
        UPDATE EPT_HR_EMP_INFO_REQ

        SET
            OTP_AUTH = 'Y',
            CHG_ON = SYSDATE,
            CHG_BY = :emp_code

        WHERE ID = :request_id
          AND EMP_CODE = :emp_code
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $updateSql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $updateSql
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare OTP update.',
            null,
            500,
            $error ?: []
        );
    }

    oci_bind_by_name(
        $stmt,
        ':request_id',
        $request_id
    );

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    if (!oci_execute(
        $stmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $updateSql
        );

        oci_rollback(
            $sql___func___con
        );

        oci_free_statement($stmt);

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to verify OTP.',
            null,
            500,
            $error ?: []
        );
    }

    oci_free_statement($stmt);

    /* ======================================================
       COMMIT OTP VERIFICATION
    ====================================================== */

    if (!oci_commit(
        $sql___func___con
    )) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $updateSql
        );

        oci_rollback(
            $sql___func___con
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to commit OTP verification.',
            null,
            500,
            $error ?: []
        );
    }

    apiResponse(
        true,
        'OTP verified successfully.',
        [
            'request_id' => $request_id
        ],
        200
    );
}

/* ==========================================================
   SAVE CONTACT DETAILS
========================================================== */

if ($action === 'save_contact') {

    $request_id = intval(
        $_POST['request_id'] ?? 0
    );

    if ($request_id <= 0) {
        apiResponse(
            false,
            'Invalid request.',
            null,
            400
        );
    }

    /* ======================================================
       VALIDATE MOBILE
    ====================================================== */

    if (!preg_match(
        '/^[0-9]{10}$/',
        $cell
    )) {

        apiResponse(
            false,
            'Invalid mobile number.',
            null,
            400
        );
    }

    /* ======================================================
       VALIDATE EMAIL
    ====================================================== */

    if (!filter_var(
        $per_email,
        FILTER_VALIDATE_EMAIL
    )) {

        apiResponse(
            false,
            'Invalid personal email.',
            null,
            400
        );
    }

    /* ======================================================
       CHECK VERIFIED OTP
    ====================================================== */

    $sql = '
        SELECT
            ID,
            OTP_AUTH

        FROM EPT_HR_EMP_INFO_REQ

        WHERE ID = :request_id
          AND EMP_CODE = :emp_code
    ';

    $stmt = oci_parse(
        $sql___func___con,
        $sql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $sql
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare request validation.',
            null,
            500,
            $error ?: []
        );
    }

    oci_bind_by_name(
        $stmt,
        ':request_id',
        $request_id
    );

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    if (!oci_execute($stmt)) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $sql
        );

        oci_free_statement($stmt);

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to validate request.',
            null,
            500,
            $error ?: []
        );
    }

    $row = oci_fetch_assoc($stmt);

    oci_free_statement($stmt);

    if (!$row) {
        apiResponse(
            false,
            'Request not found.',
            null,
            404
        );
    }

    /* ======================================================
       CHECK OTP
    ====================================================== */

    if (
        strtoupper(
            trim(
                $row['OTP_AUTH'] ?? ''
            )
        ) !== 'Y'
    ) {

        apiResponse(
            false,
            'OTP verification is required.',
            null,
            400
        );
    }

    /* ==========================================================
       1. UPDATE EPT_BCS_EMPLOYEE
    ========================================================== */

    $sql = "
        UPDATE EPT_BCS_EMPLOYEE
        SET
            CUR_TEL1     = :new_cell,
            EMAIL_ID_PER = :new_email,
            M_STATUS     = :new_m_status,
            CHG_ON       = SYSDATE,
            CHG_BY       = :emp_code

        WHERE EMP_CODE = :emp_code
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $sql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $sql
        );

        oci_rollback(
            $sql___func___con
        );

        apiResponse(
            false,
            'Failed to prepare employee details update.',
            null,
            500,
            $error ?: []
        );
    }

    /* ======================================================
       BIND FIRST UPDATE
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':new_cell',
        $cell
    );

    oci_bind_by_name(
        $stmt,
        ':new_email',
        $per_email
    );

    oci_bind_by_name(
        $stmt,
        ':new_m_status',
        $m_status
    );

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    /* ======================================================
       EXECUTE FIRST UPDATE
    ====================================================== */

    if (!oci_execute(
        $stmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $sql
        );

        oci_rollback(
            $sql___func___con
        );

        oci_free_statement($stmt);

        apiResponse(
            false,
            'Failed to update employee details.',
            null,
            500,
            $error ?: []
        );
    }

    oci_free_statement($stmt);

    /* ==========================================================
       2. UPDATE EPT_HR_EMPLOYEE_INFO
    ========================================================== */

    $hrSql = "
        UPDATE EPT_HR_EMPLOYEE_INFO
        SET
            CELL      = :new_cell,
            PER_EMAIL = :new_email,
            CHG_ON    = SYSDATE,
            CHG_BY    = :emp_code

        WHERE EMP_CODE = :emp_code
    ";

    $hrStmt = oci_parse(
        $sql___func___con,
        $hrSql
    );

    if (!$hrStmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $hrSql
        );

        oci_rollback(
            $sql___func___con
        );

        apiResponse(
            false,
            'Failed to prepare HR employee details update.',
            null,
            500,
            $error ?: []
        );
    }

    /* ======================================================
       BIND SECOND UPDATE
    ====================================================== */

    oci_bind_by_name(
        $hrStmt,
        ':new_cell',
        $cell
    );

    oci_bind_by_name(
        $hrStmt,
        ':new_email',
        $per_email
    );

    oci_bind_by_name(
        $hrStmt,
        ':emp_code',
        $emp_code
    );

    /* ======================================================
       EXECUTE SECOND UPDATE
    ====================================================== */

    if (!oci_execute(
        $hrStmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($hrStmt);

        logOracleError(
            $error,
            $hrSql
        );

        oci_rollback(
            $sql___func___con
        );

        oci_free_statement($hrStmt);

        apiResponse(
            false,
            'Failed to update HR employee details.',
            null,
            500,
            $error ?: []
        );
    }

    oci_free_statement($hrStmt);

    /* ==========================================================
       3. COMMIT BOTH UPDATES
    ========================================================== */

    if (!oci_commit(
        $sql___func___con
    )) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            'COMMIT EPT_BCS_EMPLOYEE + EPT_HR_EMPLOYEE_INFO'
        );

        oci_rollback(
            $sql___func___con
        );

        apiResponse(
            false,
            'Failed to commit personal details update.',
            null,
            500,
            $error ?: []
        );
    }

    /* ==========================================================
       SUCCESS
    ========================================================== */

    apiResponse(
        true,
        'Personal details updated successfully.',
        [
            'request_id' => $request_id
        ],
        200
    );
}

/* ==========================================================
   SAVE ADDRESS
========================================================== */

if ($action === 'save_address') {

    /* ======================================================
       ADDRESS INPUTS
    ====================================================== */

    $address = trim(
        $_POST['address'] ?? ''
    );

    $city = trim(
        $_POST['city'] ?? ''
    );

    $state = trim(
        $_POST['state'] ?? ''
    );

    $pincode = trim(
        $_POST['pincode'] ?? ''
    );

    $permnt_address = trim(
        $_POST['permnt_address'] ?? ''
    );

    $permnt_city = trim(
        $_POST['permnt_city'] ?? ''
    );

    $permnt_state = trim(
        $_POST['permnt_state'] ?? ''
    );

    $permnt_pincode = trim(
        $_POST['permnt_pincode'] ?? ''
    );

    /* ======================================================
       VALIDATION
    ====================================================== */

    if ($address === '') {
        apiResponse(
            false,
            'Current address is required.',
            null,
            400
        );
    }

    if ($city === '') {
        apiResponse(
            false,
            'Current city is required.',
            null,
            400
        );
    }

    if ($state === '') {
        apiResponse(
            false,
            'Current state is required.',
            null,
            400
        );
    }

    if (!preg_match(
        '/^[0-9]{6}$/',
        $pincode
    )) {
        apiResponse(
            false,
            'Invalid current pincode.',
            null,
            400
        );
    }

    if ($permnt_address === '') {
        apiResponse(
            false,
            'Permanent address is required.',
            null,
            400
        );
    }

    if ($permnt_city === '') {
        apiResponse(
            false,
            'Permanent city is required.',
            null,
            400
        );
    }

    if ($permnt_state === '') {
        apiResponse(
            false,
            'Permanent state is required.',
            null,
            400
        );
    }

    if (!preg_match(
        '/^[0-9]{6}$/',
        $permnt_pincode
    )) {
        apiResponse(
            false,
            'Invalid permanent pincode.',
            null,
            400
        );
    }

    /* ======================================================
       CHECK EXISTING PENDING REQUEST
    ====================================================== */

    $pendingSql = "
        SELECT
            ID,
            STATUS,
            ASON_DATE,

            NEW_ADDRESS,
            NEW_CITY,
            NEW_STATE,
            NEW_PINCODE,

            NEW_PERMNT_ADDRESS,
            NEW_PERMNT_CITY,
            NEW_PERMNT_STATE,
            NEW_PERMNT_PINCODE,

            DOC_NAME1,
            DOC_PATH1

        FROM EPT_HR_EMP_INFO_REQ

        WHERE EMP_CODE = :emp_code

          AND STATUS IN ('N', 'T')

        ORDER BY ID DESC
    ";

    $pendingStmt = oci_parse(
        $sql___func___con,
        $pendingSql
    );

    if (!$pendingStmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $pendingSql
        );

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to check existing address request.',
            null,
            500,
            $error ?: []
        );
    }

    oci_bind_by_name(
        $pendingStmt,
        ':emp_code',
        $emp_code
    );

    if (!oci_execute($pendingStmt)) {

        $error = oci_error(
            $pendingStmt
        );

        logOracleError(
            $error,
            $pendingSql
        );

        oci_free_statement($pendingStmt);

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to check existing address request.',
            null,
            500,
            $error ?: []
        );
    }

    $pendingRequest = oci_fetch_assoc(
        $pendingStmt
    );

    oci_free_statement($pendingStmt);

    /* ======================================================
       EXISTING REQUEST FOUND
    ====================================================== */

    if ($pendingRequest) {

        apiResponse(
            false,
            'An address change request already exists and is pending for authorisation.',
            [
                'request_id' =>
                    $pendingRequest['ID'],

                'status' =>
                    $pendingRequest['STATUS'],

                'requested_on' =>
                    $pendingRequest['ASON_DATE'],

                'new_address' => [
                    'address' =>
                        $pendingRequest['NEW_ADDRESS'] ?? '',

                    'city' =>
                        $pendingRequest['NEW_CITY'] ?? '',

                    'state' =>
                        $pendingRequest['NEW_STATE'] ?? '',

                    'pincode' =>
                        $pendingRequest['NEW_PINCODE'] ?? ''
                ],

                'new_permanent_address' => [
                    'address' =>
                        $pendingRequest['NEW_PERMNT_ADDRESS'] ?? '',

                    'city' =>
                        $pendingRequest['NEW_PERMNT_CITY'] ?? '',

                    'state' =>
                        $pendingRequest['NEW_PERMNT_STATE'] ?? '',

                    'pincode' =>
                        $pendingRequest['NEW_PERMNT_PINCODE'] ?? ''
                ],

                'document' => [
                    'name' =>
                        $pendingRequest['DOC_NAME1'] ?? '',

                    'path' =>
                        $pendingRequest['DOC_PATH1'] ?? ''
                ]
            ],
            400
        );
    }

    /* ======================================================
       ADDRESS PROOF
    ====================================================== */

    if (!isset(
        $_FILES['address_proof']
    )) {

        apiResponse(
            false,
            'Address proof is required.',
            null,
            400
        );
    }

    $file = $_FILES['address_proof'];

    if (
        $file['error'] !==
        UPLOAD_ERR_OK
    ) {

        apiResponse(
            false,
            'Unable to upload address proof.',
            null,
            400
        );
    }

    /* ======================================================
       FILE SIZE
    ====================================================== */

    $maxFileSize =
        5 * 1024 * 1024;

    if (
        ($file['size'] ?? 0) >
        $maxFileSize
    ) {

        apiResponse(
            false,
            'Address proof file must not exceed 5 MB.',
            null,
            400
        );
    }

    /* ======================================================
       FILE EXTENSION
    ====================================================== */

    $allowedExtensions = [
        'pdf',
        'jpg',
        'jpeg',
        'png'
    ];

    $originalName = basename(
        $file['name']
    );

    $extension = strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );

    if (!in_array(
        $extension,
        $allowedExtensions,
        true
    )) {

        apiResponse(
            false,
            'Invalid address proof file type.',
            null,
            400
        );
    }

    /* ======================================================
       MIME VALIDATION
    ====================================================== */

    $allowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png'
    ];

    $finfo = new finfo(
        FILEINFO_MIME_TYPE
    );

    $mimeType = $finfo->file(
        $file['tmp_name']
    );

    if (!in_array(
        $mimeType,
        $allowedMimeTypes,
        true
    )) {

        apiResponse(
            false,
            'Invalid address proof file content.',
            null,
            400
        );
    }

    /* ======================================================
       CREATE UPLOAD DIRECTORY
    ====================================================== */

    $uploadDir =
        '/mnt/documents/uploads/address_proof/';

    if (!is_dir(
        $uploadDir
    )) {

        if (!mkdir(
            $uploadDir,
            0755,
            true
        )) {

            apiResponse(
                false,
                'Unable to create document directory.',
                null,
                500
            );
        }
    }

    /* ======================================================
       SAFE FILE NAME
    ====================================================== */

    $safeFileName =
        $emp_code
        . '_'
        . date('YmdHis')
        . '_address_proof.'
        . $extension;

    $targetFile =
        $uploadDir
        . $safeFileName;

    /* ======================================================
       MOVE FILE
    ====================================================== */

    if (!move_uploaded_file(
        $file['tmp_name'],
        $targetFile
    )) {

        apiResponse(
            false,
            'Unable to save address proof.',
            null,
            500
        );
    }

    /* ======================================================
       DATABASE DOCUMENT PATH
    ====================================================== */

    $documentPath =
        'uploads/address_proof/'
        . $safeFileName;

    /* ======================================================
       GET CURRENT MASTER DATA
    ====================================================== */

    $masterSql = '
        SELECT

            MOBILE_NO AS CELL,
            EMAIL_ID_PER AS PER_EMAIL,

            CUR_ADD1,
            CUR_ADD2,
            CUR_ADD3,

            CUR_CITY AS CITY,
            CUR_STATE AS STATE,
            CUR_PIN AS PINCODE,

            PER_ADD1,
            PER_ADD2,
            PER_ADD3,

            PER_CITY,
            PER_STATE,
            PER_PIN,

            M_STATUS

        FROM EPT_BCS_EMPLOYEE

        WHERE EMP_CODE = :emp_code
    ';

    $stmt = oci_parse(
        $sql___func___con,
        $masterSql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $masterSql
        );

        if (file_exists(
            $targetFile
        )) {
            @unlink(
                $targetFile
            );
        }

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare employee query.',
            null,
            500,
            $error ?: []
        );
    }

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    if (!oci_execute($stmt)) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $masterSql
        );

        oci_free_statement($stmt);

        if (file_exists(
            $targetFile
        )) {
            @unlink(
                $targetFile
            );
        }

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to fetch employee details.',
            null,
            500,
            $error ?: []
        );
    }

    $employee = oci_fetch_assoc(
        $stmt
    );

    oci_free_statement($stmt);

    if (!$employee) {

        if (file_exists(
            $targetFile
        )) {
            @unlink(
                $targetFile
            );
        }

        apiResponse(
            false,
            'Employee details not found.',
            null,
            404
        );
    }

    /* ======================================================
       CURRENT MASTER ADDRESS
    ====================================================== */

    $oldAddress = trim(
        ($employee['CUR_ADD1'] ?? '')
        . ' '
        . ($employee['CUR_ADD2'] ?? '')
        . ' '
        . ($employee['CUR_ADD3'] ?? '')
    );

    $oldPermanentAddress = trim(
        ($employee['PER_ADD1'] ?? '')
        . ' '
        . ($employee['PER_ADD2'] ?? '')
        . ' '
        . ($employee['PER_ADD3'] ?? '')
    );

    /* ======================================================
       STATUS FOR NEW REQUEST
    ====================================================== */

    $status = 'N';

    /* ======================================================
       INSERT ADDRESS REQUEST
    ====================================================== */

    $insertSql = "
        INSERT INTO EPT_HR_EMP_INFO_REQ
        (
            ASON_DATE,
            EMP_CODE,

            CELL,
            PER_EMAIL,

            ADDRESS,
            CITY,
            STATE,
            PINCODE,

            PERMNT_ADDRESS,
            PERMNT_CITY,
            PERMNT_STATE,
            PERMNT_PINCODE,

            M_STATUS,

            NEW_ADDRESS,
            NEW_CITY,
            NEW_STATE,
            NEW_PINCODE,

            NEW_PERMNT_ADDRESS,
            NEW_PERMNT_CITY,
            NEW_PERMNT_STATE,
            NEW_PERMNT_PINCODE,

            DOC_NAME1,
            DOC_PATH1,

            STATUS,
            OTP_AUTH,

            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            SYSDATE,
            :emp_code,

            :cell,
            :per_email,

            :old_address,
            :old_city,
            :old_state,
            :old_pincode,

            :old_perm_address,
            :old_perm_city,
            :old_perm_state,
            :old_perm_pincode,

            :m_status,

            :new_address,
            :new_city,
            :new_state,
            :new_pincode,

            :new_perm_address,
            :new_perm_city,
            :new_perm_state,
            :new_perm_pincode,

            :doc_name,
            :doc_path,

            :status,
            'Y',

            SYSDATE,
            :chg_by
        )
        RETURNING ID INTO :request_id
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $insertSql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $insertSql
        );

        if (file_exists(
            $targetFile
        )) {
            @unlink(
                $targetFile
            );
        }

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to prepare address request.',
            null,
            500,
            $error ?: []
        );
    }

    $request_id = null;

    /* ======================================================
       OLD DATA
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':emp_code',
        $emp_code
    );

    oci_bind_by_name(
        $stmt,
        ':cell',
        $employee['CELL']
    );

    oci_bind_by_name(
        $stmt,
        ':per_email',
        $employee['PER_EMAIL']
    );

    oci_bind_by_name(
        $stmt,
        ':old_address',
        $oldAddress
    );

    oci_bind_by_name(
        $stmt,
        ':old_city',
        $employee['CITY']
    );

    oci_bind_by_name(
        $stmt,
        ':old_state',
        $employee['STATE']
    );

    oci_bind_by_name(
        $stmt,
        ':old_pincode',
        $employee['PINCODE']
    );

    oci_bind_by_name(
        $stmt,
        ':old_perm_address',
        $oldPermanentAddress
    );

    oci_bind_by_name(
        $stmt,
        ':old_perm_city',
        $employee['PER_CITY']
    );

    oci_bind_by_name(
        $stmt,
        ':old_perm_state',
        $employee['PER_STATE']
    );

    oci_bind_by_name(
        $stmt,
        ':old_perm_pincode',
        $employee['PER_PIN']
    );

    oci_bind_by_name(
        $stmt,
        ':m_status',
        $employee['M_STATUS']
    );

    /* ======================================================
       NEW CURRENT ADDRESS
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':new_address',
        $address
    );

    oci_bind_by_name(
        $stmt,
        ':new_city',
        $city
    );

    oci_bind_by_name(
        $stmt,
        ':new_state',
        $state
    );

    oci_bind_by_name(
        $stmt,
        ':new_pincode',
        $pincode
    );

    /* ======================================================
       NEW PERMANENT ADDRESS
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':new_perm_address',
        $permnt_address
    );

    oci_bind_by_name(
        $stmt,
        ':new_perm_city',
        $permnt_city
    );

    oci_bind_by_name(
        $stmt,
        ':new_perm_state',
        $permnt_state
    );

    oci_bind_by_name(
        $stmt,
        ':new_perm_pincode',
        $permnt_pincode
    );

    /* ======================================================
       DOCUMENT
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':doc_name',
        $originalName
    );

    oci_bind_by_name(
        $stmt,
        ':doc_path',
        $documentPath
    );

    /* ======================================================
       STATUS
    ====================================================== */

    oci_bind_by_name(
        $stmt,
        ':status',
        $status
    );

    oci_bind_by_name(
        $stmt,
        ':chg_by',
        $emp_code
    );

    oci_bind_by_name(
        $stmt,
        ':request_id',
        $request_id,
        10
    );

    /* ======================================================
       EXECUTE INSERT
    ====================================================== */

    if (!oci_execute(
        $stmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($stmt);

        logOracleError(
            $error,
            $insertSql
        );

        oci_rollback(
            $sql___func___con
        );

        oci_free_statement($stmt);

        if (file_exists(
            $targetFile
        )) {
            @unlink(
                $targetFile
            );
        }

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to create address request.',
            null,
            500,
            $error ?: []
        );
    }

    oci_free_statement($stmt);

    /* ======================================================
       COMMIT
    ====================================================== */

    if (!oci_commit(
        $sql___func___con
    )) {

        $error = oci_error(
            $sql___func___con
        );

        logOracleError(
            $error,
            $insertSql
        );

        oci_rollback(
            $sql___func___con
        );

        if (file_exists(
            $targetFile
        )) {
            @unlink(
                $targetFile
            );
        }

        apiResponse(
            false,
            $error['message']
                ?? 'Unable to commit address request.',
            null,
            500,
            $error ?: []
        );
    }

    /* ======================================================
       SUCCESS
    ====================================================== */

    apiResponse(
        true,
        'Address change request submitted successfully for authorisation.',
        [
            'request_id' =>
                $request_id,

            'status' =>
                $status
        ],
        200
    );
}

/* ==========================================================
   INVALID ACTION
========================================================== */

apiResponse(
    false,
    'Invalid request action.',
    null,
    400
);