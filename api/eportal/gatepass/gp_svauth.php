<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "gp_head.php";
$decodeOT = [
    'OD' => 'Out For Full Day' ,
    'OI' => 'In/Out Same Day', 
    'FO' => 'First Half Out',
    'SO' => 'Second Half Out',
    'FW' => 'Field Work',
	'TO' => 'Tour'
];

if($data['authForm']==true)
{
    startQry();
    
    $gpass = singRec("select * from ept_employee_gpass where id='".$data['ID']."'");
    //$GtASKiD = singRec("select * from EPT_USER_TASKS where id='".$data['TASK_ID']."'");
    $empmail = singRec("select short_name ,email_id_off from EPT_bcs_employee where emp_code='".$gpass['EMP_CODE']."'");
    $data["AUTH_REMARKS"] = isset($data["AUTH_REMARKS"]) && $data["AUTH_REMARKS"] !== ''
            ? $data["AUTH_REMARKS"]
            : null;

    if($data['flag']=='R')
	{        
        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']); 
        
        executeQry("update ept_employee_gpass set STATUS='R', auth_by='".$empCode."', auth_on=sysdate where ID='".$data['ID']."' ");	

        if($empmail['EMAIL_ID_OFF']!='rap@sdlindia.com')
        {
            $mailBody='Hi
						<br><br> The Following Outdoor Duty Request has been REJECTED
						<br>
						<br><br>
						<b>  Employee  :</b> '.($empmail['SHORT_NAME']).'<br><br>
                            <b>  Outdoor Date :</b> '.$gpass['GPASS_DATE'].'<br><br>
                            <b>  Out Type : </b> '.$decodeOT[$gpass['OUT_TYPE']].' <br><br>
                            <b>  Remarks : </b> '.$gpass['REMARKS'].' <br><br>
                            <b>  Status :</b> <b>REJECTED</b> <br><br>
						<br><br> Regards<br> Admin';

            $mailBody = addslashes($mailBody);

			$insert_id=executeQry("INSERT INTO EPT_bcs_mailbox_epp (ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,CHG_ON,CHG_BY,MAIL_DESCR)
            values( 													
              null,
           ' Rejected Outdoor Duty Of ".getEmpInfoByCode($gpass['EMP_CODE'])." dated ".$gpass['GPASS_DATE']."',
              '".trim($mailBody)."',
              null,
              'N',
              SYSDATE,
              '".$empCode."',
              'Outdoor Duty') 
              returning ID into :newId",'newId');

		    executeQry("INSERT INTO EPT_bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'".$insert_id."', '".strtolower($empmail['EMAIL_ID_OFF'])." ','attendance@sdlindia.com',null)");
		}

        $chk = singRec("select * from EPT_USER_TASKS where id='" . $data['TASK_ID'] . "'");

        endQry('Task Rejected');
        
        apiResponse(
            true,
            "Record Rejected successfully",
            [ "chkTask" => $chk]
        );
        

		
    } else if($data['flag']=='A') {
        
        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']);
        $gpass_no = singRec("SELECT EPORTAL.EPT_EMPLOYEE_GPASS_SEQ.NEXTVAL AS GPNO FROM dual"); 
        
        //executeQry("update ept_employee_gpass set STATUS='X', auth_by='".$empCode."', auth_on=sysdate, gpass_no='".$gpass_no['GPNO']."' where ID='".$data['ID']."' ");	

        executeQry("update ept_employee_gpass set STATUS='A', auth_by='".$empCode."', auth_on=sysdate, gpass_no='".$gpass_no['GPNO']."' where ID='".$data['ID']."' ");	

       

        $review_pm_task_id = 0;

        $review_pm_task_id = executeQry("insert into EPT_USER_TASKS (
        ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (
        null, '21', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$data['ID']."', null, 'A', null, 'Review Outdoor Duty Post Remarks ', '".$_SESSION['eptSiteCode']."', '".$empCode."', sysdate, '', '".getEmpInfoByCode($gpass['EMP_CODE'])."', '') returning ID into :taskIdPM" ,'taskIdPM');
        
        $mailBody='Hi
		<br><br> The Following Outdoor Duty Request has been APPROVED
	    <br>
	    <br><br>
	    <b>  Employee  :</b> '.($empmail['SHORT_NAME']).'<br><br>
			   <b>  Outdoor Date :</b> '.$gpass['GPASS_DATE'].'<br><br>
			   <b>  Out Type : </b> '.$decodeOT[$gpass['OUT_TYPE']].' <br><br>
			   <b>  Remarks : </b> '.$gpass['REMARKS'].' <br><br>
			   <b>  Status :</b> <b>APPROVED</b> <br><br>
	    <br><br> Regards<br> Admin';
        $mailBody = addslashes($mailBody);

		$insert_id=executeQry("INSERT INTO EPT_bcs_mailbox_epp
        (ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,CHG_ON,CHG_BY,MAIL_DESCR)
        values( 													
           null,
           ' Approved Outdoor Duty Of ".getEmpInfoByCode($gpass['EMP_CODE'])." dated ".$gpass['GPASS_DATE']."',
            '".trim($mailBody)."',
            null,
            'N',
            SYSDATE,
            '".$empCode."',
            'Outdoor Duty') 
            returning ID into :newId",'newId');

		executeQry("INSERT INTO EPT_bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'".$insert_id."', '".strtolower($empmail['EMAIL_ID_OFF'])." ','attendance@sdlindia.com',null)");

        endQry('Task Approved');
        apiResponse(true,"Record Authroized successfully");
    }
} else if($data['closeTask']) {
    startQry();
    $chk = singRec("select * from EPT_USER_TASKS where id='" . $data['TASK_ID'] . "'");
    if($chk && !empty($chk)){
        taskUpdate('C', "OUtDuty Progress and POST Remarks reviewed", $data['TASK_ID']);
        if($data["TRAN_CODE"]) {
            executeQry("update ept_employee_gpass set status='X' where ID='".$data['TRAN_CODE']."' ") ;
	    }
    }
    endQry('Task Clossed');
    apiResponse(true,"Record Clossed successfully");
}

ob_end_flush();
