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

    $duplicheck = singRec("SELECT * FROM HR_ORG_LOC_PARENT WHERE to_date('".$data['EFFEC_FROM']."') BETWEEN EFFEC_FROM AND EFFEC_TO AND ORG_LOC_ID='".$data['PARENT_LOCID']."'");
    
    if(isset($duplicheck['ID']) && $duplicheck['ID']!=''){
        endQry("duplicate data!");
        apiResponse(false, "Record Already Exists!", null, 500);
        exit;
    } else {
        $parentorgid = singRec("SELECT ORG_ID FROM HR_ORGANOGRAM_LOC WHERE ID = '".$data['PARENT_LOCID']."'");
        
        if($data['repid'] != '') {
            executeQry("UPDATE HR_ORG_LOC_PARENT 
                SET PARENT_ORGID='". trim($parentorgid['ORG_ID']) ."', 
                PARENT_LOCID = '" . $data['PARENT_LOCID'] . "',
                EFFEC_TO = '" . $data['EFFEC_TO'] . "',
                EFFEC_FROM = '" . $data['EFFEC_FROM'] . "',
                STATUS = 'D' 
                WHERE ID = '".$data['repid']."'");

            $prevrecord = singRec("SELECT * FROM (
                SELECT * FROM HR_ORG_LOC_PARENT WHERE ORG_LOC_ID = '".$data['locid']."' 
                ORDER BY ID DESC) WHERE ROWNUM=1 AND ID!='".$data['repid']."'");
            
            if(isset($prevrecord['ID']) && $prevrecord['ID'] != ''){
				executeQry("UPDATE HR_ORG_LOC_PARENT SET EFFEC_TO='".date('d-M-Y', strtotime($data['EFFEC_FROM'] . ' -1 day'))."' WHERE ID='".$prevrecord['ID']."'");
			}
        } else {
            $prevrecord = singRec("SELECT * FROM (
                SELECT * FROM HR_ORG_LOC_PARENT WHERE ORG_LOC_ID = '".$data['locid']."' ORDER BY ID DESC) WHERE ROWNUM=1");
            

            if(isset($prevrecord['ID']) && $prevrecord['ID']!=''){
                executeQry("UPDATE HR_ORG_LOC_PARENT SET EFFEC_TO ='".date('d-M-Y', strtotime($data['EFFEC_FROM'] . ' -1 day'))."' WHERE ID='".$prevrecord['ID']."'");

            }

            $parentorgid = singRec("SELECT ORG_ID FROM HR_ORGANOGRAM_LOC WHERE ID = '".$data['PARENT_LOCID']."'");
            
            $newId = executeQry("INSERT INTO HR_ORG_LOC_PARENT(ID, ORG_ID, ORG_LOC_ID, PARENT_ORGID, PARENT_LOCID, EFFEC_FROM) VALUES('',
                '" . trim($data['orgid']) . "',
                '" . trim($data['locid']) . "',
                '" . trim($parentorgid['ORG_ID']) . "',
                '" . trim($data['PARENT_LOCID']) . "',
                '" . trim($data['EFFEC_FROM']) . "')RETURNING ID INTO:newId ", 'newId');
        }
    }
    executeQry("UPDATE HR_ORGANOGRAM_LOC SET 
    PARENT_ORGID = '".trim($parentorgid['ORG_ID'])."',
    PARENT_LOCID = '".$data['PARENT_LOCID']."' WHERE ID = '".$data['locid']."'");

	endQry("Saved Successfully");
    apiResponse(true, "Organogram Reportee data saved successfully.");
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "saveOrgLocReporting.php"
    );

    apiResponse(false, "Unable to load Reportee.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}