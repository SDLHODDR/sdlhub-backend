<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "lr_head.php";

if($data['authForm']==true)
{
    startQry();

    $get_temp_leave = singRec("SELECT * from EPT_BCS_EMP_LEAVES_TEMP where id= " . $data["ID"] . " ");
    //logFuncError(json_encode($get_temp_leave)," == get_temp_leave");
    
    $empmail = singRec("select com_email from ept_hr_employee_info where emp_code=(select report_to from EPT_bcs_employee where emp_code='" . $get_temp_leave['EMP_CODE'] . "' )");
    //logFuncError(json_encode($empmail)," == empmail");

    $empmail_self = singRec("select com_email from ept_hr_employee_info where emp_code='" . $get_temp_leave['EMP_CODE'] . "' ");
    //logFuncError(json_encode($empmail_self)," == empmail_self");

    $data["AUTH_REMARKS"] = isset($data["AUTH_REMARKS"]) && $data["AUTH_REMARKS"] !== ''
            ? $data["AUTH_REMARKS"]
            : null;
    //$data["AUTH_REMARKS"] = null;

    if($data['flag']=='R')
	{
        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']);   
        executeQry("update EPT_BCS_EMP_LEAVES_TEMP set status='R' where id='" . $data['ID'] . "'");    
        
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

        $maild = executeQry("INSERT INTO EPT_bcs_mailbox_epp(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,CHG_ON,CHG_BY,MAIL_DESCR) values(null,'Leave Rejected of " . getEmpInfoByCode($get_temp_leave['EMP_CODE']) . " from  " . $get_temp_leave['LVE_DATE_FR'] . " to " . $get_temp_leave['LVE_DATE_TO'] . "', '" . trim($mailBody) . "',null,'N',SYSDATE,'" . $empCode . "','Leave') returning ID into :mid", 'mid');

        executeQry("INSERT INTO EPT_bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'" . $maild . "', '" . strtolower($empmail_self['COM_EMAIL']) . " ','attendance@sdlindia.com',null)");

        //endQry('Task Rejected');
        endQry();
        apiResponse(true,"Record Rejected successfully");

    } else if($data['flag']=='A') {
        $consume_days = $get_temp_leave['TOTAL_DAYS'];
        //logFuncError(json_encode($consume_days)," == consume_days");
        $dateArr = getbtwn_twodate($get_temp_leave['LVE_DATE_FR'], $get_temp_leave['LVE_DATE_TO']);
        //print_r($dateArr); //exit;
        $last_valid_date = null; // will hold the last date with no existing entry
        //logFuncError(json_encode($dateArr)," == dateArr");
        
        foreach ($dateArr as $lvdate) {
            $already_lv = singRec("select ID from EPT_bcs_emp_leaves where LVE_DATE_FR='".$lvdate."' and LVE_DATE_TO='".$lvdate."' and emp_code='".$data['EMP_CODE']."' ");
           
            //logFuncError(json_encode($already_lv)," == already_lv");
            if ($already_lv['ID'] == '') {
                // no entry found for this date -> it's "good", remember it
                $last_valid_date = $lvdate;
                
                // Step 1: Create a DateTime object from your string string
                $lvDateObj = date_create($lvdate); 
                // Step 2: Format it using 'd-m-y' (a single 'y' gives the 2-digit year)
                $lvDateEBLBFormat = date_format($lvDateObj, "d-m-y"); 
                //echo $lvDateEBLBFormat; // Outputs: 24-07-26
                
                $no_days = (($consume_days > 0.5) ? 1 : 0.5);
                $consume_days = $consume_days - $no_days;

                //logFuncError(json_encode($no_days)," == no_days");
                //logFuncError(json_encode($consume_days)," == in loop consume_days");

                $prd = singRec("select CODE from EPT_BCS_PERIOD where '" . $lvdate . "' between FR_DATE and TO_DATE");
                //logFuncError(json_encode($prd)," == prd");
                
                $newId = executeQry("insert into EPT_bcs_emp_leaves(EMP_CODE,LVE_DATE_FR,LVE_DATE_TO,LVE_CODE, EMP_CODE_APRV,APRV_DATE,LVE_TYPE,ENCH_AMT,NO_DAYS,STATUS,STATUS_DATE, PRD_CODE,CHG_ON,CHG_BY,REMARKS,TRAN_ID_PAYR,NO_DAYS_ADV,APPL_DATE,AUTH_EMP, AUTH_EMP_ALTERNATE,TRAN_ID,LEAVE_STARTS,LEAVE_ENDS,START_TIME,END_TIME)
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

                    // logFuncError(json_encode($newId)," == newId");

                    $updtEPT_blb = executeQry("update EPT_bcs_leave_balance set
						cons_days=cons_days+'" . $no_days . "',
						bal_days=bal_days-'" . $no_days . "'
						where emp_code='" . trim($get_temp_leave['EMP_CODE']) . "' 
						and lve_code='" . trim($get_temp_leave['LVE_CODE']) . "'
						and '" . $lvdate . "' between eff_date and upto_date+1");    

                   
                    //logFuncError(json_encode($updtEPT_blb)," == updtEPT_blb");
                
            } else {
                // entry found -> stop, but don't overwrite last_valid_date
                break;
            }
        }
        
        //echo 
        $updtEPT_blbcnt = executeQry("update EPT_bcs_leave_balance set
								APPLY_COUNT=nvl(APPLY_COUNT,0)+1
								where emp_code='" . trim($get_temp_leave['EMP_CODE']) . "' 
								and lve_code='" . trim($get_temp_leave['LVE_CODE']) . "'
								and '" . $lvdate . "' between eff_date and upto_date+1");
        //logFuncError(json_encode($updtEPT_blbcnt)," == updtEPT_blbcnt");

        taskUpdate('C', $data["AUTH_REMARKS"], $data['TASK_ID']);

        $uptEPT_belt = executeQry("update EPT_BCS_EMP_LEAVES_TEMP set status='A' where id='" . $get_temp_leave['ID'] . "'");
        
        //logFuncError(json_encode($uptEPT_belt)," == uptEPT_belt");
		
        $mailBody = 'Hi ' . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['EMP_CODE']))) . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['ID']))) . '<br><br>' . ucwords(strtolower(getEmpInfoByCode($empCode))) . ' has approved your leave request, the details are as follows. 
        <br>
            <br><br>
                <b>  Leave from  Date:</b> ' . $get_temp_leave['LVE_DATE_FR'] . '<br>
                <b>  Leave to Date: </b> ' . $get_temp_leave['LVE_DATE_TO'] . ' <br>
                <b>  Total Days: </b> ' . $get_temp_leave['TOTAL_DAYS'] . ' <br>
                <b>  Leave Type:</b> ' . $get_temp_leave['LVE_CODE'] . ' <br>
                <b>  Reason:</b> ' . $get_temp_leave['REASON'] . ' <br>
            <br><br> Regards,<br> Admin';

        $maild = executeQry("INSERT INTO EPT_bcs_mailbox_epp(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS, CHG_ON,CHG_BY,MAIL_DESCR) values(null,'Leave APPROVED of " . getEmpInfoByCode($get_temp_leave['EMP_CODE']) . " from " . $get_temp_leave['LVE_DATE_FR'] . " to " . $get_temp_leave['LVE_DATE_TO'] . "', '" . trim($mailBody) . "',null,'N',SYSDATE,'" . $get_temp_leave['EMP_CODE'] . "','Leave') returning ID into :mid", 'mid');

		$uptept_bmed= executeQry("INSERT INTO EPT_bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'" . $maild . "', '" . strtolower($empmail_self['COM_EMAIL']) . " ','attendance@sdlindia.com',null)"); 
        //logFuncError(json_encode($uptept_bmed)," == uptept_bmed");   

        
        //endQry('Task Approved');
        endQry();
        
        apiResponse(true,"Record Authroized successfully");
    }
}

ob_end_flush();
