<?php

require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/utils.php";

header("Content-Type: application/json");

try {

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

    apiResponse(false, "Unable to logout.", null, 500,
        [
            "error" => $e->getMessage()
        ]
    );
}