<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

define('CURRENT_PORTAL', 'hrms');

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}

try {
    startQry();
     foreach($data['ALLOW_ID'] as $al){ 
        executeQry("INSERT INTO HR_ORG_LOC_ALLOWANCES(ID, ORG_ID, ORG_LOC_ID, ALLOW_ID, EFFEC_FROM, STATUS)
        values(
            NULL,
            '" . trim($data['ORG_ID']) . "',
            '" . trim($data['ORG_LOC_ID']) . "',
            '" . trim($al) . "',
            to_date('".$data['EFFEC_FROM']."','dd-mm-yyyy'),'N')");
    } 
    // exit;
    endQry("Saved Successfully");

    apiResponse(true, "Allowances saved successfully.");

} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "saveOrgAllowance.php"
    );
    apiResponse(false, "Unable to load Allowances.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}