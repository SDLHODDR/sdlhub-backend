<?php
/*
 * POST { action, ... } candidate authentication (legacy careers/login.php):
 *   login     { username (email or mobile), password }
 *   register  { name, email, mobile, country, password }  -> logged in, OTPs sent
 *   verify    { email_otp, mobile_otp }                    -> legacy verifyEmailMobOTP
 *   resend    {}                                           -> new OTPs for the logged-in candidate
 *   forgot    { mobile }                                   -> legacy ForgotPass: a new password is sent
 *   logout    {}
 */
require_once __DIR__ . '/careersCommon.php';

/** Legacy texts (registration template 1007765778977790587). */
function careersRegistrationMessages($otp)
{
    return [
        'sms' => 'Your OTP for mobile number confirmation at sdlindia.com is ' . $otp
            . '. OTP is valid for 30 min. Thank you for registering on sdlindia.com. -teamSDL',
        'mail' => 'Your OTP for email confirmation at https://hrms.sdlindia.com/careers is ' . $otp
            . '. OTP is valid for 30 min. Thank you for registering on sdlindia.com. -TeamSDL',
    ];
}

/** Store new OTPs (mobile = USROTP, email = EMAILVERIFYCODE) and remember when they were issued. */
function careersIssueOtps($candidateId)
{
    $mobileOtp = careersNewOtp();
    $emailOtp = careersNewOtp();
    careersExecute("UPDATE HR_CANDIDATE_INFO SET USROTP = :mobile_otp, EMAILVERIFYCODE = :email_otp WHERE ID = :id",
        [':mobile_otp' => $mobileOtp, ':email_otp' => $emailOtp, ':id' => (string)$candidateId], 'Could not create the OTP.');
    $_SESSION['careersOtp'] = ['issuedAt' => time(), 'attempts' => 0, 'candidateId' => (int)$candidateId];
    return [$mobileOtp, $emailOtp];
}

/** After commit: send the OTPs; returns a message suffix. */
function careersOtpSender(array $candidate, $mobileOtp, $emailOtp)
{
    return static function () use ($candidate, $mobileOtp, $emailOtp) {
        $sentMail = careersQueueMail($candidate['CND_EMAIL'], 'Welcome To SDLINDIA!',
            careersRegistrationMessages($emailOtp)['mail'], 'Careers OTP');
        $sentSms = true;
        if (careersIsIndia($candidate)) {
            $sentSms = careersSendSms($candidate['CND_MOBILE'], careersRegistrationMessages($mobileOtp)['sms'],
                CAREERS_SMS_TEMPLATE_REGISTER);
        }
        if (careersOtpDebug()) {
            $GLOBALS['careersDebugOtp'] = ['email' => $emailOtp, 'mobile' => $mobileOtp];
        }
        return ($sentMail && $sentSms) ? '' : ' Some OTPs could not be sent; please use Resend OTP.';
    };
}

$input = careersInput();
$action = strtolower(trim((string)($input['action'] ?? '')));

careersRunAction(static function () use ($input, $action) {
    if ($action === 'login') {
        if (($_SESSION['careersLoginLock'] ?? 0) > time()) {
            throw new CareersException('Too many failed attempts. Please try again in a few minutes.', 429);
        }
        $username = strtolower(trim((string)($input['username'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new CareersException('Please enter your email / mobile number and password.', 422);
        }
        // Legacy: email (any case) or the last 10 digits of the mobile number.
        $candidate = careersRow("SELECT * FROM (
                SELECT ID, CND_NAME, CND_EMAIL, CND_MOBILE, CND_COUNTRY, CND_PASS, ISACTIVE, STATUS,
                    ISEMAILVERIFIED, ISOTPVERIFIED
                FROM HR_CANDIDATE_INFO
                WHERE LOWER(CND_EMAIL) = :username OR SUBSTR(CND_MOBILE, -10) = :username
                ORDER BY ID DESC
            ) WHERE ROWNUM = 1", [':username' => $username]);
        $valid = !empty($candidate['ID'])
            && hash_equals((string)$candidate['CND_PASS'], careersEncodePassword($password));
        if (!$valid) {
            $failures = (int)($_SESSION['careersLoginFailures'] ?? 0) + 1;
            $_SESSION['careersLoginFailures'] = $failures;
            if ($failures >= CAREERS_LOGIN_MAX_FAILURES) {
                $_SESSION['careersLoginFailures'] = 0;
                $_SESSION['careersLoginLock'] = time() + CAREERS_LOGIN_LOCK_SECONDS;
            }
            throw new CareersException('Invalid email / mobile number or password.', 401);
        }
        if ((string)($candidate['ISACTIVE'] ?? '') === '0') {
            throw new CareersException('Your account is not active. Please contact HR.', 403);
        }
        unset($_SESSION['careersLoginFailures'], $_SESSION['careersLoginLock']);
        careersLogin($candidate);
        return ['Logged in successfully.', [
            'candidate' => careersSessionCandidate($candidate),
            'csrfToken' => $_SESSION['careersCsrf'],
        ]];
    }

    if ($action === 'register') {
        $name = careersRequired($input['name'] ?? '', 'Full name', 300);
        if (!preg_match("/^[\\p{L} .'-]+$/u", $name)) {
            throw new CareersException('Full name can contain letters, spaces, dots and hyphens only.', 422);
        }
        $email = careersValidEmail($input['email'] ?? '');
        $mobile = careersValidMobile($input['mobile'] ?? '');
        $country = careersRequired($input['country'] ?? '', 'Country', 30);
        $countryRow = careersRow("SELECT DESCR FROM EPPLIVE.BCS_COUNTRY WHERE DESCR = :country", [':country' => $country]);
        if (empty($countryRow['DESCR'])) {
            throw new CareersException('Please select your country.', 422);
        }
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 50) {
            throw new CareersException('Password must be 8 to 50 characters long.', 422);
        }

        $emailTaken = careersRow("SELECT ID FROM HR_CANDIDATE_INFO WHERE LOWER(CND_EMAIL) = :email AND ROWNUM = 1", [':email' => $email]);
        $mobileTaken = careersRow("SELECT ID FROM HR_CANDIDATE_INFO WHERE SUBSTR(CND_MOBILE, -10) = :mobile AND ROWNUM = 1",
            [':mobile' => substr($mobile, -10)]);
        if (!empty($emailTaken['ID']) && !empty($mobileTaken['ID'])) {
            throw new CareersException('This email address and mobile number are already registered. Please log in.', 409);
        }
        if (!empty($emailTaken['ID'])) {
            throw new CareersException('This email address is already registered. Please log in.', 409);
        }
        if (!empty($mobileTaken['ID'])) {
            throw new CareersException('This mobile number is already registered. Please log in.', 409);
        }

        // Legacy name split: first word, middle words, last word.
        $parts = preg_split('/\s+/', $name);
        $first = $parts[0];
        $last = count($parts) > 1 ? end($parts) : '';
        $middle = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '';

        $id = careersInsert("INSERT INTO HR_CANDIDATE_INFO (CND_NAME, CND_FNAME, CND_MNAME, CND_LNAME, CND_PASS, CND_COUNTRY,
                STATUS, CND_MOBILE, CND_EMAIL, CHG_ON, CHG_BY)
            VALUES (:name, :first, :middle, :last, :pass, :country, 'N', :mobile, :email, SYSDATE, 'ONLINE')", [
            ':name' => $name, ':first' => substr($first, 0, 100), ':middle' => substr($middle, 0, 100),
            ':last' => substr($last, 0, 100), ':pass' => careersEncodePassword($password), ':country' => $country,
            ':mobile' => $mobile, ':email' => $email,
        ], 'Could not create your account.');
        if ($id === '') throw new RuntimeException('Could not create your account.');

        $candidate = careersRow("SELECT ID, CND_NAME, CND_EMAIL, CND_MOBILE, CND_COUNTRY, ISEMAILVERIFIED, ISOTPVERIFIED
            FROM HR_CANDIDATE_INFO WHERE ID = :id", [':id' => $id]);
        [$mobileOtp, $emailOtp] = careersIssueOtps($id);
        careersLogin($candidate);
        $_SESSION['careersOtp']['candidateId'] = (int)$id;
        return ['Account created. Please enter the OTPs sent to you.', [
            'candidate' => careersSessionCandidate($candidate),
            'csrfToken' => $_SESSION['careersCsrf'],
        ], careersOtpSender($candidate, $mobileOtp, $emailOtp)];
    }

    if ($action === 'verify') {
        $candidate = careersCandidate();
        if (careersIsVerified($candidate)) {
            return ['Your account is already verified.', ['candidate' => careersSessionCandidate($candidate)]];
        }
        $otp = $_SESSION['careersOtp'] ?? [];
        if ((int)($otp['candidateId'] ?? 0) !== (int)$candidate['ID'] || time() - (int)($otp['issuedAt'] ?? 0) > CAREERS_OTP_TTL_SECONDS) {
            throw new CareersException('Your OTP has expired. Please click Resend OTP.', 410);
        }
        if ((int)$otp['attempts'] >= CAREERS_OTP_MAX_ATTEMPTS) {
            throw new CareersException('Too many wrong attempts. Please click Resend OTP.', 429);
        }
        $_SESSION['careersOtp']['attempts'] = (int)$otp['attempts'] + 1;

        $stored = careersRow("SELECT USROTP, EMAILVERIFYCODE FROM HR_CANDIDATE_INFO WHERE ID = :id", [':id' => (string)$candidate['ID']]);
        $emailOk = hash_equals((string)($stored['EMAILVERIFYCODE'] ?? ''), trim((string)($input['email_otp'] ?? '')));
        $mobileOk = !careersIsIndia($candidate)
            || hash_equals((string)($stored['USROTP'] ?? ''), trim((string)($input['mobile_otp'] ?? '')));
        if (!$emailOk || !$mobileOk) {
            throw new CareersException('Invalid OTP. Please check and try again.', 422);
        }
        careersExecute("UPDATE HR_CANDIDATE_INFO SET ISEMAILVERIFIED = 1, ISOTPVERIFIED = 1 WHERE ID = :id",
            [':id' => (string)$candidate['ID']], 'Could not verify your account.');
        unset($_SESSION['careersOtp']);
        $candidate['ISEMAILVERIFIED'] = '1';
        $candidate['ISOTPVERIFIED'] = '1';
        return ['Your account has been verified.', ['candidate' => careersSessionCandidate($candidate)]];
    }

    if ($action === 'resend') {
        $candidate = careersCandidate();
        if (careersIsVerified($candidate)) {
            throw new CareersException('Your account is already verified.', 409);
        }
        $issuedAt = (int)($_SESSION['careersOtp']['issuedAt'] ?? 0);
        if ($issuedAt > 0 && time() - $issuedAt < CAREERS_OTP_RESEND_SECONDS) {
            throw new CareersException('Please wait a minute before requesting a new OTP.', 429);
        }
        [$mobileOtp, $emailOtp] = careersIssueOtps($candidate['ID']);
        return ['A new OTP has been sent.', null, careersOtpSender($candidate, $mobileOtp, $emailOtp)];
    }

    if ($action === 'forgot') {
        $mobile = careersValidMobile($input['mobile'] ?? '');
        $lastRequest = (int)($_SESSION['careersForgotAt'] ?? 0);
        if ($lastRequest > 0 && time() - $lastRequest < CAREERS_OTP_RESEND_SECONDS) {
            throw new CareersException('Please wait a minute before trying again.', 429);
        }
        $_SESSION['careersForgotAt'] = time();
        $generic = 'If this mobile number is registered, a new password has been sent to it and to the registered email.';
        $candidate = careersRow("SELECT * FROM (
                SELECT ID, CND_EMAIL, CND_MOBILE, CND_COUNTRY FROM HR_CANDIDATE_INFO
                WHERE SUBSTR(CND_MOBILE, -10) = :mobile ORDER BY ID DESC
            ) WHERE ROWNUM = 1", [':mobile' => substr($mobile, -10)]);
        if (empty($candidate['ID'])) {
            return [$generic, null];
        }
        // Legacy ForgotPass: the new password is a 6 digit code sent by SMS and email.
        $newPassword = careersNewOtp();
        careersExecute("UPDATE HR_CANDIDATE_INFO SET CND_PASS = :pass, USROTP = :otp, EMAILVERIFYCODE = :otp WHERE ID = :id",
            [':pass' => careersEncodePassword($newPassword), ':otp' => $newPassword, ':id' => (string)$candidate['ID']],
            'Could not reset your password.');
        return [$generic, null, static function () use ($candidate, $newPassword) {
            careersQueueMail($candidate['CND_EMAIL'], 'Welcome To SDLINDIA!',
                'Your login password is: ' . $newPassword . '. You can now log in using your new password. Do not share this code. TeamSDL',
                'Careers password');
            if (careersIsIndia($candidate)) {
                careersSendSms($candidate['CND_MOBILE'],
                    'Team SDL: Your login password is: ' . $newPassword . '. You can now log in using your new password. Do not share this code.',
                    CAREERS_SMS_TEMPLATE_PASSWORD);
            }
            if (careersOtpDebug()) {
                $GLOBALS['careersDebugOtp'] = ['password' => $newPassword];
            }
            return '';
        }];
    }

    if ($action === 'logout') {
        unset($_SESSION['careers'], $_SESSION['careersOtp']);
        session_regenerate_id(true);
        $_SESSION['careersCsrf'] = bin2hex(random_bytes(32));
        return ['Logged out.', ['csrfToken' => $_SESSION['careersCsrf']]];
    }

    throw new CareersException('Invalid action.', 422);
}, 'careers/auth.php');
