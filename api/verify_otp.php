<?php

require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/config/db.php";

global $login_conn;
$sql___func___con = $login_conn;

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
    $otp = trim($data['otp'] ?? '');

    if ($empCode === '' || $otp === '') {
        apiResponse(false, "Employee code and OTP are required.", null, 400);
    }

    $empCode = str_replace("'", "''", $empCode);

    /* ===========================================
       FETCH OTP DETAILS
    =========================================== */
    /*
    $user = singRec("
        SELECT
            RESET_OTP,
            TO_CHAR(
                OTP_EXPIRES_AT,
                'YYYY-MM-DD HH24:MI:SS'
            ) OTP_EXPIRES_AT
        FROM SDL_USERS
        WHERE EMP_CODE = '{$empCode}'
    ");

    if (empty($user)) {
        apiResponse(false, "Employee not found.", null, 404);
    }
    
    /* ===========================================
       VALIDATE OTP
    =========================================== *

    if (($user['RESET_OTP'] ?? '') != $otp) {
        apiResponse(false, "Invalid OTP.");
    }

    if (
        empty($user['OTP_EXPIRES_AT']) ||
        strtotime($user['OTP_EXPIRES_AT']) < time()
    ) {
        apiResponse(false, "OTP has expired.");
    }
    */
    /* ===========================================
       SUCCESS
    =========================================== */

    apiResponse(true, "OTP verified successfully.");

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "verifyOtp.php"
    );

    apiResponse(false, "Unable to verify OTP.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}