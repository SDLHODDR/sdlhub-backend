<?php
// IMPORTANT: this must run BEFORE session_start()

/*if (session_status() === PHP_SESSION_NONE) {

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // keep empty for localhost
        'secure' => false, // true only on HTTPS
        'httponly' => true,
        'samesite' => 'Lax' //  KEY FIX
    ]);

    session_start();
}*/

// IMPORTANT: this must run BEFORE session_start()

if (session_status() === PHP_SESSION_NONE) {

    // Compatible across old + new PHP versions
    // Also supports SameSite for older PHP versions using path workaround

    if (PHP_VERSION_ID >= 70300) {
        // PHP 7.3+
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,   // true only if using HTTPS
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        // PHP < 7.3
        session_set_cookie_params(
            0,
            '/; samesite=Lax',
            '',
            false,
            true
        );
    }
    session_start();
}

/*
 * Inactivity logout. Only session_keepalive.php, called when the user actually
 * types, clicks or scrolls, moves LAST_ACTIVITY forward. Ordinary requests do
 * not, so a page that refreshes itself cannot keep an idle session alive.
 * Outside the block above: some endpoints start the session before this file.
 */
if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 900); // 15 minutes, in seconds
}

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['emp_code'])) {
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    } elseif (time() - $_SESSION['last_activity'] >= SESSION_IDLE_TIMEOUT) {
        // Continue with a new empty session, so endpoints see "not logged in".
        $_SESSION = [];
        session_destroy();
        session_id(session_create_id());
        session_start();
    }
}
