<?php

require_once "gp_head.php";

if($data['sendAuth']==true)
{
    if($data['ID'] != "") {
        $gpass = singRec("select * from EPT_EMPLOYEE_GPASS where  id = '".$data['ID']."'");
        $name = singRec("SELECT hr_get_emp_mgr('".$gpass['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
        $name1 = findParentOrgEmp($gpass['EMP_CODE']);        
        $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
        
        $manageremail = singRec("select EMAIL_ID_OFF as COM_EMAIL from epplive.bcs_employee 
                            WHERE emp_code = '".$Manager."'");
        
        $task_id = executeQry("insert into EPT_USER_TASKS (
                    ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (
                    null, '349', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$data['ID']."', null, 'A', null, concat('Outdoor DATED ', '".$gpass['GPASS_DATE']."' ), '".$_SESSION['eptSiteCode']."', '".$Manager."', sysdate, '', '".getEmpInfoByCode($gpass['EMP_CODE'])."', '') returning ID into :taskId" ,'taskId');
        
        executeQry("update ept_employee_gpass 
                      set status='T',
                      chg_by ='".$empCode."',
                      chg_on = SYSDATE where id='".$data['ID']."' ");
        
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
            $maild = executeQry("INSERT INTO bcs_mailbox_epp(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS, CHG_ON,CHG_BY,MAIL_DESCR) values(null,'  Outdoor Duty Request Of ".getEmpInfoByCode($gpass['EMP_CODE'])." dated ".$gpass['GPASS_DATE']."', '".trim($mailBody)."',null,'N',SYSDATE,
            '".$empCode."','Outdoor Duty')  returning ID into :mid",'mid');

            executeQry("INSERT INTO bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC)
                        values(null,'".$maild."', '".strtolower($manageremail['COM_EMAIL'])." ','attendance@sdlindia.com',null)");			
            endQry();
        }
        
        echo json_encode([
            "status" => true,
            "message" => "Authorization sent successfully"
        ]);

       

        endQry();
    }
} else if($data['resendAuth']==true)
{
    if($data['ID'] != "") {
        executeQry("update ept_employee_gpass
                 set status ='T', CHG_BY='" . trim(strtoupper($empCode)) . "', 
                 CHG_ON = SYSDATE where id='".$data['ID']."' ") ;

        executeQry("UPDATE EPT_USER_TASKS SET 
					  STATUS='O',
					  AUTH_ON=SYSDATE
				  WHERE task_id='349' AND tran_code='" . $data['ID'] . "'");          

        endQry();
    } 
}

ob_end_flush();
