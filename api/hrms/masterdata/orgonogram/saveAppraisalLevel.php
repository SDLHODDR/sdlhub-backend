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
    // $parentLoc = $data['ORG_ID'];
    // $parentLoc = array_filter($parentLoc, function($value) {
	// 	return !is_null($value) && $value !== '';
	// });
    // $uniqueParentLoc = array_unique($parentLoc);

    // echo '<pre>';
    // print_r($data);
    // echo '<pre/>';
    // exit;
    
    $TId = singRec("SELECT COUNT(ID)CNT FROM HR_ORG_APPR_LEVELS WHERE effec_from  >=  '".$data['EFFEC_FROM']."' AND org_id = '".$data['ORG_ID']."'");
    
    if($TId['CNT'] != 0){
        apiResponse(false, "Record Already Exists!", null, 500);
        exit;
    } else {
        $effectiveDate = date('d-M-Y', strtotime($data['EFFEC_FROM'] .'-1 day'));

        executeQry("UPDATE HR_ORG_APPR_LEVELS 
        SET effec_to='".($effectiveDate)."', STATUS = 'D' 
        WHERE to_date('".$data['EFFEC_FROM']."')
        BETWEEN effec_from AND NVL(effec_to , '01-Mar-3000') AND org_id = '".$data['ORG_ID']."'");

        foreach($data['APPR_ORGID'] as $key => $valAPORG){
            $apprOrgId = singRec("SELECT ID FROM HR_ORGANOGRAM WHERE ID = '".$valAPORG."'"); 
            if ($apprOrgId['ID'] !== null) {
               $newId = executeQry("INSERT INTO HR_ORG_APPR_LEVELS(ID, ORG_ID, APPR_LEVEL, APPR_ORGID, EFFEC_FROM, STATUS, CHG_BY, CHG_ON) VALUES('',
               '" . $data['ORG_ID'] . "',
               '" . trim($data['APPR_LEVEL'][$key]) . "',
               '" . trim($apprOrgId['ID']) . "',
               '" . trim($data['EFFEC_FROM']) . "',
               'N', 
               '" . $empCode . "',
               SYSDATE)RETURNING ID INTO:newId ", 'newId');
            }
        }
        
        endQry("Saved Successsfully");
        apiResponse(true, "Organogram Appraisal data Inserted/Updated successfully.");
    }
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