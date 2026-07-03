<?php

require_once "tb_head.php";


if($data['sendAuth']==true)
{
    startQry();

    if($data['ID'] != "") {
        $person = singRec("select * from epplive.bcs_trvltkt_request where id='".$data['ID']."'");
        if($person['TRVL_EMP']=='E'){
            $pn = singRec("select epplive.get_emp_name('".$person['EMP_CODE']."')pn from dual");
            // $Manager = findParentOrgEmp($_SESSION['ept']['eptEmpCode']);        
            $name = singRec("SELECT epplive.hr_get_emp_mgr('".$person['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
            $name1 = findParentOrgEmp($person['EMP_CODE']);        
            $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
            
            $sitecode = singRec("select pay_site from epplive.bcs_employee where emp_code='".$person['EMP_CODE']."'");
        } else {
            $pn['PN'] = $person['PERSON_NAME'];
            $sitecode = $_SESSION['eptSiteCode'];
            $name = singRec("SELECT epplive.hr_get_emp_mgr('".$_SESSION['eptSiteCode']."',SYSDATE)EMP_CODE FROM DUAL");
            $name1 = findParentOrgEmp($_SESSION['eptSiteCode']);        
            $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
        }

        $task_id=executeQry("insert into EPT_USER_TASKS (
            ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (null, '346', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$data['ID']."', null, 'A', null, 'Ticket Request From ".$person['TRVL_FROM_LOC']." To ".$person['TRVL_TO_LOC']." Dated ".$person['TRVL_DATE']."', '".$sitecode['PAY_SITE']."', '', sysdate, '', '".$person['PERSON_NAME']."', '') returning ID into :taskId" ,'taskId');

        executeQry("update epplive.BCS_TRVLTKT_REQUEST set status='T' where id='".$data['ID']."'") ;
        
        if($task_id){
            echo json_encode([
                "status" => true,
                "status_code" => 200,
                "message" => "Request sent for Authorization"
            ]);
            endQry("Sent for Authorization!");
        }
        else{
            echo json_encode([
                "status" => false,
                "status_code" => 500,
                "message" => "Some Error occured"
            ]);
        }
        endQry();
    }
}

ob_end_flush();
