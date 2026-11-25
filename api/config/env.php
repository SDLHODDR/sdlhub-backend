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
| HRMS Document Uploads
|--------------------------------------------------------------------------
| HRMS_DOC_ROOT: folder holding the legacy "input/..." upload folders
| (input/emp_docs, input/jd_docs, input/policy_doc, input/gatepass). The DB
| DOC_PATH columns store paths relative to it, e.g. "input/emp_docs/...".
| HRMS_LEGACY_DOC_URL: old HRMS server serving the same relative paths for
| documents uploaded before SDL Hub (also VITE_HRMS_DOC_BASE_URL in the frontend .env).
| Folder names live in config/path.php.
*/

$_ENV["HRMS_DOC_ROOT"] = $_ENV["DOCUMENT_ROOT"]; // /mnt/documents (was the sdlhub-backend folder)

$_ENV["HRMS_LEGACY_DOC_URL"] = "https://hrms.sdlindia.com/hradmin/";

/*
|--------------------------------------------------------------------------
| ePortal / EPP links (used in mails and payslip downloads)
|--------------------------------------------------------------------------
*/

$_ENV["EPORTAL_LEGACY_URL"] = "https://eportal.sdlindia.com/";

$_ENV["EPP_PAYSLIP_URL"] = "https://epp.sdlindia.com/reports/pdfReport/payslip.php";

/*
|--------------------------------------------------------------------------
| Mail skip list
|--------------------------------------------------------------------------
| Outdoor duty / ticket booking mails are not queued for these addresses.
| Comma separated; matched case-insensitively.
*/

$_ENV["MAIL_SKIP_EMAILS"] = "rap@sdlindia.com";

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

