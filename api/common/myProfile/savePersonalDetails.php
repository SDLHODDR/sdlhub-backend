<?php

/* ==========================================================
   EMPLOYEE PERSONAL DETAILS UPDATE API
   ----------------------------------------------------------
   Actions:
   1. send_otp
      - Validate submitted personal details
      - Compare with current employee details
      - Insert request into HR_EMP_INFO_REQ
      - Generate TEST OTP = 1234
      - Return test OTP

   2. verify_otp
      - Validate OTP
      - Find latest pending request
      - Check OTP expiry using Oracle SYSDATE
      - Update OTP_AUTH = Y

   ========================================================== */


require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");


/* ==========================================================
   HELPER
   ========================================================== */

function esc($value)
{
    $value = trim((string)$value);

    return str_replace("'", "''", $value);
}


/* ==========================================================
   GET ACTION
   ========================================================== */

$action = trim((string)($_POST['action'] ?? ''));


/* ==========================================================
   GET EMPLOYEE CODE
   ========================================================== */

$empCode = '';

if (isset($_SESSION['emp_code'])) {
    $empCode = trim((string)$_SESSION['emp_code']);
}

if ($empCode === '' && isset($_SESSION['EMP_CODE'])) {
    $empCode = trim((string)$_SESSION['EMP_CODE']);
}

if ($empCode === '' && isset($_POST['emp_code'])) {
    $empCode = trim((string)$_POST['emp_code']);
}


if ($empCode === '') {

    apiResponse(
        false,
        "Employee code is required.",
        null,
        400
    );

    exit;
}


$empCodeEsc = esc($empCode);


/* ==========================================================
   ACTION VALIDATION
   ========================================================== */

if ($action === '') {

    apiResponse(
        false,
        "Action is required.",
        null,
        400
    );

    exit;
}

/* ==========================================================
   SEND OTP
   ========================================================== */

if ($action === 'send_otp') {

    /* ======================================================
       GET FORM DATA
       ====================================================== */

    $cell = trim((string)($_POST['cell'] ?? ''));
    $perEmail = trim((string)($_POST['per_email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $pincode = trim((string)($_POST['pincode'] ?? ''));
    $maritalStatus = trim(
        (string)($_POST['m_status'] ?? '')
    );
    $bloodGroup = trim(
        (string)($_POST['blood_group'] ?? '')
    );

    /* ======================================================
       VALIDATION
       ====================================================== */

    if ($cell === '') {

        apiResponse(
            false,
            "Mobile number is required.",
            null,
            400
        );

        exit;
    }


    if ($perEmail === '') {

        apiResponse(
            false,
            "Personal email is required.",
            null,
            400
        );

        exit;
    }


    if ($address === '') {

        apiResponse(
            false,
            "Address is required.",
            null,
            400
        );

        exit;
    }


    if ($city === '') {

        apiResponse(
            false,
            "City is required.",
            null,
            400
        );

        exit;
    }


    if ($state === '') {

        apiResponse(
            false,
            "State is required.",
            null,
            400
        );

        exit;
    }


    if (!preg_match('/^[0-9]{6}$/', $pincode)) {

        apiResponse(
            false,
            "Please enter a valid 6-digit pincode.",
            null,
            400
        );

        exit;
    }


    if (
        $maritalStatus !== '0' &&
        $maritalStatus !== '1'
    ) {

        apiResponse(
            false,
            "Invalid marital status.",
            null,
            400
        );

        exit;
    }


    /* ======================================================
       EMAIL VALIDATION
       ====================================================== */

    if (!filter_var($perEmail, FILTER_VALIDATE_EMAIL)) {

        apiResponse(
            false,
            "Please enter a valid personal email address.",
            null,
            400
        );

        exit;
    }

    /* ======================================================
       FILE VALIDATION
       ====================================================== */

    $fileName = '';
    $fileTmpName = '';
    $fileType = '';
    $fileSize = 0;


    if (
        isset($_FILES['attachment']) &&
        $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {

            apiResponse(
                false,
                "Unable to upload attachment.",
                null,
                400
            );

            exit;
        }


        $fileName = basename(
            (string)$_FILES['attachment']['name']
        );

        $fileTmpName = (string)$_FILES['attachment']['tmp_name'];

        $fileType = strtolower(
            pathinfo($fileName, PATHINFO_EXTENSION)
        );

        $fileSize = (int)$_FILES['attachment']['size'];


        /* Maximum 5 MB */

        if ($fileSize > (5 * 1024 * 1024)) {

            apiResponse(
                false,
                "Attachment size cannot exceed 5 MB.",
                null,
                400
            );

            exit;
        }


        /* Allowed extensions */

        $allowedExtensions = [
            'pdf',
            'jpg',
            'jpeg',
            'png'
        ];


        if (!in_array(
            $fileType,
            $allowedExtensions,
            true
        )) {

            apiResponse(
                false,
                "Only PDF, JPG, JPEG and PNG files are allowed.",
                null,
                400
            );

            exit;
        }
    }

   /* ================================================================
        DATABASE FILE PATH
    ================================================================= */
    $dbFilePath = "assets/profile_documents/" . $empCodeEsc . "/" . $fileName;

    /* ======================================================
       FETCH CURRENT EMPLOYEE DETAILS
       ====================================================== */

    startQry();


    try {

        $employeeRows = executeSelectQry("
             SELECT 
                EMP_CODE,

                MOBILE_NO       AS CELL,
                EMAIL_ID_PER    AS PER_EMAIL,

                CUR_ADD1,
                CUR_ADD2,
                CUR_ADD3,
                CUR_CITY,
                CUR_STATE,
                CUR_PIN         AS CUR_PINCODE,

                PER_ADD1,
                PER_ADD2,
                PER_ADD3,
                PER_CITY,
                PER_STATE,
                PER_PIN         AS PER_PINCODE,

                M_STATUS        AS MARITAL_STATUS,
                BLOOD_GRP       AS BLOOD_GROUP

            FROM EPT_BCS_EMPLOYEE
            WHERE EMP_CODE = '{$empCodeEsc}'
        ");

        

        if ($employeeRows === false) {

            forceRollback();

            apiResponse(
                false,
                "Unable to fetch current employee details.",
                null,
                500
            );

            exit;
        }

        if (empty($employeeRows)) {

            forceRollback();

            apiResponse(
                false,
                "Employee details not found.",
                null,
                404
            );

            exit;
        }

        $employee = $employeeRows[0];

        /* ==========================================================
            HELPER - BUILD ADDRESS
            ========================================================== */

            function buildAddress($part1, $part2, $part3)
            {
                $parts = [];

                foreach ([
                    $part1,
                    $part2,
                    $part3
                ] as $part) {

                    $part = trim((string)$part);

                    if ($part !== '') {
                        $parts[] = $part;
                    }
                }

                return implode(', ', $parts);
            }

        /* ==================================================
           CURRENT VALUES
           ================================================== */

        $oldCell = trim((string)($employee['CELL'] ?? ''));
        $oldEmail = trim((string)($employee['PER_EMAIL'] ?? ''));
        $oldAddress = buildAddress( $employee['CUR_ADD1'] ?? '', $employee['CUR_ADD2'] ?? '', $employee['CUR_ADD3'] ?? '' );
        $oldCity = trim((string)($employee['CUR_CITY'] ?? ''));
        $oldState = trim((string)($employee['CUR_STATE'] ?? ''));
        $oldPincode = trim((string)($employee['CUR_PINCODE'] ?? ''));
        $oldPermntAddress = trim((string)($employee['PER_ADDRESS'] ?? ''));
        $oldPermntCity = trim((string)($employee['PER_CITY'] ?? ''));
        $oldPermntState = trim((string)($employee['PER_STATE'] ?? ''));
        $oldPermntPincode = trim((string)($employee['PER_PINCODE'] ?? ''));
        $oldMaritalStatus = trim((string)($employee['MARITAL_STATUS'] ?? ''));
        $oldBloodGroup = trim((string)($employee['BLOOD_GROUP'] ?? ''));
        $oldPermntAddress = buildAddress( $employee['PER_ADD1'] ?? '', $employee['PER_ADD2'] ?? '', $employee['PER_ADD3'] ?? '' );

        /* ==================================================
           CHECK WHETHER ANYTHING HAS CHANGED
           ================================================== */

        $hasChanges = false;


        if ($cell !== $oldCell) {
            $hasChanges = true;
        }

        if ($perEmail !== $oldEmail) {
            $hasChanges = true;
        }

        if ($address !== $oldAddress) {
            $hasChanges = true;
        }

        if ($city !== $oldCity) {
            $hasChanges = true;
        }

        if ($state !== $oldState) {
            $hasChanges = true;
        }

        if ($pincode !== $oldPincode) {
            $hasChanges = true;
        }

        if ($maritalStatus !== $oldMaritalStatus) {
            $hasChanges = true;
        }

        if ($bloodGroup !== $oldBloodGroup) {
            $hasChanges = true;
        }

        /* ==================================================
           NO CHANGES
           ================================================== */

        if (!$hasChanges && $fileName === '') {

            forceRollback();

            apiResponse(
                false,
                "No changes found in personal details.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           CHECK EXISTING PENDING REQUEST
           ==================================================

           Only requests which are:

           OTP_AUTH = N or Y
           AUTH_ON IS NULL

           are considered pending.
           ================================================== */

        $pendingRows = executeSelectQry("
            SELECT
                ID,
                OTP_AUTH,
                CHG_ON
            FROM EPT_HR_EMP_INFO_REQ
            WHERE EMP_CODE = '{$empCodeEsc}'
              AND AUTH_ON IS NULL
              AND OTP_AUTH IN ('N', 'Y')
            ORDER BY ID DESC
            FETCH FIRST 1 ROW ONLY
        ");


        if ($pendingRows === false) {

            forceRollback();

            apiResponse(
                false,
                "Unable to check existing personal details request.",
                null,
                500
            );

            exit;
        }


        if (!empty($pendingRows)) {

            forceRollback();

            apiResponse(
                false,
                "A personal details update request is already pending for authorization.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           GENERATE TEST OTP
           ==================================================

           IMPORTANT:
           Actual SMS integration is NOT active.

           Test OTP:
           1234
           ================================================== */

        $otp = '1234';


        /* ==================================================
           ESCAPE VALUES
           ================================================== */

        $cellEsc = esc($cell);
        $perEmailEsc = esc($perEmail);
        $addressEsc = esc($address);
        $cityEsc = esc($city);
        $stateEsc = esc($state);
        $bloodGroupEsc = esc($bloodGroup);
        $fileNameEsc = esc($fileName);
        $dbFilePathEsc = esc($dbFilePath);

        /* ==================================================
           PINCODE

           We use numeric value because the column is assumed
           to be NUMBER.
           ================================================== */

        $pincodeSql = (int)$pincode;


        /* ==================================================
           MARITAL STATUS

           Expected 0 / 1
           ================================================== */

        $maritalStatusSql = (int)$maritalStatus;


        /* ==================================================
           INSERT REQUEST
           ==================================================

           IMPORTANT:

           Sequence:
               HRMSLIVE.HR_EMP_INFO_REQ_SEQ.NEXTVAL

           Synonym:
               EPT_HR_EMP_INFO_REQ

           OTP:
               1234

           OTP_AUTH:
               N

           AUTH_ON:
               NULL
           ================================================== */        
            executeQry("
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
                    M_STATUS,

                    NEW_CELL,
                    NEW_PER_EMAIL,
                    NEW_ADDRESS,
                    NEW_CITY,
                    NEW_STATE,
                    NEW_PINCODE,

                    NEW_PERMNT_ADDRESS,
                    NEW_PERMNT_CITY,
                    NEW_PERMNT_STATE,
                    NEW_PERMNT_PINCODE,

                    NEW_M_STATUS,

                    DOC_NAME1,
                    DOC_PATH1,

                    CHG_ON,
                    CHG_BY,

                    OTP_NO,
                    OTP_AUTH,
                    TASK_ID,
                    AUTH_ON,
                    AUTH_BY
                )
                VALUES
                (
                    SYSDATE,
                    '{$empCodeEsc}',

                    /* OLD / CURRENT DETAILS */
                    '{$oldCell}',
                    '{$oldEmail}',
                    '{$oldAddress}',
                    '{$oldCity}',
                    '{$oldState}',
                    {$oldPincode},
                    {$oldMaritalStatus},

                    /* NEW DETAILS */
                    '{$cellEsc}',
                    '{$perEmailEsc}',
                    '{$addressEsc}',
                    '{$cityEsc}',
                    '{$stateEsc}',
                    {$pincodeSql},

                    /* NEW PERMANENT ADDRESS */
                    '{$oldPermntAddress}',
                    '{$oldPermntCity}',
                    '{$oldPermntState}',
                    '{$oldPermntPincode}',

                    /* NEW MARITAL STATUS */
                    {$maritalStatusSql},

                    /* DOCUMENT */
                    '{$fileNameEsc}',
                    '{$dbFilePathEsc}',

                    /* AUDIT */
                    SYSDATE,
                    '{$empCodeEsc}',

                    /* OTP */
                    {$otp},
                    'N',

                    /* AUTHORIZATION */
                    NULL,
                    NULL,
                    NULL
                )
            ");
        
        /* ==================================================
           CHECK INSERT
           ================================================== */

        if ($qry_____result != 0) {

            forceRollback();

            apiResponse(
                false,
                "Unable to create personal details update request.",
                null,
                500
            );
            exit;
        }

        /* ==================================================
           GET INSERTED REQUEST ID
           ================================================== */

        $requestIdRows = executeSelectQry("
            SELECT
                ID
            FROM EPT_HR_EMP_INFO_REQ
            WHERE EMP_CODE = '{$empCodeEsc}'
              AND OTP_NO = {$otp}
              AND OTP_AUTH = 'N'
              AND AUTH_ON IS NULL
            ORDER BY ID DESC
            FETCH FIRST 1 ROW ONLY
        ");

        if ($requestIdRows === false || empty($requestIdRows)) {
            forceRollback();
            apiResponse(
                false,
                "Request created but request ID could not be retrieved.",
                null,
                500
            );

            exit;
        }

        $requestId = (int)(
            $requestIdRows[0]['ID'] ?? 0
        );

        /* ==================================================
           COMMIT
           ================================================== */

        endQry();


        /* ==================================================
           SIMULATED SMS
           ==================================================

           Actual SMS service is not integrated.

           Therefore we return test_otp.
           ================================================== */

        apiResponse(
            true,
            "OTP sent successfully.",
            [
                "request_id" => $requestId,
                "otp_required" => true,

                /* TESTING ONLY */
                "test_otp" => $otp
            ],
            200
        );

        exit;


    }
    catch (Throwable $e) {

        try {
            forceRollback();
        }
        catch (Throwable $rollbackError) {
        }


        $errorMessage = $e->getMessage();


        if (function_exists('writeErrorLog')) {

            try {

                writeErrorLog(
                    "HR Employee Personal Details - Send OTP Error: "
                    . $errorMessage
                );

            }
            catch (Throwable $logError) {
            }
        }


        apiResponse(
            false,
            "Unable to send OTP.",
            null,
            500
        );

        exit;
    }
}

/* ==========================================================
   VERIFY OTP
   ========================================================== */

if ($action === 'verify_otp') {

    /* ======================================================
       GET OTP
       ====================================================== */

    $otp = trim(
        (string)($_POST['otp'] ?? '')
    );

    /* ======================================================
       OTP VALIDATION
       ====================================================== */

    if ($otp === '') {

        apiResponse(
            false,
            "OTP is required.",
            null,
            400
        );

        exit;
    }


    if (!preg_match('/^[0-9]{4}$/', $otp)) {

        apiResponse(
            false,
            "Please enter a valid 4-digit OTP.",
            null,
            400
        );

        exit;
    }

    /* ======================================================
       START TRANSACTION
       ====================================================== */

    startQry();

    try {

        /* ==================================================
           FIND LATEST PENDING REQUEST
           ==================================================

           IMPORTANT:

           Expiry is checked by Oracle.

           10 minutes:
               10 / 1440

           Because Oracle DATE stores date + time, this avoids
           PHP strtotime() / NLS / timezone problems.
           ================================================== */

        $requestRows = executeSelectQry("
            SELECT
                ID,
                EMP_CODE,
                OTP_NO,
                OTP_AUTH,
                CHG_ON,
                AUTH_ON,
                SYSDATE AS DB_TIME,

                CASE
                    WHEN CHG_ON IS NOT NULL
                     AND CHG_ON >= SYSDATE - (10 / 1440)
                    THEN 1
                    ELSE 0
                END AS OTP_VALID

            FROM EPT_HR_EMP_INFO_REQ

            WHERE EMP_CODE = '{$empCodeEsc}'
              AND OTP_AUTH = 'N'
              AND AUTH_ON IS NULL

            ORDER BY ID DESC

            FETCH FIRST 1 ROW ONLY
        ");


        /* ==================================================
           SQL ERROR
           ================================================== */

        if ($requestRows === false) {

            forceRollback();

            apiResponse(
                false,
                "Unable to fetch pending OTP request.",
                null,
                500
            );

            exit;
        }


        /* ==================================================
           NO REQUEST
           ================================================== */

        if (empty($requestRows)) {

            forceRollback();

            apiResponse(
                false,
                "No pending OTP request found. Please request a new OTP.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           GET REQUEST
           ================================================== */

        $request = $requestRows[0];


        $requestId = (int)(
            $request['ID'] ?? 0
        );


        $storedOtp = trim(
            (string)($request['OTP_NO'] ?? '')
        );


        $otpValid = (int)(
            $request['OTP_VALID'] ?? 0
        );


        /* ==================================================
           VALIDATE REQUEST ID
           ================================================== */

        if ($requestId <= 0) {

            forceRollback();

            apiResponse(
                false,
                "Invalid OTP request.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           CHECK OTP EXPIRY
           ================================================== */

        if ($otpValid !== 1) {

            forceRollback();

            apiResponse(
                false,
                "OTP has expired. Please request a new OTP.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           STORED OTP VALIDATION
           ================================================== */

        if ($storedOtp === '') {

            forceRollback();

            apiResponse(
                false,
                "OTP is not available for this request. Please request a new OTP.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           COMPARE OTP
           ================================================== */

        if ($storedOtp !== $otp) {

            forceRollback();

            apiResponse(
                false,
                "Invalid OTP. Please enter the correct OTP.",
                null,
                400
            );

            exit;
        }


        /* ==================================================
           UPDATE OTP_AUTH

           N = Not Verified
           Y = Verified
           ================================================== */

        executeQry("
            UPDATE EPT_HR_EMP_INFO_REQ

            SET OTP_AUTH = 'Y'

            WHERE ID = {$requestId}

              AND EMP_CODE = '{$empCodeEsc}'

              AND OTP_AUTH = 'N'

              AND AUTH_ON IS NULL

              AND CHG_ON IS NOT NULL

              AND CHG_ON >= SYSDATE - (10 / 1440)
        ");


        /* ==================================================
           CHECK UPDATE
           ================================================== */

        if ($qry_____result != 0) {

            forceRollback();

            apiResponse(
                false,
                "Unable to verify OTP. Please try again.",
                null,
                500
            );

            exit;
        }


        /* ==================================================
           COMMIT
           ================================================== */

        endQry();


        /* ==================================================
           SUCCESS
           ================================================== */

        apiResponse(
            true,
            "OTP verified successfully.",
            [
                "request_id" => $requestId,
                "emp_code" => $empCode,
                "otp_verified" => true
            ],
            200
        );

        exit;


    }
    catch (Throwable $e) {


        /* ==================================================
           ROLLBACK
           ================================================== */

        try {
            forceRollback();
        }
        catch (Throwable $rollbackError) {
        }


        /* ==================================================
           LOG
           ================================================== */

        $errorMessage = $e->getMessage();


        if (function_exists('writeErrorLog')) {

            try {

                writeErrorLog(
                    "HR Employee Personal Details - Verify OTP Error: "
                    . $errorMessage
                );

            }
            catch (Throwable $logError) {
            }
        }


        /* ==================================================
           RESPONSE
           ================================================== */

        apiResponse(
            false,
            "Unable to verify OTP.",
            null,
            500
        );

        exit;
    }
}



/* ==========================================================
   INVALID ACTION
   ========================================================== */

apiResponse(
    false,
    "Invalid action.",
    null,
    400
);

exit;
