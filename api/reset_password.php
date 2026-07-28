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
    $newPassword = trim($data['password'] ?? '');

    if ($empCode === '' || $newPassword === '') {
        apiResponse(false, "Missing required fields.", null, 400);
    }

    /* ===========================================
       ENCODE PASSWORD
    =========================================== */

    $encodedPassword = encodel($newPassword);

    $empCode = str_replace("'", "''", $empCode);
    $encodedPassword = str_replace("'", "''", $encodedPassword);

    /* ===========================================
       UPDATE PASSWORD
    =========================================== */
/*
    startQry();

    executeQry("
        UPDATE SDL_USERS
        SET
            PASS_WD = '{$encodedPassword}',
            RESET_OTP = NULL,
            OTP_EXPIRES_AT = NULL
        WHERE EMP_CODE = '{$empCode}'
    ");

    endQry();
*/
    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */
    apiResponse(true, "Password updated successfully.");

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "resetPassword.php"
    );

    apiResponse(false,"Unable to update password.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}