<?php

	function getEmpInfoByCode($id)
	{
		$EmpName = singRec("SELECT EMP_FNAME, EMP_LNAME 
								FROM epplive.bcs_employee
								WHERE emp_code = '" . $id . "'");
		$name = $EmpName['EMP_FNAME'] . ' ' . $EmpName['EMP_LNAME'];
		return ucwords(strtolower($name));
	}

	function findParentOrgEmp($empCode)
	{
		$office = singRec("SELECT  ORG_ID,ORG_LOC_ID FROM 
			(SELECT ORG_ID ,ORG_LOC_ID FROM HRMSLIVE.HR_EMP_OFFICE_DET 
				WHERE emp_code = '".$empCode."' order by EFFEC_TO desc 
			) WHERE ROWNUM=1");

		$report = singRec("SELECT PARENT_ORGID , PARENT_LOCID from hrmslive.hr_org_loc_parent 
						WHERE org_id = '".$office['ORG_ID']."' and 
						SYSDATE between effec_from and nvl(effec_to , '01-Mar-3000') 
						and ORG_LOC_ID ='".$office['ORG_LOC_ID']."'");

		$orgEmp = singRec("SELECT HO.EMP_CODE FROM hrmslive.HR_EMPLOYEE_INFO HO
					INNER JOIN hrmslive.HR_EMP_OFFICE_DET HD ON HD.EMP_CODE = HO.EMP_CODE
					WHERE HD.ORG_ID ='".$report['PARENT_ORGID']."'
					AND HD.ORG_LOC_ID ='".$report['PARENT_LOCID']."' AND HO.STATUS='A'");

		return $orgEmp['EMP_CODE'];
	}
	function getbtwn_twodate($fromDate, $toDate, $format = 'd-M-Y')
	{
		$dates = array();
		$current = strtotime($fromDate);
		$date2 = strtotime($toDate);
		$stepVal = '+1 day';
		while ($current <= $date2) {
			$dates[] = date($format, $current);
			$current = strtotime($stepVal, $current);
		}
		return $dates;
	}

	function generateTask(
		$task_grp = null,
		$tran_code = null,
		$tran_desc = null,
		$user = null,
		$status = null,
		$remarks = null,
		$ref_task_id = null,
		$emp_code_for = null,
		$created_on = null,
		$task_grp_desc = null,
		$siteCode = null,
		$udf1 = null,
		$udf2 = null
	) {
		if (empty($user)) {
			$user = $_SESSION['emp_code']; //$_SESSION['ept']['eptuserId'];
		}
		if (empty($status)) {
			$status = 'O';
		}
		$tsk = singRec("SELECT * FROM EPT_TASK_MASTER 
						WHERE TASK_GRP = '" . $task_grp . "'");
		if ($tsk['TASK_TYPE'] == 'N') //Notifiaction
		{
			$taskAccess = multiRec("SELECT u.EMP_CODE 
									FROM EPT_PROFILE_TASK pt 
									INNER JOIN BCS_USER_RIGHTS r on pt.PROFILE_ID=r.PROFILE_ID 
									INNER JOIN BCS_USERS u on r.USER_CODE=u.CODE
									WHERE pt.TASK_ID ='" . $tsk['ID'] . "' 
									AND r.STATUS='A' and u.STATUS='A'
									AND u.code <> '" . $user . "'");
			$newId = null;
			$newIdArr = null;
			foreach ($taskAccess as $u) {
				$userNoti = singRec("SELECT ID FROM ept_user_tasks 
									WHERE task_id='" . $tsk['ID'] . "'
									AND upper(tran_code)='" . strtoupper($tran_code) . "' ");
				if ($userNoti['ID']) {
					$newId = executeQry("insert into EPT_USER_TASKS(ID, TASK_ID, 
																	CREATED_ON, CREATED_BY, 
																	EXPIRE_ON, STATUS,TRAN_CODE,
																	TASK_TYPE,TRAN_DESC,TASK_GRP_DESC,
																	SITE_CODE,REMARKS,REF_TASK_ID,
																	EMP_CODE_FOR)
															values(Null,
																	'" . trim(strtoupper($tsk['ID'])) . "',
																	nvl('" . $created_on . "',SYSDATE),
																	'" . trim(strtoupper($user)) . "',
																	to_date(nvl('" . $created_on . "',SYSDATE)) + " . $tsk['EXPIRY_DAYS'] . ",
																	'" . $status . "',
																	'" . trim(strtoupper($tran_code)) . "',
																	'A',
																	'" . trim(strtoupper(substr($tran_desc, 0, 120))) . "',
																	'" . trim(strtoupper($task_grp_desc)) . "',
																	'" . ($siteCode ? $siteCode : $_SESSION['ept']['eptSiteCode']) . "',
																	'" . $remarks . "',
																	'" . $ref_task_id . "',
																	'" . $u['EMP_CODE'] . "')returning ID into :newId", 'newId');
					execQry(array(
						'type' => 'insert', 'table' => 'EPT_USER_TASKS_LOG',
						'data' => array(
							'USER_TASKID' => $newId,
							'TASK_ID' => $tsk['ID'], 'TRAN_CODE' => trim(strtoupper($tran_code)),
							'SITE_CODE' => ($siteCode ? $siteCode : $_SESSION['ept']['eptSiteCode']),
							'STATUS' => $status, 'REMARKS' => $remarks,
							'EMP_CODE_FOR' => $u['EMP_CODE'], 'IP_ADDR' => $_SERVER['REMOTE_ADDR'],
							'CHG_ON' => 'SYSDATE', 'CHG_BY' => $_SESSION['ept']['eptuserId']
						), 'return' => '',
						'print' => 0
					));
					$newIdArr[] = $newId;
				}
			}
			return $newIdArr;
		} else  //Task 
		{

			$res = singRec("SELECT a.ID , a.task_id
							FROM EPT_USER_TASKS a,ept_task_master b
							WHERE b.task_grp='" . $task_grp . "' 
							AND a.tran_code='" . strtoupper($tran_code) . "'
							AND b.id=a.task_id 
							AND a.SITE_CODE = '" . ($siteCode ? $siteCode : $_SESSION['ept']['eptSiteCode']) . "'");
			if ($res['ID'] && $res['TASK_ID'] != '7') {
				$newId = executeQry("UPDATE EPT_USER_TASKS 
									SET CREATED_ON=nvl('" . $created_on . "',SYSDATE), 
										CREATED_BY='" . trim(strtoupper($user)) . "',
										EXPIRE_ON=to_date(nvl('" . $created_on . "',SYSDATE)) + " . $tsk['EXPIRY_DAYS'] . ", 
										STATUS='" . $status . "',
										TRAN_DESC='" . trim(strtoupper($tran_desc)) . "',
										REF_TASK_ID='" . $ref_task_id . "',
										EMP_CODE_FOR='" . $emp_code_for . "',REMARKS='" . $remarks . "'
									WHERE id='" . $res['ID'] . "'
									returning ID into :newId", 'newId');
				execQry(
					array(
						'type' => 'insert', 'table' => 'EPT_USER_TASKS_LOG',
						'data' => array(
							'USER_TASKID' => $newId, 'TASK_ID' => $tsk['ID'], 'TRAN_CODE' => trim(strtoupper($tran_code)),
							'SITE_CODE' => ($siteCode ? $siteCode : $_SESSION['ept']['eptSiteCode']), 'STATUS' => $status, 'REMARKS' => $remarks,
							'EMP_CODE_FOR' => $emp_code_for, 'IP_ADDR' => $_SERVER['REMOTE_ADDR'],
							'CHG_ON' => 'SYSDATE', 'CHG_BY' => $_SESSION['ept']['eptuserId']
						),
						'return' => '',
						'print' => 0
					)
				);
			} else {
				$newId = executeQry("insert into EPT_USER_TASKS
										(ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, 
										TRAN_CODE,TASK_TYPE,TRAN_DESC,TASK_GRP_DESC,SITE_CODE,REMARKS,REF_TASK_ID,EMP_CODE_FOR, UDF_1, UDF_2)
										values(
										Null,'" . trim(strtoupper($tsk['ID'])) . "',
										nvl('" . $created_on . "',SYSDATE),
										'" . trim(strtoupper($user)) . "',
										to_date(nvl('" . $created_on . "',SYSDATE)) + " . $tsk['EXPIRY_DAYS'] . ",
										'" . $status . "',
										'" . trim(strtoupper($tran_code)) . "',
										'A',
										'" . trim(strtoupper($tran_desc)) . "',
										'" . trim(strtoupper($task_grp_desc)) . "',
										'" . ($siteCode ? $siteCode : $_SESSION['ept']['eptSiteCode']) . "',
										'" . $remarks . "',
										'" . $ref_task_id . "',
										'" . $emp_code_for . "',
										'" . $udf1 . "',
										'" . $udf2 . "'
										) 
										returning ID into :newId", 'newId');
				execQry(
					array(
						'type' => 'insert', 'table' => 'EPT_USER_TASKS_LOG',
						'data' => array(
							'USER_TASKID' => $newId, 'TASK_ID' => $tsk['ID'], 'TRAN_CODE' => trim(strtoupper($tran_code)),
							'SITE_CODE' => ($siteCode ? $siteCode : $_SESSION['ept']['eptSiteCode']), 'STATUS' => $status, 'REMARKS' => $remarks,
							'EMP_CODE_FOR' => $emp_code_for, 'IP_ADDR' => $_SERVER['REMOTE_ADDR'],
							'CHG_ON' => 'SYSDATE', 'CHG_BY' => $_SESSION['ept']['eptuserId']
						),
						'return' => '',
						'print' => 0
					)
				);
			}
			return $newId;
		}
	}

	// function arr($request = null)
	// {
	// #requestDecoder();
	// echo '<pre>';
	// if (empty($request))
	// 	print_r($_REQUEST);
	// else
	// 	print_r($request);
	// echo '</pre>';
	// }
?>
