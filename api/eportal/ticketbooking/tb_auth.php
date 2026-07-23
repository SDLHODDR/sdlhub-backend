<?php

require_once "tb_head.php";


if($data['sendAuth']==true)
{
    startQry();

    if($data['ID'] != "") {
        $person = singRec("select * from EPT_bcs_trvltkt_request where id='".$data['ID']."'");
        if($person['TRVL_EMP']=='E'){
            $pn = singRec("select EPT_get_emp_name('".$person['EMP_CODE']."')pn from dual");
            // $Manager = findParentOrgEmp($_SESSION['ept']['eptEmpCode']);        
            $name = singRec("SELECT EPT_hr_get_emp_mgr('".$person['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
            $name1 = findParentOrgEmp($person['EMP_CODE']);        
            $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
            
            $sitecode = singRec("select pay_site from EPT_bcs_employee where emp_code='".$person['EMP_CODE']."'");
        } else {
            $pn['PN'] = $person['PERSON_NAME'];
            $sitecode = $_SESSION['eptSiteCode'];
            $name = singRec("SELECT EPT_hr_get_emp_mgr('".$_SESSION['eptSiteCode']."',SYSDATE)EMP_CODE FROM DUAL");
            $name1 = findParentOrgEmp($_SESSION['eptSiteCode']);        
            $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
        }

        $task_id=executeQry("insert into EPT_USER_TASKS (
            ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (null, '346', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$data['ID']."', null, 'A', null, 'Ticket Request From ".$person['TRVL_FROM_LOC']." To ".$person['TRVL_TO_LOC']." Dated ".$person['TRVL_DATE']."', '".$sitecode['PAY_SITE']."', '', sysdate, '', '".$person['PERSON_NAME']."', '') returning ID into :taskId" ,'taskId');

        executeQry("update EPT_BCS_TRVLTKT_REQUEST set status='T' where id='".$data['ID']."'") ;
        
        if($task_id){
            // echo json_encode([
            //     "status" => true,
            //     "status_code" => 200,
            //     "message" => "Request sent for Authorization"
            // ]);
             endQry("Sent for Authorization!");
            apiResponse(true,"Request sent for Authorization successfully");
           
        }
        else{
            // echo json_encode([
            //     "status" => false,
            //     "status_code" => 500,
            //     "message" => "Some Error occured"
            // ]);
            endQry();
            apiResponse(false,"Some Error occured",null,500,$e->getMessage());
        }
        
    }
}else if($data['resendAuth']==true)
{
    if($data['ID'] != "") {
        executeQry("UPDATE EPT_USER_TASKS SET 
					  STATUS='O',
					  AUTH_ON=SYSDATE
				  WHERE task_id='346' AND tran_code='" . $data['ID'] . "'"); 
                  
        executeQry("update EPT_BCS_TRVLTKT_REQUEST set status='T' where id='".$data['ID']."'");
        endQry();
    } 
}

ob_end_flush();
