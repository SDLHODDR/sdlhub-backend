<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    
    $empOrgCode = $data['EMP_CODE'] ?? null;
    $locID = $data['LOC_ID'] ?? null;
    $effecFrom = $data['EFFEC_FROM'] ?? null;

    // $empLevel = '100';
    // $divsnID = '11';
    // $effecFrom = '01-APR-24';

    if (!$empOrgCode && !$locID && !$effecFrom) {
        apiResponse(false, "Emp Code, Location Id Effective From is required", null, 500);
        exit;
    }

    $reporting1 = singRec("SELECT 
        ID,
        get_org_loc_emp_code(PARENT_LOCID , sysdate)REP,
        PARENT_ORGID,
        PARENT_LOCID
        FROM hr_org_loc_parent
        WHERE
            org_loc_id='".$locID."'
            AND sysdate between effec_from AND nvl(effec_to,'01-Mar-3000')");
    
    if ( empty($reporting1) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    $reporting2 = singRec("SELECT 
        ID,
        get_org_loc_emp_code(PARENT_LOCID , sysdate)REP,
        PARENT_ORGID,
        PARENT_LOCID
        FROM hr_org_loc_parent
        WHERE
            org_loc_id='".$locID."'
            AND to_date('".$effecFrom."') between effec_from AND nvl(effec_to, '01-Mar-3000')");
    
    if ( empty($reporting2) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    $orgId = !empty($reporting1['PARENT_ORGID']) ? $reporting1['PARENT_ORGID'] : $reporting2['PARENT_ORGID'];
    $locIdOrg = !empty($reporting1['PARENT_LOCID']) ? $reporting1['PARENT_LOCID'] : $reporting2['PARENT_LOCID'];

    $reportEmp = singRec("SELECT get_emp_mgr('".$empOrgCode."', SYSDATE)EMP FROM DUAL");
    $reportEmp1 = singRec("SELECT get_org_loc_mgr('". ($locID) ."',SYSDATE)EMP FROM DUAL");

    $desig = singRec("SELECT GET_ORGLOC_DESIG('". $locIdOrg ."')des FROM dual");

    $results = [];

    $REPORT_TO_DISPLAY = $orgId . ' - ' . $desig['DES'] . '-' . getEmpInfoByCodeHR($reportEmp['EMP'] ?$reportEmp['EMP'] : $reportEmp1['EMP']  ).' - '.($reportEmp['EMP'] ? $reportEmp['EMP']  : $reportEmp1['EMP'] );
    
    $result = [
            "ORGID_RPTMG" => $orgId,
            "REPORT_DES" => $desig['DES'] ?? $desig['des'],
            "REPORT_EMP" => $reportEmp['EMP'],
            "REPORT1_EMP" => $reportEmp1['EMP'],
            "REPORT_TO_DISPLAY" => $REPORT_TO_DISPLAY
    ];
    
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