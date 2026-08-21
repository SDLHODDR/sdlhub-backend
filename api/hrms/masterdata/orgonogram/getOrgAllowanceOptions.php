<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    $locId = $data['LOC_ID'] ?? null;
    $locId = 853;

    if (!$locId ) {
        apiResponse(false, "Locaiton Id is required", null, 500);
        exit;
    }

    $getAWLOptData = multiRec("SELECT 
    hjd.allow_id,
    ha.allow_desc || ' - '|| hjd.allow_amount || ' Rs. - ' || hjd.add_info AS DESCR
    FROM hr_jd_allowances hjd 
    INNER JOIN hr_allowances ha ON hjd.allow_id = ha.allow_id
    WHERE jd_id IN (
        SELECT jd_id FROM hr_organogram WHERE id IN
            (
                SELECT org_id FROM hr_organogram_loc WHERE id='" . $locId . "')
                AND ha.allow_id  NOT IN (
                    SELECT allow_id FROM hr_org_loc_allowances WHERE
                    org_loc_id='".$locId."' AND sysdate BETWEEN effec_from AND NVL(effec_to ,'01-Mar-3000')))");

    
    
    if ( empty($getAWLOptData) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    
    $results = [];
    foreach ($getAWLOptData as $awlDt) {
        $results[] = [
            "ALLOW_ID" => (int)$awlDt['ALLOW_ID'],
            "ALLOW_DESC" => $awlDt['DESCR']
        ];
    }
    apiResponse(true, "Organogram Appraisal data fetched successfully.", $results);
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