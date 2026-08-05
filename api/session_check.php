<?php
/*session_start();
if(!isset($_SESSION['emp_code'])){
    header("Location: /"); exit;
}*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowed_origins = [
    "http://localhost:3000",
    "http://localhost:3001",
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Content-Type: application/json");

if (isset($_SESSION['emp_code'])) {
    echo json_encode([
        "logged_in" => true,
        "emp_code" => $_SESSION['emp_code'],
        "name" => ucfirst($_SESSION['name']),
        "profile_image" => $_SESSION['profile_image'] ?? null
    ]);
} else {
    echo json_encode([
        "logged_in" => false
    ]);
}

session_write_close();

exit;

?>


