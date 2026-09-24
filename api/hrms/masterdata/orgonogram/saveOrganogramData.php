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

function createTaskChecks(array $dataP, string &$failureMessage = '', $empCode): bool
{
    $orgId = trim((string)($dataP['ID'] ?? ''));
    $positionOccupied = (int)($dataP['POSITION_OCCUPIED'] ?? 0);

    if ($orgId === '') {
        $failureMessage = 'Organogram must be saved before task checks can run.';
        return false;
    }

    $locationCount = singRec(
        "SELECT COUNT(DISTINCT CASE
                    WHEN TRIM(get_org_loc_emp_code(hol.ID, SYSDATE)) IS NOT NULL THEN hol.ID
                END) CNT
            FROM HR_ORGANOGRAM ho
            JOIN HR_ORGANOGRAM_LOC hol ON hol.ORG_ID = ho.ID
            JOIN HR_SFM_NEW_EMP_LEVELS hel ON ho.EMP_LEVEL = hel.LEVL
            WHERE ho.ID = :orgId",
        [':orgId' => $orgId]
    );
    $mappedEmployeeCount = (int)($locationCount['CNT'] ?? 0);

    if ($mappedEmployeeCount !== $positionOccupied) {
        $failureMessage = "Task criteria not fulfilled: {$mappedEmployeeCount} active employee location(s) found, but position occupied is {$positionOccupied}.";
        return false;
    }

    $unlinkedLocations = singRec(
        "SELECT COUNT(*) CNT
            FROM HR_ORGANOGRAM_LOC hol
            WHERE hol.ORG_ID = :orgId
              AND NOT EXISTS (
                  SELECT 1
                  FROM HR_ORG_LOC_PARENT p
                  WHERE p.ORG_LOC_ID = hol.ID
                    AND p.EFFEC_FROM <= hol.EFFEC_FROM
                    AND (p.EFFEC_TO IS NULL OR p.EFFEC_TO >= hol.EFFEC_FROM)
              )",
        [':orgId' => $orgId]
    );
    $unlinkedLocationCount = (int)($unlinkedLocations['CNT'] ?? 0);

    if ($unlinkedLocationCount > 0) {
        $failureMessage = "Task criteria not fulfilled: {$unlinkedLocationCount} location(s) are not linked to an active parent location.";
        return false;
    }

    $locationsWithoutManager = singRec(
        "SELECT COUNT(*) CNT
            FROM HR_ORGANOGRAM_LOC hol
            WHERE hol.ORG_ID = :orgId
              AND TRIM(NVL(get_org_loc_mgr(hol.ID, SYSDATE),
                           get_emp_mgr(get_org_loc_emp_code(hol.ID, SYSDATE), SYSDATE))) IS NULL",
        [':orgId' => $orgId]
    );
    $locationsWithoutManagerCount = (int)($locationsWithoutManager['CNT'] ?? 0);

    if ($locationsWithoutManagerCount > 0) {
        $failureMessage = "Task criteria not fulfilled: {$locationsWithoutManagerCount} location(s) do not have a reporting manager.";
        return false;
    }

    $appraisalLevel = singRec(
        "SELECT COUNT(*) CNT
            FROM HR_ORG_APPR_LEVELS a
            WHERE a.ORG_ID = :orgId
              AND TRIM(GET_ORG_NAME(a.APPR_ORGID)) IS NOT NULL
              AND TRIM(GET_ORG_NAME(a.APPR_ORGID)) <> '-'",
        [':orgId' => $orgId]
    );

    if ((int)($appraisalLevel['CNT'] ?? 0) < 1) {
        $failureMessage = 'Task criteria not fulfilled: at least one appraisal level with a valid name is required.';
        return false;
    }

    return true;
}

function generateTaskOrg($dataP = "", $orgId = 0, $empCode) {
    /* ==========================================================
       GET TASK MASTER

       IMPORTANT:
       Do NOT COMMIT before this query and task INSERT.
       ========================================================== */

    $task = singRec("
        SELECT
            t.*,
            TRUNC(SYSDATE) + t.EXPIRY_DAYS AS EXPDT
        FROM HR_TASK_MASTER t
        WHERE TASK_LABEL = 'Authorise Organogram' AND ID = 56
    ");

    /* ==========================================================
       CHECK TASK MASTER
       ========================================================== */

    if ($task === false) {
        forceRollback(
            'Failed to fetch task configuration for bank details update.'
        );
        return;
    }

    if (empty($task)) {
        forceRollback(
            'Task configuration not found for bank details update.'
        );
        return;
    }

    /* ==========================================================
       VALIDATE TASK MASTER DATA
       ========================================================== */

    if (!isset($task['ID']) || $task['ID'] === '') {
        forceRollback(
            'Task configuration found, but task ID is missing.'
        );
        return;
    }

    if (!isset($task['TASK_GRP']) || $task['TASK_GRP'] === '') {
        forceRollback(
            'Task configuration found, but TASK GRP is missing.'
        );
        return;
    }

    if (!isset($task['TASK_DESC']) || $task['TASK_DESC'] === '') {
        forceRollback(
            'Task configuration found, but TASK DESC is missing.'
        );
        return;
    }

    if (
        !isset($task['EXPDT']) ||
        $task['EXPDT'] === ''
    ) {
        forceRollback(
            'Task configuration found, but task expiry date is missing.'
        );
        return;
    }

    /* ==========================================================
       TRANSACTION DESCRIPTION
       ========================================================== */

    $tran_desc = "Organogram submitted by employee {$empCode} for authorization.";

    // $bindVal = array(
    //     'ptaskgrp' => array(
    //         'type'  => 'IN',
    //         'value' => $task['TASK_GRP']
    //     ),

    //     'pTranCode' => array(
    //         'type'  => 'IN',
    //         'value' => $orgId
    //     ),

    //     'ptrandesc' => array(
    //         'type'  => 'IN',
    //         'value' => $tran_desc
    //     ),

    //     'ptaskgrpdesc' => array(
    //         'type'  => 'IN',
    //         'value' => $task['TASK_DESC']
    //     ),

    //     'pstatus' => array(
    //         'type'  => 'IN',
    //         'value' => 'O'
    //     ),

    //     'pemp_code_for' => array(
    //         'type'  => 'IN',
    //         'value' => NULL
    //     ),

    //     'pCreateBy' => array(
    //         'type'  => 'IN',
    //         'value' => $empCode
    //     ),

    //     'pcompId' => array(
    //         'type'  => 'IN',
    //         'value' => $dataP['COMPANY_ID']
    //     ),

    //     'pdeptId' => array(
    //         'type'  => 'IN',
    //         'value' => $dataP['DEPARTMENT_ID']
    //     ),

    //     'pdivsnId' => array(
    //         'type'  => 'IN',
    //         'value' => $dataP['DIVISION_ID']
    //     ),

    //     'pretval' => array(
    //         'type'   => 'OUT',
    //         'length' => 100
    //     ),

    //     'pretstr' => array(
    //         'type'   => 'OUT',
    //         'length' => 4000
    //     ),

    //     'result' => array(
    //         'type'   => 'OUT',
    //         'length' => 100
    //     )
    // );

    // print_r($bindVal);

    // $resProc = executeProc(
    //     "BEGIN
    //         :result := hr_authtran.gentask(
    //             :ptaskgrp,
    //             :pTranCode,
    //             :ptrandesc,
    //             :ptaskgrpdesc,
    //             :pstatus,
    //             :pemp_code_for,
    //             :pCreateBy,
    //             :pcompId,
    //             :pdeptId,
    //             :pdivsnId,
    //             :pretval,
    //             :pretstr
    //         );
    //     END;",
    //     $bindVal
    // );

    // print_r($resProc);

     $resProc = execQry(array(
        'type' => 'insert',
        'table' => 'HR_USER_TASKS',
        'data' => array(
            'ID' => '',
            'TASK_ID' => $task['ID'],
            'STATUS' => 'O',
            'TRAN_CODE' => $orgId,
            'TASK_TYPE' => $task['TASK_TYPE'],
            'TRAN_DESC' => trim($tran_desc),
            'TASK_GRP_DESC' => $task['TASK_LABEL'],
            'EMP_CODE_FOR' => NULL,
            'REMARKS' => 'SENT FOR AUTHORIZATION',
            'COMP_ID' => $dataP['COMPANY_ID'],
            'DIVSN_ID' => $dataP['DIVISION_ID'],
            'DEPT_ID' => $dataP['DEPARTMENT_ID'],
            'EXPIRE_ON' => $task['EXPDT'],
            'CREATED_ON' => 'SYSDATE',
            'CREATED_BY' => $empCode
        ),
        'return' => 'ID',
        'print' => 0
    ));

    /* ==========================================================
    CHECK USER TASK INSERT
    ========================================================== */
    if ($resProc === false) {
        forceRollback('Error executing hr_authtran.gentask');
        return false;
    }

    // if ($resProc['result'] == -1) {
    //     forceRollback(
    //         'Fail In Procedure genTask: ' . $resProc['pretstr']
    //     );
    // }

    // // Values returned by Oracle
    // $taskReturn = $resProc['result'];
    // $retVal     = $resProc['pretval'];
    // $retStr     = $resProc['pretstr'];

    return $resProc;
}

try {
    startQry();
    if(!empty($data)) {
        $hrOrgStatus = 'N';
        $canSendForAuth = false;
        $taskCheckMessage = '';

        if(isset($data['mode']) || !empty($data['mode'])) {
            if($data['mode'] == "edit") {
                if(!empty($data["sendForAuth"])) {
                    $canSendForAuth = createTaskChecks($data, $taskCheckMessage, $empCode);
                    ($canSendForAuth) ? $hrOrgStatus = "T" : $hrOrgStatus = "N";
                }

                // echo " canSendForAuth check : " . $canSendForAuth;
                // echo "<br/> hrOrgStatus : " . $hrOrgStatus;
                // exit;
                // if($canSendForAuth){
                    
                // }
                 
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
                    STATUS= '" . $hrOrgStatus . "',
                    CHG_ON= sysdate,
                    JD_ID=  '" . trim($data['JD_LABEL_ID']) . "'                  
                    WHERE ID='" . $data['ID'] . "'");

                endQry('Updated Successfully!');

                if($OrgId){
                    if($canSendForAuth){
                        //Function to generate Task
                        $taskRes = generateTaskOrg($data, $OrgId, $empCode);
                        // echo '<pre/>';
                        // print_r($taskRes);
                        // exit;
                        if(isset($taskRes) || !empty($taskRes)) {
                            /* ==========================================================
                                COMMIT

                                COMMIT ONLY AFTER:
                                1. Organogram inserted/updated successfully
                                2. Task master found successfully
                                3. User task inserted successfully
                                ========================================================== */

                            endQry();
                            //apiResponse(true, $canSendForAuth ? "Organogram updated successfully and is ready for task generation." : "Organogram updated successfully. " . ($taskCheckMessage ?: "Task generation was not requested."), [
                            apiResponse(true, "Organogram updated successfully. ", [
                                "OrgId" => $data['ID'],
                                "canSendForAuth" => $canSendForAuth,
                                "status" => $hrOrgStatus,
                                "task_id" => $taskRes
                            ]);
                        } else {
                            $OrgId = executeQry("UPDATE HR_ORGANOGRAM SET 
                                STATUS= 'N',
                                CHG_BY= '" . $empCode . "',
                                CHG_ON= sysdate,
                                WHERE ID='" . $data['ID'] . "'");
                                
                            endQry();
                            apiResponse(false, "Organogram Upated but Error occured while generating Task", null, 200);
                        }
                    }    

                    
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
                    if(!empty($data["sendForAuth"])) {
                        $canSendForAuth = createTaskChecks($data, $taskCheckMessage);
                        ($canSendForAuth) ? $hrOrgStatus = "T" : $hrOrgStatus = "N";
                    }

                    $OrgId = executeQry("INSERT INTO HR_ORGANOGRAM (FINENT,COMPANY,DEPT_ID,DESI_ID,DIVSN_ID,OLVL_ID,POSI_COUNT,FILL_COUNT,EMP_LEVEL,CHG_BY,STATUS,CHG_ON, JD_ID)
                        VALUES ('" . trim($data['FIN_ENTITY_ID']) . "',
                            '" . trim($data['COMPANY_ID']) . "',
                            '" . trim($data['DEPARTMENT_ID']) . "',
                            '" . trim($data['DESIGNATION_ID']) . "',
                            '" . trim($data['DIVISION_ID']) . "',
                            '" . trim($data['ORG_LEVEL_ID']) . "',
                            '" . trim($data['POSITION_COUNT']) . "',
                            '" . trim($data['POSITION_OCCUPIED']) . "',
                            '" . trim($data['EMP_LEVEL_ID']) . "',
                            '" . $empCode . "',
                            '" . $hrOrgStatus . "',
                            sysdate,
                            '" . trim($data['JD_LABEL_ID']) . "'
                        ) returning  ID into :orgId ", 'OrgId');

                    endQry('Saved Successfully!');
                    if($OrgId){
                        apiResponse(true, $canSendForAuth ? "Organogram added successfully and is ready for task generation." : "Organogram added successfully. " . ($taskCheckMessage ?: "Task generation was not requested."),
                                [
                                    "OrgId" => $OrgId,
                                    "canSendForAuth" => $canSendForAuth,
                                    "status" => $hrOrgStatus,
                                    ]
                        );
                    } else {
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
