<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "lr_head.php";

if($data['authForm']==true)
{
    // $tran_code = $data['TRAN_CODE'];
    // $task_id = $data['TASK_ID'];
    // $flag = $data['flag'];
    // $tran_code = $data['TRAN_CODE'];    
    // $auto_remarks = isset($data["AUTH_REMARKS"]) && $data["AUTH_REMARKS"] !== '' ? $data["AUTH_REMARKS"] : null;
    // $user = $data['EMP_CODE'];
    // $retval = 0;
    // $retstr = "";

    // $auth_process_status = ept_authtran.leave_auth($tran_code, $task_id, $flag, $auto_remarks, $user, $retval, $retstr);

    // print_r($auth_process_status);
    // exit;

    startQry();

    $get_temp_leave = singRec("SELECT * from epplive.BCS_EMP_LEAVES_TEMP where id= " . $data["ID"] . " ");
    
    $empmail = singRec("select com_email from ept_hr_employee_info where emp_code=(select report_to from epplive.bcs_employee where emp_code='" . $get_temp_leave['EMP_CODE'] . "' )");

    $empmail_self = singRec("select com_email from ept_hr_employee_info where emp_code='" . $get_temp_leave['EMP_CODE'] . "' ");

    $data["AUTH_REMARKS"] = isset($data["AUTH_REMARKS"]) && $data["AUTH_REMARKS"] !== ''
            ? $data["AUTH_REMARKS"]
            : null;
    //$data["AUTH_REMARKS"] = null;

    if($data['flag']=='R')
	{
        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']);   
        executeQry("update epplive.BCS_EMP_LEAVES_TEMP set status='R' where id='" . $data['ID'] . "'");    
        
        $mailBody = 'Hi ' . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['EMP_CODE']))) . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['ID']))) . '<br><br>' . ucwords(strtolower(getEmpInfoByCode($empCode))) . ' has rejected your leave request, the details are as follows.
        <br>
        <br>
            <b>  Leave from  Date:</b> ' . $get_temp_leave['LVE_DATE_FR'] . '<br>
            <b>  Leave to Date: </b> ' . $get_temp_leave['LVE_DATE_TO'] . ' <br>
            <b>  Total Days: </b> ' . $get_temp_leave['TOTAL_DAYS'] . ' <br>
            <b>  Leave Type:</b> ' . $get_temp_leave['LVE_CODE'] . ' <br>
            <b>  Reason:</b> ' . $get_temp_leave['REASON'] . ' <br>
            <b>  Status:</b> <b>REJECTED</b> <br><br>
        <br> Regards,<br> Admin';

        $maild = executeQry("INSERT INTO epplive.bcs_mailbox_epp(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,CHG_ON,CHG_BY,MAIL_DESCR) values(null,'Leave Rejected of " . getEmpInfoByCode($get_temp_leave['EMP_CODE']) . " from  " . $get_temp_leave['LVE_DATE_FR'] . " to " . $get_temp_leave['LVE_DATE_TO'] . "', '" . trim($mailBody) . "',null,'N',SYSDATE,'" . $empCode . "','Leave') returning ID into :mid", 'mid');

        executeQry("INSERT INTO epplive.bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'" . $maild . "', '" . strtolower($empmail_self['COM_EMAIL']) . " ','attendance@sdlindia.com',null)");

        endQry('Task Rejected');

        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "message" => "Record Rejected successfully",
        ]);

    } else if($data['flag']=='A') {
        $consume_days = $get_temp_leave['TOTAL_DAYS'];
        $dateArr = getbtwn_twodate($get_temp_leave['LVE_DATE_FR'], $get_temp_leave['LVE_DATE_TO']);
        foreach ($dateArr as $lvdate) {
            $no_days = (($consume_days > 0.5) ? 1 : 0.5);
            $consume_days = $consume_days - $no_days;
            
            $prd = singRec("select CODE from epplive.BCS_PERIOD where '" . $lvdate . "' between FR_DATE and TO_DATE");
			
            $already_lv = singRec("select ID from epplive.bcs_emp_leaves where LVE_DATE_FR='".$lvdate."' and LVE_DATE_TO='".$lvdate."' and emp_code='".$data['EMP_CODE']."' ");

            if($already_lv['ID']==''){
                $newId = executeQry("insert into epplive.bcs_emp_leaves(EMP_CODE,LVE_DATE_FR,LVE_DATE_TO,LVE_CODE, EMP_CODE_APRV,APRV_DATE,LVE_TYPE,ENCH_AMT,NO_DAYS,STATUS,STATUS_DATE, PRD_CODE,CHG_ON,CHG_BY,REMARKS,TRAN_ID_PAYR,NO_DAYS_ADV,APPL_DATE,AUTH_EMP, AUTH_EMP_ALTERNATE,TRAN_ID,LEAVE_STARTS,LEAVE_ENDS,START_TIME,END_TIME)
				values
				(
				'" . $get_temp_leave['EMP_CODE'] . "',
				'" . $lvdate . "',
				'" . $lvdate . "',
				'" . trim($get_temp_leave['LVE_CODE']) . "',
				'" . $data['EMP_CODE'] . "',
				SYSDATE,
				null,
				null,
				'" . $no_days . "',
				'N',
				SYSDATE,
				'" . $prd['CODE'] . "',
				SYSDATE,
				'" . $data['EMP_CODE'] . "',
				'" . $get_temp_leave['REASON'] . "',
				null,
				null,
				'" . $get_temp_leave['CHG_ON'] . "',
				null,
				null,
				'" . $data['ID'] . "',
				'" . $get_temp_leave['LVE_START_ON'] . "',
				'" . $get_temp_leave['LVE_END_ON'] . "',
				null,
				null
				) returning  ID into :newId ", 'newId');

                executeQry("update epplive.bcs_leave_balance set cons_days=cons_days+'" . $no_days . "', bal_days=bal_days-'" . $no_days . "' where emp_code='" . trim($get_temp_leave['EMP_CODE']) . "'  and lve_code='" . trim($get_temp_leave['LVE_CODE']) . "' and '" . $lvdate . "' between eff_date and upto_date+1");
            }
        }

        executeQry("update epplive.bcs_leave_balance set APPLY_COUNT=nvl(APPLY_COUNT,0)+1  where emp_code='" . trim($get_temp_leave['EMP_CODE']) . "' and lve_code='" . trim($get_temp_leave['LVE_CODE']) . "' and '" . $lvdate . "' between eff_date and upto_date+1");
        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']);

        executeQry("update epplive.BCS_EMP_LEAVES_TEMP set status='A' where id='" . $get_temp_leave['ID'] . "'");

		$mailBody = 'Hi ' . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['EMP_CODE']))) . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['ID']))) . '<br><br>' . ucwords(strtolower(getEmpInfoByCode($empCode))) . ' has approved your leave request, the details are as follows. 
        <br>
            <br><br>
                <b>  Leave from  Date:</b> ' . $get_temp_leave['LVE_DATE_FR'] . '<br>
                <b>  Leave to Date: </b> ' . $get_temp_leave['LVE_DATE_TO'] . ' <br>
                <b>  Total Days: </b> ' . $get_temp_leave['TOTAL_DAYS'] . ' <br>
                <b>  Leave Type:</b> ' . $get_temp_leave['LVE_CODE'] . ' <br>
                <b>  Reason:</b> ' . $get_temp_leave['REASON'] . ' <br>
            <br><br> Regards,<br> Admin';

        $maild = executeQry("INSERT INTO epplive.bcs_mailbox_epp(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS, CHG_ON,CHG_BY,MAIL_DESCR) values(null,'Leave APPROVED of " . getEmpInfoByCode($get_temp_leave['EMP_CODE']) . " from " . $get_temp_leave['LVE_DATE_FR'] . " to " . $get_temp_leave['LVE_DATE_TO'] . "', '" . trim($mailBody) . "',null,'N',SYSDATE,'" . $get_temp_leave['EMP_CODE'] . "','Leave') returning ID into :mid", 'mid');

		executeQry("INSERT INTO epplive.bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'" . $maild . "', '" . strtolower($empmail_self['COM_EMAIL']) . " ','attendance@sdlindia.com',null)");    
        
        endQry('Task Approved');
        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "message" => "Record Authroized successfully"
        ]);
    }
}

ob_end_flush();
