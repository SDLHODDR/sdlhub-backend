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
    $empmail = singRec("select short_name ,email_id_off from epplive.bcs_employee where emp_code='".$gpass['EMP_CODE']."'");
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

			$insert_id=executeQry("INSERT INTO epplive.bcs_mailbox_epp (ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,CHG_ON,CHG_BY,MAIL_DESCR)
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

		    executeQry("INSERT INTO epplive.bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'".$insert_id."', '".strtolower($empmail['EMAIL_ID_OFF'])." ','attendance@sdlindia.com',null)");
		}

        $chk = singRec("select * from EPT_USER_TASKS where id='" . $data['TASK_ID'] . "'");

        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "chkTask" => $chk,
            "message" => "Record Rejected successfully"
        ]);

        

		endQry('Task Rejected');
    } else if($data['flag']=='A') {
        
        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']);
        $gpass_no = singRec("SELECT EPORTAL.EPT_EMPLOYEE_GPASS_SEQ.NEXTVAL AS GPNO FROM dual"); 
        
        executeQry("update ept_employee_gpass set STATUS='X', auth_by='".$empCode."', auth_on=sysdate, gpass_no='".$gpass_no['GPNO']."' where ID='".$data['ID']."' ");	
        
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

		$insert_id=executeQry("INSERT INTO epplive.bcs_mailbox_epp
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

		executeQry("INSERT INTO epplive.bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'".$insert_id."', '".strtolower($empmail['EMAIL_ID_OFF'])." ','attendance@sdlindia.com',null)");

        endQry('Task Approved');
        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "message" => "Record Authroized successfully"
        ]);
    }
}

ob_end_flush();
