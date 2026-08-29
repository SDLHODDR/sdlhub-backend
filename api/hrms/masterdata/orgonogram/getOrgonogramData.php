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
    $compIdsString = $data["COMP_ID"];
    $divIdsString = $data["DIVISION_ID"];
    $deptCodesString = $data["DEPT_ID"];

    $organograms = multiRec(
        "SELECT 
            ID, 
            GET_SHCOMP_NAME(COMPANY)|| ' - ' || GET_DIVISION_NAME(DIVSN_ID)|| ' - ' || GET_DEPT_NAME(DEPT_ID)|| ' - ' || GET_DESIGN_NAME(DESI_ID) as ORGNGM_OPTIONS,
            FINENT, 
            COMPANY, 
            LABEL, 
            DEPT_ID, 
            DESI_ID, 
            DIVSN_ID, 
            OLVL_ID, 
            POSI_COUNT, 
            FILL_COUNT,
            PARENT_ORGID, 
            JD_ID, 
            EMP_LEVEL, 
            STATUS, 
            CHG_ON, 
            CHG_BY, 
            NOTICE_DAYS
        FROM HR_ORGANOGRAM 
        WHERE 
        COMPANY IN ($compIdsString)
        AND DIVSN_ID in ($divIdsString)
        AND DEPT_ID in ($deptCodesString)
        ORDER BY ID DESC"
    );
    
    if ( empty($organograms) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    
    $results = [];
    foreach ($organograms as $org) {
         $arrOPT = explode(" - ", $org["ORGNGM_OPTIONS"]);
        $results[] = [
            "ID" => (int)$org['ID'],
            "FINENT" => $org['FINENT'],
            "COMPANY_TXT" => isset($arrOPT[0]) ? $arrOPT[0] : '', // SDL
            "DIVSN_TXT"   => isset($arrOPT[1]) ? $arrOPT[1] : '', // SDLPN
            "DEPT_TXT"    => isset($arrOPT[2]) ? $arrOPT[2] : '', // Distribution
            "DESI_TXT"    => isset($arrOPT[3]) ? $arrOPT[3] : '', // Sr. Distribution Executive
            "COMPANY" => $org["COMPANY"],
            "LABEL" => $org['LABEL'],
            "DEPT_ID" => $org["DEPT_ID"],
            "DESI_ID" => $org["DESI_ID"],
            "DIVSN_ID" => $org["DIVSN_ID"],
            "OLVL_ID" => $org['OLVL_ID'],
            "POSI_COUNT" => $org['POSI_COUNT'],
            "FILL_COUNT" => $org['FILL_COUNT'],
            "PARENT_ORGID" => $org['PARENT_ORGID'],
            "JD_ID" => $org['JD_ID'],
            "EMP_LEVEL" => $org['EMP_LEVEL'],
            "STATUS" => $org['STATUS'],
            "STATUSTXT" => $org['STATUS'],
            "CHG_ON" => $org['CHG_ON'],
            "CHG_BY" => $org['CHG_BY'],
            "NOTICE_DAYS" => $org['NOTICE_DAYS'],
            "OPTIONS" => $org["ORGNGM_OPTIONS"],
        ];
    }
    
    apiResponse(true, "Organograms fetched successfully.", $results);
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
