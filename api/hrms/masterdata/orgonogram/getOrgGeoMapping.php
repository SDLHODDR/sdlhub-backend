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
    
    $empLevel = $data['EMP_LEVEL'] ?? null;
    $divsnID = $data['DIVSN_ID'] ?? null;
    $effecFrom = $data['EFFEC_FROM'] ?? null;

    // $empLevel = '100';
    // $divsnID = '11';
    // $effecFrom = '01-APR-24';

    if (!$empLevel && !$divsnID && !$effecFrom && !$geoId ) {
        apiResponse(false, "Emp Level, Division Id Effective From and Geo Id is required", null, 500);
        exit;
    }

    $getOPtions = multiRec("SELECT 
        hgm.GEO_ID,
        hgm.geo_desc ||'('|| hgm.geo_label ||')' as Geo_Details
        FROM HR_SFM_NEW_GEO_MAPPING hgm
        INNER JOIN hr_divisions hd 
        ON hd.divsn_id = hgm.divsn_id
        INNER JOIN HR_SFM_NEW_EMP_LEVELS hel 
        ON hel.levl = hgm.geo_lvl
        WHERE hel.levl = '" . $empLevel . "'  
        AND hd.divsn_id= '" . $divsnID . "'
        AND to_date('".$effecFrom."') between hgm.effec_from
        AND nvl(effec_to,'01-Mar-3000')");
    
    if ( empty($getOPtions) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    $results = [];
    foreach ($getOPtions as $optGEO) {
        $result[] = [
            "GEO_ID" => $optGEO['GEO_ID'],
            "GEO_DETAILS" => $optGEO['GEO_DETAILS'],
        ];
    }
    apiResponse(true, "Organogram GEO data fetched successfully.", $result);
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