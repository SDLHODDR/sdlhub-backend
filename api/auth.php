<?php
require_once "cors.php";
require_once "config/db.php";
require_once "config/session.php";
require_once "config/utils.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

/* ---------------------------
   READ INPUT
---------------------------- */
$data = json_decode(file_get_contents("php://input"), true);

$username = $data['login_code'] ?? null;
$password = $data['password'] ?? null;

if (!$username || !$password) {
    echo json_encode([
        "status"  => false,
        "message" => "Login & Password required"
    ]);
    exit;
}

/* ---------------------------
   AUTHENTICATION
---------------------------- */
$encoded = encodel($password);

$sql = "
SELECT EMP_CODE, NAME
FROM SDL_USERS
WHERE EMP_CODE = :u

AND STATUS = 'A'
"; //AND PASS_WD = :p

$stid = oci_parse($login_conn, $sql);
oci_bind_by_name($stid, ":u", $username);
//oci_bind_by_name($stid, ":p", $encoded);
oci_execute($stid);

if ($row = oci_fetch_assoc($stid)) {

    session_regenerate_id(true);

    /* ---------------------------
       SET SESSION
    ---------------------------- */
    $_SESSION['emp_code'] = $row['EMP_CODE'];
    $_SESSION['name']     = $row['NAME'];
    

    $profileImage = null;

    $rootPath = realpath(__DIR__ . "/../..");
    $filePath = $rootPath . "/public/assets/img/profiles/" . $row['EMP_CODE'] . ".jpg";
    $baseUrl = "http://localhost/sdlhub_new/public/assets/img/profiles/";

    if (file_exists($filePath)) {
        $profileImage = $baseUrl . $row['EMP_CODE'] . ".jpg"; //?v=" . filemtime($filePath)
    }

    $row['profile_image'] = $profileImage;
    $_SESSION['profile_image'] = $profileImage;

     /* ---------------------------
       GENERATE CSRF TOKEN (ONCE)
    ---------------------------- */
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    echo json_encode([
        "status"     => true,
        "message"    => "Login successful",
        "user"       => $row,
        "csrf_token" => $_SESSION['csrf_token'],
        "redirect"   => "/eportal/dashboard"
    ]);

} else {

    echo json_encode([
        "status"  => false,
        "message" => "Invalid login"
    ]);
}
session_write_close();

