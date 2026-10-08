<?php

require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/config/db.php";       // <-- Added: provides $login_conn
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   HANDLE PREFLIGHT (IF NEEDED)
=========================================== */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

try {

    /* ===========================================
       LOG LOGOUT ACTIVITY BEFORE CLEARING SESSION
    =========================================== */

    $empCode = $_SESSION["emp_code"] ?? null;

    if ($empCode && isset($login_conn) && $login_conn) {
        recordUserAuthLog($login_conn, $empCode, 'O');
    }

    /* ===========================================
       CLEAR SESSION DATA
    =========================================== */

    $_SESSION = [];

    /* ===========================================
       REMOVE SESSION COOKIE
    =========================================== */

    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    /* ===========================================
       DESTROY SESSION
    =========================================== */

    session_destroy();

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(true, "Logged out successfully.");

} catch (Throwable $e) {

    apiResponse(false, "Unable to logout.", null, 500, [
        "error" => $e->getMessage()
    ]);
}