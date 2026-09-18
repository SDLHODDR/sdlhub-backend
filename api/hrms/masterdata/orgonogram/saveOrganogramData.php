<?php

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
    // echo '<pre>';
    // print_r($data);
    // echo '</pre>';
    // exit;
    startQry();
    if(!empty($data)) {
        if(isset($data['mode']) || !empty($data['mode'])) {
            if($data['mode'] == "edit") {
                $OrgId = executeQry("UPDATE HR_ORGANOGRAM SET 
                    FINENT= '" . trim($data['FIN_ENTITY_ID']) . "',
                    COMPANY= '" . trim($data['COMPANY_ID']) . "',
                    DEPT_ID= '" . trim($data['DEPARTMENT_ID']) . "',
                    DESI_ID= '" . trim($data['DESIGNATION_ID']) . "',
                    DIVSN_ID= '" . trim($data['DIVISION_ID']) . "',
                    OLVL_ID= '" . trim($data['ORG_LEVEL_ID']) . "',
                    POSI_COUNT= '" . trim($data['POSITION_COUNT']) . "',
                    FILL_COUNT= '" . trim($data['POSITION_OCCUPIED']) . "',
                    EMP_LEVEL = '" . trim($data['EMP_LEVEL_ID']) . "',
                    CHG_BY= '" . $empCode . "',
                    CHG_ON= sysdate,
                    JD_ID=  '" . trim($data['JD_LABEL_ID']) . "'                  
                    WHERE ID='" . $data['ID'] . "'");

                endQry('Updated Successfully!');

                if($OrgId){
                    apiResponse(true,"Organogram updated successfully",[ "OrgId" => $data['ID']]);
                } else {
                    apiResponse(false, "Error occured", null, 200);
                }
            } else {
                $chk = singRec("SELECT
                ID FROM HR_ORGANOGRAM 
                WHERE 
                    COMPANY = '" . trim($data['COMPANY_ID']) . "'  
                    AND DEPT_ID= '" . trim($data['DEPARTMENT_ID']) . "'
                    AND DESI_ID = '" . trim($data['DESIGNATION_ID']) . "'
                    AND DIVSN_ID= '" . trim($data['DIVISION_ID']) . "' 
                    AND JD_ID=	'" . trim($data['JD_LABEL_ID']) . "' ");
                
                if($chk['ID'] == ""){
                    
                    $OrgId = executeQry("insert into HR_ORGANOGRAM (FINENT,COMPANY,DEPT_ID,DESI_ID,DIVSN_ID,OLVL_ID,POSI_COUNT,FILL_COUNT,EMP_LEVEL,CHG_BY,CHG_ON, JD_ID)
                        values ('" . trim($data['FIN_ENTITY_ID']) . "',
                                '" . trim($data['COMPANY_ID']) . "',
                                '" . trim($data['DEPARTMENT_ID']) . "',
                                '" . trim($data['DESIGNATION_ID']) . "',
                                '" . trim($data['DIVISION_ID']) . "',
                                '" . trim($data['ORG_LEVEL_ID']) . "',
                                '" . trim($data['POSITION_COUNT']) . "',
                                '" . trim($data['POSITION_OCCUPIED']) . "',
                                '" . trim($data['EMP_LEVEL_ID']) . "',
                                '" . $empCode . "',
                                sysdate,
                                '" . trim($data['JD_LABEL_ID']) . "'
                        ) returning  ID into :orgId ", 'OrgId');
                        endQry('Saved Successfully!');
                        if($OrgId){
                            apiResponse(
                                true,
                                "Organogram added successfully",
                                [
                                    "OrgId" => $OrgId,
                                ]
                            );
                        } else
                        {
                            apiResponse(false, "Error occured", null, 200);
                        }
                } else {
                    apiResponse(false, "Organogram already exists.", null, 200);
                    endQry('Organogram already Exists');
                }
            }
        } else {
            apiResponse(false, "Mode is empty. Cannot add/update data", null, 200);
        }

    exit;
       
        
    } else {
        apiResponse(false, "Form data is empty.", null, 200);       
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "saveOrganogramData.php"
    );

    apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
