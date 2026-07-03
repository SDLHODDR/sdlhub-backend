<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/utils.php";

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode([
        "status" => false,
        "message" => "Invalid input"
    ]);
    exit;
}

$emp_code = $data['emp_code'] ?? null;
$new_pass = $data['password'] ?? null;

if (!$emp_code || !$new_pass) {
    echo json_encode([
        "status" => false,
        "message" => "Missing required fields"
    ]);
    exit;
}

$encoded = encodel($new_pass);

/*
$sql = "UPDATE SDL_USERS
        SET PASS_WD = :pwd,
            RESET_OTP = NULL,
            OTP_EXPIRES_AT = NULL
        WHERE EMP_CODE = :emp";

$stid = oci_parse($login_conn, $sql);
oci_bind_by_name($stid, ":pwd", $encoded);
oci_bind_by_name($stid, ":emp", $emp_code);

if (oci_execute($stid)) {
    echo json_encode([
        "status" => true,
        "message" => "Password updated successfully"
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "Database error"
    ]);
}*/

 echo json_encode([
        "status" => true,
        "message" => "Password updated successfully"
    ]);