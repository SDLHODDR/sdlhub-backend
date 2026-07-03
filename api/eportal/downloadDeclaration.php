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

require_once __DIR__ . "/itr/helpers/generateDeclarationPdf.php";
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

$category      = "declaration";
$type          = trim($_POST["type"] ?? "");
$financialYear = trim($_POST["financial_year"] ?? "");
$empCode       = trim($_POST["selected_empcode"] ?? "");

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

/*
=====================================================
ALL EMPLOYEES
BACKGROUND JOB
=====================================================
*/

if ($type === "all") {
	
    $jobId = createDeclarationDownloadJob(
		$con,
		$financialYear,
		$_SESSION['emp_code']
	);

	startDeclarationWorker($jobId,$financialYear);

	responseSuccess(
		"Your request has been submitted successfully. An email will be sent once the ZIP is ready.",
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

processDeclarationDownload(
    $con,
    $zip,
    $financialYear,
    $type,
    $empCode,
    $loggedEmpCode
);

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
    "Declaration downloaded successfully.",
    [
        "action" => "download",
        "download_url" => $published["download_url"]
    ]
);
