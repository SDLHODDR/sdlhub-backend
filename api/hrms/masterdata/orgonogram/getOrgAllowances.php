<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    $orgId = $data['ORG_ID'] ?? null;
    $locId = $data['LOC_ID'] ?? null;

    $locId = 853;
    $orgId = 178;
    if (!$orgId && $locId) {
        apiResponse(false, "Organogram Id and Location Id is required", null, 500);
        exit;
    }

    $getAllowanceData = multiRec("SELECT 
    ID,
    ALLOW_ID,
    GET_ALLOWDESC(allow_id)ALLOW_DESC,
    ddmonyyyy(EFFEC_FROM)EFFEC_FROM,
    ddmonyyyy(EFFEC_TO)EFFEC_TO
    FROM HR_ORG_LOC_ALLOWANCES
    WHERE ORG_LOC_ID='" . $locId . "'
    AND org_id = '".$orgId."' order by id DESC");
    
    if ( empty($getAllowanceData) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    $results = [];
    foreach ($getAllowanceData as $awlDt) {
        $result[] = [
            "ID" => $awlDt['ID'],
            "ALLOW_ID" => $awlDt['ALLOW_ID'],
            "ALLOW_DESC" => $awlDt['ALLOW_DESC'],
            "EFFEC_FROM" => $awlDt['EFFEC_FROM'],
            "EFFEC_TO" => $awlDt['EFFEC_TO']
        ];
    }
    apiResponse(true, "Organogram Appraisal data fetched successfully.", $result);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getOrganogramData.php"
    );
    apiResponse(false, $e->getMessage(), null, 500);
    //apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
?>