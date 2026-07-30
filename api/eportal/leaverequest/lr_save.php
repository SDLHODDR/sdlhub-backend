<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "lr_head.php";


if($data['saveLrData']==true)
{
	try {
		startQry();
	
		$name = singRec("SELECT ept_hr_get_emp_mgr('".$data['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
		$name1 = findParentOrgEmp($data['EMP_CODE']);        
		$Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;

		$manageremail = singRec("select EMAIL_ID_OFF as COM_EMAIL from ept_bcs_employee WHERE emp_code = '".$Manager."'");

		$empData = singRec("select DEPT_CODE,PROC_GROUP PROC_GRP from ept_bcs_employee where emp_code='" . $data['EMP_CODE'] . "' ");

		$sql = multiRec("select emp_code from ept_bcs_emp_leaves_temp where emp_code='" . $data['EMP_CODE'] . "'  and status not in ('X' , 'R')  and
					(lve_date_fr between to_date('" . $data['LVE_DATE_FR'] . "','YYYY-MM-DD') and to_date('" . $data['LVE_DATE_TO'] . "','YYYY-MM-DD') or
					lve_date_to between to_date('" . $data['LVE_DATE_FR'] . "','YYYY-MM-DD') and to_date('" . $data['LVE_DATE_TO'] . "','YYYY-MM-DD') or
					to_date('" . $data['LVE_DATE_FR'] . "','YYYY-MM-DD') between lve_date_fr and lve_date_to) 

					union
					select emp_code from ept_bcs_emp_leaves where emp_code='" . $data['EMP_CODE'] . "'  and
					(lve_date_fr between to_date('" . $data['LVE_DATE_FR'] . "','YYYY-MM-DD') and to_date('" . $data['LVE_DATE_TO'] . "','YYYY-MM-DD') or
					lve_date_to between to_date('" . $data['LVE_DATE_FR'] . "','YYYY-MM-DD') and to_date('" . $data['LVE_DATE_TO'] . "','YYYY-MM-DD') or
					to_date('" . $data['LVE_DATE_FR'] . "','YYYY-MM-DD') between lve_date_fr and lve_date_to) 
					");
		$cnt = count($sql);
		
		if ($cnt > 0) {
			$msg = 'Leave already available for this period';
			endQry();
			echo json_encode([
				"status" => false,
				"status_code" => 200,
				"message" => "Leave already available for this period"
			]);
			exit;
		}
		
		// First day of the month.
		$first_day = date('01-M-Y', strtotime($data['LVE_DATE_FR']));
		$LVE_DATE_FR = date('d-M-Y', strtotime($data['LVE_DATE_FR']));
		//to_date('" . $data["LVE_DATE_FR"] . "','DD-MON-YYYY')
		// Last day of the month.
		$last_day =  date('t-M-Y', strtotime($data['LVE_DATE_TO']));
		$LVE_DATE_TO = date('d-M-Y', strtotime($data['LVE_DATE_TO']));

		// $applied_in_month = singRec("select  nvl(sum(applied_days),0) applied_in_month
		// 				  from (
		// 				  SELECT SUM(NO_DAYS) applied_days FROM EPT_BCS_EMP_LEAVES WHERE EMP_CODE = '" . $data['EMP_CODE'] . "' AND LVE_DATE_FR >= '" . $first_day . "' AND LVE_DATE_TO <= '" . $last_day . "' AND LVE_CODE='" . $data['LVE_CODE'] . "' AND STATUS = 'C'
		// 				  UNION 
		// 				  SELECT  SUM(TOTAL_DAYS) FROM EPT_BCS_EMP_LEAVES_TEMP WHERE EMP_CODE = '" . $data['EMP_CODE'] . "' AND LVE_DATE_FR >= '" . $first_day . "' AND LVE_DATE_TO <= '" . $last_day . "' AND LVE_CODE='" . $data['LVE_CODE'] . "' )");

		$insert_id = executeQry("insert into EPT_bcs_emp_leaves_temp
			(EMP_CODE,LVE_DATE_FR,LVE_DATE_TO,LVE_START_ON,LVE_END_ON,LVE_CODE,TOTAL_DAYS,REASON,CHG_BY,CHG_ON,APRVR_ID,RAISED_BY,STATUS)
			values
			( 
			'" . trim($data['EMP_CODE']) . "',
			'" . trim($LVE_DATE_FR) . "',
			'" . trim($LVE_DATE_TO) . "',
			'" . trim($data['LEAVE_STARTS']) . "',
			'" . trim($data['LEAVE_ENDS']) . "',
			'" . trim($data['LVE_CODE']) . "',
			'" . trim($data['NO_DAYS']) . "',
			'" . trim($data['REASON']) . "',
			'" . trim($_SESSION['eptPrimaryId']) . "',
			SYSDATE,
			'" . $Manager . "' , '" . $empCode . "','T'
		) returning ID into :newId", 'newId');

		if ($insert_id) {
		$get_temp_leave = singRec("SELECT * from EPT_BCS_EMP_LEAVES_TEMP where id = '" . $insert_id . "' ");
		$empmail_self = singRec("select com_email from EPT_HR_EMPLOYEE_INFO where emp_code='" . $get_temp_leave['EMP_CODE'] . "' ");
		$task_id = generateTask('leave_application', $insert_id, getEmpInfoByCode($data['EMP_CODE']) . " (" . trim(strtoupper($data['LVE_DATE_FR'])) . " TO " . trim(strtoupper($data['LVE_DATE_TO'])) . ")", '', '', '', '', $Manager);
			
			// 
			
			if($manageremail['COM_EMAIL']!='rap@sdlindia.com'){

				$mailBody = 'Hi, ' . ucwords(strtolower(getEmpInfoByCode($get_temp_leave['EMP_CODE']))) . ' has sent a leave request, the details are as follows.
				<br><br>
						<b>  Leave from Date:</b> ' . $get_temp_leave['LVE_DATE_FR'] . '<br>
						<b>  Leave to Date: </b> ' . $get_temp_leave['LVE_DATE_TO'] . ' <br>
						<b>  Total Days: </b> ' . $get_temp_leave['TOTAL_DAYS'] . ' <br>
						<b>  Leave Type:</b> ' . $get_temp_leave['LVE_CODE'] . ' <br>
						<b>  Reason:</b> ' . $get_temp_leave['REASON'] . ' <br>
						<b>  Status:</b> <b>Pending Approval</b> <br><br>
				<br>Regards,<br> Admin ';
				// echo html_entity_decode($mailBody);
				// exit;
				$maild = executeQry("INSERT INTO EPT_bcs_mailbox_epp(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,
				CHG_ON,CHG_BY,MAIL_DESCR)
				values(null,'Leave Request of " . getEmpInfoByCode($get_temp_leave['EMP_CODE']) . " from 
				" . $get_temp_leave['LVE_DATE_FR'] . " to " . $get_temp_leave['LVE_DATE_TO'] . "',
				'" . trim($mailBody) . "',null,'N',SYSDATE,'" . $data['EMP_CODE'] . "','Leave') 
				returning ID into :mid", 'mid');

				executeQry("INSERT INTO EPT_bcs_mailbox_epp_details(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC)
							values(null,'" . $maild . "',
							'" . strtolower($manageremail['COM_EMAIL']) . " ',null,null)");

				$msg = 'Leave Added Successfully !!';
				endQry();
				apiResponse(
					true,
					"Leave Added successfully.",
					[
						"message" => $msg,
						"data" => $get_temp_leave
					]
				);
			} else {
				// endQry();
				$msg = "Save Failed !!";
				apiResponse(false, "Save Failed", null, 200);
			}
			
			
		} else {
			apiResponse(false, "Error Occured", null, 200);
		}

    } catch (Throwable $e) {
        logOracleError($e);
        apiResponse(false, "Unable to apply leaves.", null, 500);
    } finally {
        if ($sql___func___con) {
            oci_close($sql___func___con);
        }
    }
} 