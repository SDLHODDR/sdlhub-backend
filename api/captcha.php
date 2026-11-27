<?php
/*
 * New login captcha image.
 * Response: { status, message, data: { image: "data:image/png;base64,..." } }
 */

require_once 'cors.php';
require_once 'config/session.php';
require_once 'config/utils.php';
require_once 'config/captcha.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $image = captchaCreateImage();
    session_write_close();
    apiResponse(true, 'Captcha created.', ['image' => $image]);
} catch (Throwable $e) {
    writeErrorLog('captcha.php: ' . $e->getMessage());
    apiResponse(false, 'Unable to load the captcha.', null, 500);
}
