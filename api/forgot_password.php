<?php
require_once "cors.php";
require_once "config/db.php";

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$emp_code = $data['emp_code'] ?? null;

if (!$emp_code) {
    echo json_encode(["status" => false, "message" => "Employee code required"]);
    exit;
}

/* -----------------------
   CHECK USER EXISTS
------------------------*/
$sql = "SELECT MOBILE_NO FROM SDL_USERS 
        WHERE EMP_CODE = :emp 
        AND STATUS = 'A'";

$stid = oci_parse($login_conn, $sql);
oci_bind_by_name($stid, ":emp", $emp_code);
oci_execute($stid);

if ($row = oci_fetch_assoc($stid)) {

    $mobile = $row['MOBILE_NO'];

    if (!$mobile) {
        echo json_encode([
            "status" => false,
            "message" => "Mobile number not registered"
        ]);
        exit;
    }

    /* -----------------------
       GENERATE OTP
    ------------------------*/
    $otp = rand(100000, 999999);
    $expiry = date('d-M-Y H:i:s', strtotime('+5 minutes'));

    /* -----------------------
       SAVE OTP IN DB
    ------------------------*/
    $updateSql = "UPDATE SDL_USERS
                  SET RESET_OTP = :otp,
                      OTP_EXPIRES_AT = TO_DATE(:exp,'DD-MON-YYYY HH24:MI:SS')
                  WHERE EMP_CODE = :emp";

    $updateStid = oci_parse($login_conn, $updateSql);
    oci_bind_by_name($updateStid, ":otp", $otp);
    oci_bind_by_name($updateStid, ":exp", $expiry);
    oci_bind_by_name($updateStid, ":emp", $emp_code);
    oci_execute($updateStid);

    /* -----------------------
       SEND SMS HERE
    ------------------------*/
    // call SMS API here
    // sendSms($mobile, "Your OTP is $otp");

    echo json_encode([
        "status" => true,
        "message" => "OTP sent to registered mobile. ".$otp
    ]);

} else {
    echo json_encode([
        "status" => false,
        "message" => "Employee not found"
    ]);
}
