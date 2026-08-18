<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

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
    // print_r($data); 
    // exit;

    $orgNMId = $data['ID'] ?? null;

    if (!$orgNMId) {
        apiResponse(false, "Organogram Id is required", null, 500);
        exit;
    }

    $organogramLoc = multiRec("SELECT 
        DISTINCT hol.ID, get_emp_name(get_org_loc_emp_code(hol.ID, SYSDATE)) || ' - ' || get_org_loc_emp_code(hol.ID , SYSDATE) NM, hol.geo_desc ||' ('|| loc_label || ')' geodesc, hol.effec_from, hol.effec_to, hol.loc_label, hol.geo_id, get_org_loc_emp_code(hol.ID, SYSDATE) emp_code 
        FROM HR_ORGANOGRAM ho, HR_ORGANOGRAM_LOC hol, HR_SFM_NEW_EMP_LEVELS hel
        WHERE 
        ho.id = hol.org_id AND 
        ho.emp_level = hel.levl AND 
        ho.id  = '" . $orgNMId . "' 
        ORDER BY 1 DESC");
    
    if ( empty($organogramLoc) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    $results = [];
    // print_r($organogramLoc);
    // exit;
    foreach ($organogramLoc as $orgLoc) {
        $results[] = [
            "ID" => (int)$orgLoc['ID'],
            "NM" => $orgLoc['NM'],
            "GEODESC" => $orgLoc['GEODESC'],
            "EFFEC_FROM" => $orgLoc['EFFEC_FROM'],
            "EFFEC_TO" => $orgLoc['EFFEC_TO'],
            "LOC_LABEL" => $orgLoc['LOC_LABEL'],
            "GEO_ID" => $orgLoc['GEO_ID'],
            "EMP_CODE" => $orgLoc['EMP_CODE'],
        ];
    }
    
    apiResponse(true, "Organogram data fetched successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getOrganogramData.php"
    );

    apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}