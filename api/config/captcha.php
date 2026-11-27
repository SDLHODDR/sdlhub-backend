<?php
/*
 * Login captcha, kept in the PHP session (no external service).
 * The code is single use: it is cleared on every check, right or wrong,
 * so each login attempt needs a new image.
 */

const CAPTCHA_LENGTH = 5;
const CAPTCHA_TTL = 300; // seconds
// No 0/O, 1/I/L, 5/S, 8/B: they are easy to misread.
const CAPTCHA_CHARS = '234679ACDEFGHJKMNPQRTUVWXYZ';

/** Creates a new code in the session and returns it as a PNG data URI. */
function captchaCreateImage(): string
{
    $code = '';
    $max = strlen(CAPTCHA_CHARS) - 1;
    for ($i = 0; $i < CAPTCHA_LENGTH; $i++) {
        $code .= CAPTCHA_CHARS[random_int(0, $max)];
    }

    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_expires'] = time() + CAPTCHA_TTL;

    // Drawn small with the built-in font, then enlarged: no font file needed on the server.
    $smallW = 80;
    $smallH = 24;
    $small = imagecreatetruecolor($smallW, $smallH);
    imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));

    $x = 6;
    for ($i = 0; $i < CAPTCHA_LENGTH; $i++) {
        $color = imagecolorallocate($small, random_int(10, 90), random_int(10, 90), random_int(60, 140));
        imagestring($small, 5, $x + random_int(-1, 1), random_int(2, 7), $code[$i], $color);
        $x += 14;
    }

    $w = 160;
    $h = 48;
    $img = imagecreatetruecolor($w, $h);
    imagecopyresampled($img, $small, 0, 0, 0, 0, $w, $h, $smallW, $smallH);
    imagedestroy($small);

    // Noise lines and dots.
    for ($i = 0; $i < 6; $i++) {
        $c = imagecolorallocate($img, random_int(120, 200), random_int(120, 200), random_int(120, 200));
        imagesetthickness($img, random_int(1, 2));
        imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }
    for ($i = 0; $i < 250; $i++) {
        $c = imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220));
        imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);

    return 'data:image/png;base64,' . base64_encode($png);
}

/**
 * Checks the entered code against the session and clears it.
 * Returns "" when correct, otherwise the message to show.
 */
function captchaVerify(string $input): string
{
    $expected = $_SESSION['captcha_code'] ?? '';
    $expires = (int)($_SESSION['captcha_expires'] ?? 0);
    unset($_SESSION['captcha_code'], $_SESSION['captcha_expires']);

    $input = strtoupper(trim($input));

    if ($input === '') {
        return 'Please enter the captcha.';
    }
    if ($expected === '' || time() > $expires) {
        return 'The captcha has expired. Please enter the new one.';
    }
    if (!hash_equals($expected, $input)) {
        return 'The captcha is incorrect. Please try again.';
    }
    return '';
}
