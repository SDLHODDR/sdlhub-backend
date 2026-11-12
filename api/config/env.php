<?php

$_ENV["LOCAL_REACT_APP_URL"] = "http://localhost:5173";

$_ENV["APP_URL"] = "http://localhost/sdlhub/public/"; //-- need to remove

$_ENV["API_ROOT_PATH"] = "http://localhost/sdlhub-backend/api";

/*
|--------------------------------------------------------------------------
| Public Download Folder
|--------------------------------------------------------------------------
*/

$_ENV["PUBLIC_PATH"] = "/var/www/html/sdlhub-public";

$_ENV["PUBLIC_URL"] = "http://localhost/sdlhub-public";

$_ENV["PROFILES_URL"] = $_ENV["PUBLIC_URL"] . "/profiles"; //"http://localhost/sdlhub-public/profiles/";

/*
|--------------------------------------------------------------------------
| Documents Root
|--------------------------------------------------------------------------
*/

$_ENV["DOCUMENT_ROOT"] = "/mnt/documents";

/*
|--------------------------------------------------------------------------
| Careers (candidate portal)
|--------------------------------------------------------------------------
| SMS gateway used for candidate OTPs (cell24x7, DLT templates). Messages
| must match the registered templates word for word.
*/

$_ENV["SMS_GATEWAY_URL"] = "https://sms.cell24x7.com:1111/mspProducerM/sendSMS";
$_ENV["SMS_USER"] = "usersdl1";
$_ENV["SMS_PASSWORD"] = "usersdl1";
$_ENV["SMS_SENDER"] = "AyHelp";

// CVs and photos uploaded by candidates (outside the web root; www-data must be able to write).
$_ENV["CAREERS_UPLOAD_ROOT"] = $_ENV["DOCUMENT_ROOT"] . "/careers";

// Development only: true = OTPs are not sent by SMS / email but returned in the API response.
$_ENV["CAREERS_OTP_DEBUG"] = true;

