<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);
 
require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/path.php";

$con = db_eportal();  

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";
require_once __DIR__ . "/helpers/downloadHelper.php";

header("Content-Type: application/json");

/*
=====================================================
READ JSON INPUT
=====================================================
*/

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (json_last_error() === JSON_ERROR_NONE && is_array($input)) {
    $_POST = $input;
}

/*
=====================================================
METHOD CHECK
=====================================================
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responseError("Invalid request method");
}

/*
=====================================================
SESSION VALIDATION
=====================================================
*/

if (!isset($_SESSION["emp_code"])) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

/*
=====================================================
REQUEST
=====================================================
*/

$category = "documents";
$type = trim($_POST["type"] ?? "");
$financialYear = trim($_POST["financial_year"] ?? "");
$empCode = trim($_POST["selected_empcode"] ?? "");
$loggedEmpCode = $_SESSION["emp_code"];

/*
=====================================================
VALIDATION
=====================================================
*/

validateDownloadRequest(
    $category,
    $type,
    $financialYear,
    $empCode
);

if (!is_dir(INCOME_TAX_PATH)) {
    responseError("Document directory not found.");
}

/*
=====================================================
ALL EMPLOYEES - BACKGROUND JOB
=====================================================
*/

if ($type === "all") {

    $jobId = createDocumentDownloadJob(
        $con,
        $financialYear,
        $_SESSION['emp_code']
    );
    
    startDocumentWorker($jobId, $financialYear);
    
    responseSuccess(
        "Your request has been submitted successfully. A download link will be emailed once processing is completed.",
        [
            "action" => "background"
        ]
    );
}

/*
=====================================================
SINGLE EMPLOYEE
=====================================================
*/

$zipData = createZipFile(
    $category,
    $type,
    $financialYear,
    $empCode
);

$zip = $zipData["zip"];

processDocumentDownload(
    $zip,
    $financialYear,
    $type,
    $empCode
);

$zip->close();

$published = publishZip(
    $zipData["file_path"],
    $zipData["file_name"]
);

logDownload(
    $con,
    $loggedEmpCode,
    $category,
    $type,
    $empCode,
    $financialYear,
    $zipData["file_name"],
    $published["file_path"]
);

responseSuccess(
    "Download is ready.",
    [
        "action" => "download",
        "download_url" => $published["download_url"]
    ]
);
