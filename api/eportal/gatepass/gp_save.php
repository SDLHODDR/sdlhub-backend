<?php
//  ini_set('display_errors', 1);
//  error_reporting(E_ALL);

require_once "gp_head.php";

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}
$isSave = isset($data['saveGpData']) && $data['saveGpData'] == true;
$isEdit = isset($data['editGpData']) && $data['editGpData'] == true;
if($isSave)
{
    startQry();
    if($data['ID'] != "") {
        $gpass= singRec("select * from EPT_EMPLOYEE_GPASS where  id = '".$data['ID']."'");
        $name = singRec("SELECT hr_get_emp_mgr('".$gpass['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
        $name1 = findParentOrgEmp($gpass['EMP_CODE']);        
        $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
    } else {
        $isAllowed = true;
        // ===============================
        // Fetch existing entries for same date
        // ===============================

        $gpass = multiRec("
            select OUT_TYPE 
            from EPT_EMPLOYEE_GPASS 
            where EMP_CODE = '".$data['EMP_CODE']."' 
            and GPASS_DATE = '".$data['GPASS_DATE']."'
        ");

        $existingTypes = array_column($gpass, 'OUT_TYPE');
        
        $newType = $data['OUT_TYPE'];

        // ===============================
        // RULE 1: OD / FW / TO (strict single)
        // ===============================
        if (in_array($newType, ["OD", "FW", "TO"])) {
            if (count($existingTypes) > 0) {
                $isAllowed = false;
                apiResponse(false, "Only one ".$newType." entry allowed for this date", null, 200);
                
                exit;
            }
        }
        
        // ===============================
        // RULE 2: If OD/FW/TO already exists → block all
        // ===============================
        foreach ($existingTypes as $type) {
            if (in_array($type, ["OD", "FW", "TO"])) {
                $isAllowed = false;
                apiResponse(false, "Full-day/Field/Tour entry already exists for this date", null, 200);
                
                exit;
            }
        }

        // ===============================
        // RULE 3: FO / SO (single per type)
        // ===============================
        if ($newType === "FO" && in_array("FO", $existingTypes)) {
            $isAllowed = false;    
           apiResponse(false, "First Half already exists for this date", null, 200);
            
            exit;
        }

        if ($newType === "SO" && in_array("SO", $existingTypes)) {
            $isAllowed = false;
            apiResponse(false, "Second Half already exists for this date", null, 200);
            exit;
        }

        // ===============================
        // RULE 4: FO + SO combination (ALLOW or BLOCK based on requirement)
        // ===============================

        // If you want to BLOCK FO + SO together → keep this
        /*
        if ($newType === "FO" && in_array("SO", $existingTypes)) {
            echo json_encode([
                "status" => false,
                "message" => "Cannot combine First Half and Second Half"
            ]);
            rollbackQry();
            exit;
        }
        if ($newType === "SO" && in_array("FO", $existingTypes)) {
            echo json_encode([
                "status" => false,
                "message" => "Cannot combine First Half and Second Half"
            ]);
            rollbackQry();
            exit;
        }
        */

        // If you want to ALLOW FO + SO → keep it commented (recommended based on your latest message)

        // ===============================
        // RULE 5: OI → always allowed
        // ===============================
        // No restriction needed
        //exit;
        if($isAllowed)
        {
            $name = singRec("SELECT hr_get_emp_mgr('".$data['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
            $name1 = findParentOrgEmp($data['EMP_CODE']);        
            $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
            $manageremail = singRec("select EMAIL_ID_OFF as COM_EMAIL from EPT_bcs_employee WHERE 
                emp_code = '".$Manager."'");

            $insert_id=executeQry("INSERT INTO EPT_EMPLOYEE_GPASS (ID, GPASS_NO, GPASS_DATE, EMP_CODE, OUT_TYPE,  REMARKS ,STATUS, CHG_ON, CHG_BY , auth_by)
                values( 													
                    null, 
                    'null', 
                    '".$data['GPASS_DATE']."', 
                    '".$data['EMP_CODE']."', 
                    '".$data['OUT_TYPE']."', 
                    '". str_replace("'", "''", $data['REMARKS']) ."', 
                    'N', 
                    sysdate, 
                    '".$empCode."', 
                    null) returning ID into :newId",'newId');

            $eppSiteCode = $_SESSION['eppSiteCode'] ?? null;
            $eppEmpCode = $_SESSION['eppEmpCode'] ?? null;

            $tasklog_id = executeQry("insert into ept_user_tasks_log  
                            (ID, USER_TASKID, TASK_ID, TRAN_CODE, STATUS, REMARKS, SITE_CODE, EMP_CODE_FOR, IP_ADDR, CHG_ON, CHG_BY) values 
                            ( null, '', '2', '".$insert_id."', 'O', '".str_replace("'", "''", $data['REMARKS'])."',  '".$eppSiteCode."', '".$Manager."', '', sysdate, '".$eppEmpCode."' ) returning ID into :taskId", 'taskId');            
            
            if($insert_id) {
                if($data['withAuth']==true){    
                    $gpass = singRec("select * from EPT_EMPLOYEE_GPASS where  id = '".$insert_id."'");
                    $name = singRec("SELECT hr_get_emp_mgr('".$gpass['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
                    $name1 = findParentOrgEmp($gpass['EMP_CODE']);        
                    $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
                    
                    $manageremail = singRec("select EMAIL_ID_OFF as COM_EMAIL from EPT_bcs_employee 
                                        WHERE emp_code = '".$Manager."'");

                    $task_id = executeQry("insert into EPT_USER_TASKS (
                    ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (
                    null, '349', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$insert_id."', null, 'A', null, concat('Outdoor DATED ', '".$gpass['GPASS_DATE']."' ), '".$_SESSION['eptSiteCode']."', '".$Manager."', sysdate, '', '".getEmpInfoByCode($gpass['EMP_CODE'])."', '') returning ID into :taskId" ,'taskId');

                    $postremarks_task_id = 0;
                    $postremarks_task_id = executeQry("insert into EPT_USER_TASKS (
                        ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (
                        null, '349', sysdate,'".$empCode."' , (sysdate+2), 'O', 'null', null, null, '".$insert_id."', null, 'A', null, concat('Outdoor DATED ', '".$gpass['GPASS_DATE']." User task for Post Remarks' ), '".$_SESSION['eptSiteCode']."', '".$empCode."', sysdate, '', concat('POSTREMARKS~', '".getEmpInfoByCode($gpass['EMP_CODE'])."' ), '') returning ID into :taskPMRId" ,'taskPMRId');

                    executeQry("update ept_employee_gpass 
                      set status='T',
                      chg_by ='".$empCode."',
                      chg_on = SYSDATE where id='".$insert_id."' ");
                    
                    $mailBody='Hi
                    <br><br> The Following Outdoor Duty Request has been Raised.
                    <br>
                    <br><br>
                    <b>  Employee  :</b> '.(getEmpInfoByCode($gpass['EMP_CODE'])).'<br><br>
                    <b>  Outdoor Date :</b> '.$gpass['GPASS_DATE'].'<br><br>
                    <b>  Out Type : </b> '.$decodeOT[$gpass['OUT_TYPE']].' <br><br>
                    <b>  Remarks : </b> '.$gpass['REMARKS'].' <br><br>
                    <b>  Status :</b> <b>Pending Approval</b> <br><br>
                    <br><br> Regards<br> Admin';

                    if($manageremail['COM_EMAIL']!='rap@sdlindia.com') {
                        $maild = executeQry("INSERT INTO EPT_BCS_MAILBOX_EPP(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS, CHG_ON,CHG_BY,MAIL_DESCR) values(null,'  Outdoor Duty Request Of ".getEmpInfoByCode($gpass['EMP_CODE'])." dated ".$gpass['GPASS_DATE']."', '".trim($mailBody)."',null,'N',SYSDATE,
                        '".$empCode."','Outdoor Duty')  returning ID into :mid",'mid');

                        executeQry("INSERT INTO EPT_BCS_MAILBOX_EPP_DETAILS(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC)
                                    values(null,'".$maild."', '".strtolower($manageremail['COM_EMAIL'])." ','attendance@sdlindia.com',null)");			
                        endQry();
                    }
                    endQry();
                    apiResponse(
                      true,
                      "Gatepass generated and send for Authorization successfully",
                      [
                        "task_id" => $task_id,
                        "gpass_id" => $insert_id,
                        "postremarks_task_id" => $postremarks_task_id,
                      ]
                    );
                } else {
                    endQry();
                    apiResponse(
                      true,
                      "Gatepass generated successfully",
                      [
                        "task_id" => $insert_id,
                        "gpass_id" => $insert_id,
                      ]
                    );
                    
                }
            } else {
                apiResponse(false,"Some Error occured",null,500,$e->getMessage());
            }
        }
        endQry();
    }
} 
else if($isEdit)
{
    startQry();
    if($data["ID"]) {
        
        $updateFields = "
            OUT_TYPE = '".$data['OUT_TYPE']."',
            REMARKS = '" . str_replace("'", "''", $data['REMARKS']) . "'
        ";

        //Add POST_REMARKS only if present
        if (isset($data['POST_REMARKS']) && $data['POST_REMARKS'] !== "") {
            if($_FILES["POST_REMARKS_DOC"]["name"])
            {
                $fileType = $_FILES['POST_REMARKS_DOC']['type'] ?? '';
                $fileSize = $_FILES['POST_REMARKS_DOC']['size'] ?? 0;
                $fileTmp  = $_FILES['POST_REMARKS_DOC']['tmp_name'];
                $fileName = $_FILES['POST_REMARKS_DOC']['name'] ?? '';

                // Generate a safe, collision-free filename instead of trusting
                // the client-supplied name (path traversal / overwrite risk).
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);
                $generatedName = 'gatepass_' . bin2hex(random_bytes(8)) . ($safeExt ? '.' . $safeExt : '');
                
                $uploadDir = __DIR__ . '/../../../input/gatepass/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $destPath = $uploadDir . $generatedName;
                if (!move_uploaded_file($fileTmp, $destPath)) {
                    apiResponse(false, "Unable to store uploaded document.", null, 500);
                }

                // Web-relative path saved to DB / returned to frontend
                $docPath = 'input/gatepass/' . $generatedName;
            }

            $updateFields .= ",
                POST_REMARKS = '" . str_replace("'", "''", $data['POST_REMARKS']) . "',
                CHG_BY = '" . $empCode . "',
                CHG_ON = SYSDATE";

            if (!empty($docPath)) {
                $updateFields .= ",
                POST_REMARKS_DOC = '" . str_replace("'", "''", $docPath) . "'";
            }
        }
        
        // echo "UPDATE ept_employee_gpass
        //     SET $updateFields
        //     WHERE ID IN (".$data['ID'].")";

        // exit;
        $editPRMId = executeQry("UPDATE ept_employee_gpass
            SET $updateFields
            WHERE ID IN (".$data['ID'].")
            returning ID into :editPRMId", 'editPRMId');

        if($editPRMId) {
            if (isset($data['POST_REMARKS']) && $data['POST_REMARKS'] !== "") {
                $chkTask = singRec("SELECT ID FROM EPT_USER_TASKS WHERE TRAN_CODE='" . $data['ID'] . "' AND TASK_ID = '349' and EMP_CODE_FOR = '" . $empCode . "' "); 
                 
                if(isset($chkTask['ID']) && $chkTask['ID'] !== ""){
                    taskUpdate('C', '', $chkTask['ID']);
                }
                
                $gpass = singRec("select * from EPT_EMPLOYEE_GPASS where  id = '".$data['ID']."'");
                $name = singRec("SELECT hr_get_emp_mgr('".$gpass['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
                $name1 = findParentOrgEmp($gpass['EMP_CODE']);        
                $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
                $manageremail = singRec("select EMAIL_ID_OFF as COM_EMAIL from EPT_bcs_employee 
                                    WHERE emp_code = '".$Manager."'");

    //             $review_pm_task_id = 0;
                
    //             $review_pm_task_id = executeQry("insert into EPT_USER_TASKS (
    // ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (
    // null, '21', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$data['ID']."', null, 'A', null, concat('Review Outdoor Duty Post Remarks DATED ', '".$gpass['GPASS_DATE']."' ), '".$_SESSION['eptSiteCode']."', '".$Manager."', sysdate, '', '".getEmpInfoByCode($gpass['EMP_CODE'])."', '') returning ID into :taskIdPM" ,'taskIdPM');
               
                $mailBody='Hi '.ucwords(strtolower(getEmpInfoByCode($Manager))). ',<br><br>' .ucwords(strtolower(getEmpInfoByCode($empCode))).' has modified the post remarks for the outdoor. 
                <br>
                <br><br>
                <b>  Employee  :</b> '.getEmpInfoByCode($gpass['EMP_CODE']).'<br><br>
                <b>  Outdoor Date :</b> '.$gpass['GPASS_DATE'].'<br><br>
                <b>  Out Type :</b> '.$decodeOT[$gpass['OUT_TYPE']].' <br><br>
                <b>  Remarks :</b> '.$gpass['REMARKS'].' <br><br>
                <b>  Post OD Remarks :</b> '.$data['POST_REMARKS'].' <br>
                <br><br> Regards<br> Admin';
                if($_FILES["POST_REMARKS_DOC"]["name"])
                {
                  $mailBody='Hi '.ucwords(strtolower(getEmpInfoByCode($Manager))). ',<br><br>' .ucwords(strtolower(getEmpInfoByCode($empCode))).' has modified the post remarks for the outdoor. 
                    <br>
                    <br><br>
                    <b>  Employee  :</b> '.getEmpInfoByCode($gpass['EMP_CODE']).'<br><br>
                    <b>  Outdoor Date :</b> '.$gpass['GPASS_DATE'].'<br><br>
                    <b>  Out Type :</b> '.$decodeOT[$gpass['OUT_TYPE']].' <br><br>
                    <b>  Remarks :</b> '.$gpass['REMARKS'].' <br><br>
                    <b>  Post OD Remarks :</b> '.$data['POST_REMARKS'].' <br>
                    <b>  Post OD Document :</b> <a href="https://eportal.sdlindia.com/'.$uploadedFile.'">Click Here</a> <br>
                    <br><br> Regards<br> Admin';
                  if($manageremail['COM_EMAIL']!='rap@sdlindia.com') {
                    $maild = executeQry("INSERT INTO EPT_BCS_MAILBOX_EPP(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS, CHG_ON,CHG_BY,MAIL_DESCR) values(null,'  Outdoor Duty Request Of ".getEmpInfoByCode($gpass['EMP_CODE'])." dated ".$gpass['GPASS_DATE']."', '".trim($mailBody)."',null,'N',SYSDATE,
                        '".$empCode."','Outdoor Duty')  returning ID into :mid",'mid');

                    executeQry("INSERT INTO EPT_BCS_MAILBOX_EPP_DETAILS(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'".$maild."', '".strtolower($manageremail['COM_EMAIL'])." ','attendance@sdlindia.com',null)");			
                    
                    endQry();
                  }
                }
            }
        }
    }
    endQry();
    apiResponse(true,"Gatepass updated successfully");
    
}
else if($data['deleteOD']==true)
{
    startQry();
    if($data["delteId"]) {
        executeQry("DELETE FROM ept_employee_gpass WHERE ID in (".$data['delteId'].")");
    }
    // echo json_encode([
    //     "status" => true,
    //     "status_code" => 200,
    //     "message" => "Gatepass deleted successfully"
    // ]);
     endQry();
    apiResponse(true,"Gatepass deleted successfully");
   
}else if($data['closeTicket']==true)
{
    startQry();
    if($data["ID"]) {
        executeQry("update ept_employee_gpass set status='X' where ID='".$data['ID']."' ") ;
	    executeQry("update EPT_USER_TASKS set status='C', Remarks='Auto Closed due to Cancellation' where task_id='349' and tran_code='".$data['ID']."'");
	}
   
     endQry();
    apiResponse(true,"Gatepass deleted successfully");
   
}

ob_end_flush();
