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
    $orgId = $data['ID'] ?? null;

    if (!$orgId ) {
        apiResponse(false, "Organogram Id is required", null, 500);
        exit;
    }

    $getAppLevelsData = multiRec("SELECT
        A.APPR_LEVEL,
        ddmonyyyy(a.effec_from)EFFEC_FROM,
        ddmonyyyy(a.effec_to)EFFEC_TO, 
        GET_ORG_NAME(a.appr_ORGID) AS NAME
        FROM HR_ORG_APPR_LEVELS a 
        WHERE a.ORG_ID='" . $orgId . "' ORDER BY APPR_LEVEL");
    
    if ( empty($getAppLevelsData) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    $results = [];
    foreach ($getAppLevelsData as $apprLvl) {
        $result[] = [
            "APPR_LEVEL" => $apprLvl['APPR_LEVEL'],
            "EFFEC_FROM" => $apprLvl['EFFEC_FROM'],
            "EFFEC_TO" => $apprLvl['EFFEC_TO'],
            "NAME" => $apprLvl['NAME']
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