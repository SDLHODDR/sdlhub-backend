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
