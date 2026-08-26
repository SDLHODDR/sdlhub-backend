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
require_once __DIR__ . "/../../../config/emp_func.php";
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
    //$orgId = $data['ID'] ?? null;
    $locId = $data['LOC_ID'] ?? null;

    if (!$locId ) {
        apiResponse(false, "Location Id is required", null, 500);
        exit;
    }

    $getData = multiRec("SELECT 
        ID, 
        get_org_loc_emp_code(PARENT_LOCID , EFFEC_FROM)REP, 
        GET_ORG_NAME(PARENT_ORGID)||' - ' || GET_ORG_LOC_NAME(PARENT_LOCID)ORGNM,
        ddmonyyyy(a.EFFEC_FROM)EFFEC_FROM,
        ddmonyyyy(a.EFFEC_TO)EFFEC_TO,
        PARENT_ORGID,
        PARENT_LOCID 
		FROM HR_ORG_LOC_PARENT a where ORG_LOC_ID='" . $locId . "' order by EFFEC_TO desc");
    
    if ( empty($getData) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    $results = [];
    foreach ($getData as $repDt) {
        $result[] = [
            "ID" => $repDt['ID'],
            "REP" => $repDt['REP'],
            "ORGNM" => $repDt['ORGNM'],
            "EFFEC_FROM" => $repDt['EFFEC_FROM'],
            "EFFEC_TO" => $repDt['EFFEC_TO'],
            "PARENT_ORGID" => $repDt['PARENT_ORGID'],
            "PARENT_LOCID" => $repDt['PARENT_LOCID'],
        ];
    }

    foreach ($result as $repDtRslt) {
        $name = singRec("SELECT HO.EMP_CODE FROM HR_EMPLOYEE_INFO HO
                                                                    INNER JOIN HR_EMP_OFFICE_DET HD ON HD.EMP_CODE = HO.EMP_CODE
                                                                    WHERE HD.ORG_ID ='".$repDtRslt['PARENT_ORGID']."' 
                                                                    AND HD.ORG_LOC_ID ='".$repDtRslt['PARENT_LOCID']."' AND HO.STATUS='A'");
        

        $finalResult[] = [
            "ID" => $repDtRslt['ID'],
            "REP" => $rerepDtRsltpDt['REP'],
            "ORGNM" => $repDtRslt['ORGNM'],
            "EFFEC_FROM" => $repDtRslt['EFFEC_FROM'],
            "EFFEC_TO" => $repDtRslt['EFFEC_TO'],
            "PARENT_ORGID" => $repDtRslt['PARENT_ORGID'],
            "PARENT_LOCID" => $repDtRslt['PARENT_LOCID'],
            "EMP_NAME" => getEmpInfoByCodeHR($name['EMP_CODE']) 
        ];
    }

    apiResponse(true, "Organogram Appraisal data fetched successfully.", $finalResult);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getOrganogramData.php"
    );

    apiResponse(false, "Unable to load organogram." .  $e->getMessage(), null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}