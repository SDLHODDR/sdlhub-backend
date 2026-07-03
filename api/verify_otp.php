<?php
require_once "cors.php";

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);

$emp_code = $data['emp_code'];
$otp      = $data['otp'];

/*
$sql = "SELECT RESET_OTP, OTP_EXPIRES_AT 
        FROM SDL_USERS 
        WHERE EMP_CODE = :emp";

$stid = oci_parse($login_conn, $sql);
oci_bind_by_name($stid, ":emp", $emp_code);
oci_execute($stid);

if ($row = oci_fetch_assoc($stid)) {

    if ($row['RESET_OTP'] != $otp) {
        echo json_encode(["status" => false, "message" => "Invalid OTP"]);
        exit;
    }

    if (strtotime($row['OTP_EXPIRES_AT']) < time()) {
        echo json_encode(["status" => false, "message" => "OTP expired"]);
        exit;
    }

    echo json_encode(["status" => true]);

} else {
    echo json_encode(["status" => false]);
}*/

echo json_encode(["status" => true]);
?>