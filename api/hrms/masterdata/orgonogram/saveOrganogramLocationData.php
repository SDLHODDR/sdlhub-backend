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

    $orgres = singRec("SELECT * FROM HR_ORGANOGRAM WHERE ID = '".$data['ID']."'");
    if($orgres['EMP_LEVEL']=='15'){

        $geo_det = singRec("SELECT
			DIVSN_ID,
			DIVSN_DESC AS GEO_DESC,
			'Office Staff ' as GEO_LABEL 
			FROM HR_DIVISIONS WHERE DIVSN_ID = '" . $data['GEO_ID'] . "' ");

    } else {

        $geo_det = singRec("SELECT
			GEO_ID,
			GEO_DESC,
			GEO_LABEL 
			FROM HR_SFM_NEW_GEO_MAPPING
			WHERE GEO_ID = '" . $data['GEO_ID'] . "' 
            AND DIVSN_ID = '".$data['DIVSN_ID']."' 
            AND '".$data['EFFEC_FROM']."'
			BETWEEN EFFEC_FROM AND EFFEC_TO");
    }

    if($data['ID'] == ''){
        $newId = executeQry("INSERT INTO HR_ORGANOGRAM_LOC
			(ID, ORG_ID, GEO_ID, GEO_DESC, LOC_LABEL, EFFEC_FROM, EFFEC_TO, EMP_LEVEL, STATUS)
			VALUES('',
			'" . $data['ORGANOGRAM_ID'] . "',
			'" . trim($data['GEO_ID']) . "',
			'" . trim($geo_det['GEO_DESC']) . "',
			'" . trim($geo_det['GEO_LABEL']) . "',
			'" . trim($data['EFFEC_FROM']) . "',
			'" . trim($data['EFFEC_TO']) . "',
			'" . trim($data['EMP_LEVEL']) . "',
			'A'				
			)RETURNING ID INTO:newId ", 'newId');

        if(!$newId)
        {
            apiResponse(false, "Organogram Location insert/update failed", null, 500);
            exit;
        }
        if ($data['EFFEC_TO'] != '' && $newId) {
			executeQry("UPDATE HR_ORGANOGRAM_LOC 
				SET STATUS = 'C' 
				WHERE ORG_ID='" . $data['ORGANOGRAM_ID'] . "' AND ID = '" . $newId . "'");
		}
    } else {
        executeQry("UPDATE HR_ORGANOGRAM_LOC SET
			GEO_ID = '" . $data['GEO_ID'] . "',
			GEO_DESC= '" . trim($geo_det['GEO_DESC']) . "',
			LOC_LABEL= '" . trim($geo_det['GEO_LABEL']) . "',
			EFFEC_FROM='" . trim($data['EFFEC_FROM']) . "',
			EFFEC_TO='" . trim($data['EFFEC_TO']) . "',
			EMP_LEVEL='" . trim($data['EMP_LEVEL']) . "',
			STATUS = 'A'
			where ID = '" . $data['ID'] . "'"); //LOC_ID_hidden
        if ($data['EFFEC_TO'] != '') {
            executeQry("UPDATE HR_ORGANOGRAM_LOC SET
                STATUS = 'C'
                WHERE ID = '" . $data['ID'] . "'");
        }
    }
    if ($data['EFFEC_TO'] != '') {
        executeQry("UPDATE HR_ORGANOGRAM_LOC SET 
            STATUS = 'C'
            WHERE ORG_ID='" . $_REQUEST['ORGANOGRAM_ID'] . "' AND ID = '" . $newId . "'");
    }
    endQry('Updated Sucessffully');
    
    apiResponse(true, "Organogram Location data Insert/Update successfully.", $newId);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "saveOrganogramLocationData.php"
    );
    apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}