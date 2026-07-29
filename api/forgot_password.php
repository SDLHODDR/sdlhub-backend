<?php

require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/config/db.php";

$sql___func___con = $login_conn;   // Use db_login() for SDL_USERS database

require_once __DIR__ . "/config/functions.php";
require_once __DIR__ . "/config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

try {

    /* ===========================================
       READ INPUT
    =========================================== */

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        apiResponse(false, "Invalid request.", null, 400);
    }

    $empCode = trim($data['emp_code'] ?? '');

    if ($empCode === '') {
        apiResponse(false, "Employee code required.", null, 400);
    }

    $empCode = str_replace("'", "''", $empCode);

    /* ===========================================
       CHECK EMPLOYEE
    =========================================== */

    $user = singRec("
        SELECT MOBILE_NO
        FROM SDL_USERS
        WHERE EMP_CODE = '{$empCode}'
        AND STATUS = 'A'
    ");

    if (empty($user)) {
        apiResponse(false, "Employee not found.", null, 404);
    }

    $mobile = trim($user['MOBILE_NO'] ?? '');

    if ($mobile === '') {
        apiResponse(false, "Mobile number not registered.");
    }

    /* ===========================================
       GENERATE OTP
    =========================================== */

    $otp = random_int(100000, 999999);

    $expiry = strtoupper(
        date("d-M-Y H:i:s", strtotime("+5 minutes"))
    );

    /* ===========================================
       SAVE OTP
    =========================================== */

    startQry();

    executeQry("
        UPDATE SDL_USERS
        SET
            RESET_OTP = '{$otp}',
            OTP_EXPIRES_AT = TO_DATE(
                '{$expiry}',
                'DD-MON-YYYY HH24:MI:SS'
            )
        WHERE EMP_CODE = '{$empCode}'
    ");

    endQry();

    /* ===========================================
       SEND SMS
    =========================================== */

    // sendSms($mobile, "Your OTP is {$otp}");

    apiResponse(
        true,
        "OTP sent to registered mobile.{$otp}",
        [
            "mobile" => $mobile,
            // Remove otp in production
            "otp" => $otp
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "forgotPasswordSendOtp.php"
    );

    apiResponse(false, "Unable to process your request.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}