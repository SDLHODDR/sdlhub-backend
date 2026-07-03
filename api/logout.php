<?php
require_once "cors.php";
require_once "config/session.php";

header("Content-Type: application/json");

/* Clear session data */
$_SESSION = [];

/* Destroy cookie */
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* Destroy session */
session_destroy();

echo json_encode([
    "status" => true,
    "message" => "Logged out successfully"
]);

exit;
