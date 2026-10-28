<?php

try {
    if (isset($data['closeConfirmInt']) && $data['closeConfirmInt'] == true){
        startQry();
		$employeeCode = trim((string)($data['EMP_CODE'] ?? ''));
		$effectiveFrom = trim((string)($data['EFF_FROM'] ?? ''));
		$workflowTaskId = trim((string)($data['TASK_ID'] ?? ''));
		$tenureChangeId = trim((string)($data['tenure_change_id'] ?? $data['TENURE_CHANGE_ID'] ?? ''));
		$userTaskId = trim((string)($data['user_task_id'] ?? $data['USER_TASK_ID'] ?? ''));
		if ($employeeCode === '' || $effectiveFrom === '' || $workflowTaskId === '' || $tenureChangeId === '') {
			throw new InvalidArgumentException('Employee code, tenure change ID, effective date, and workflow task ID are required.');
		}
		$effectiveDate = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
		if (!$effectiveDate || $effectiveDate->format('Y-m-d') !== $effectiveFrom) {
			throw new InvalidArgumentException('Effective date must use YYYY-MM-DD format.');
		}
        
        $jd_id = singRec("SELECT * FROM HR_ORGANOGRAM
        WHERE ID IN (
            SELECT ORG_ID FROM HR_EMP_OFFICE_DET WHERE
                EMP_CODE = :employee_code AND SYSDATE
                BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01'))", [
			':employee_code' => $employeeCode
		]);
		if (empty($jd_id['COMPANY']) || empty($jd_id['DIVSN_ID']) || empty($jd_id['DEPT_ID'])) {
			throw new RuntimeException('Could not resolve the employee\'s company, division, and department.');
		}
		$taskConditions = "TASK_ID = :task_id
			AND TRAN_CODE = :employee_code
			AND EMP_CODE_FOR = :approver_code
			AND STATUS = 'O'";
		$taskBinds = [
			':task_id' => $workflowTaskId,
			':employee_code' => $employeeCode,
			':approver_code' => $empCode
		];
		if ($userTaskId !== '') {
			$taskConditions .= ' AND ID = :user_task_id';
			$taskBinds[':user_task_id'] = $userTaskId;
		}
		$openTasks = multiRec(
			"SELECT ID FROM HR_USER_TASKS WHERE {$taskConditions} FOR UPDATE NOWAIT",
			$taskBinds
		);
		if (count($openTasks) !== 1 || empty($openTasks[0]['ID'])) {
			throw new RuntimeException('The selected manager task is unavailable or already being processed. Refresh the task and try again.');
		}
		$userTaskId = $openTasks[0]['ID'];

		$tenure_chg = singRec("SELECT ID, ETYPE_ID, CURR_CTC, PROP_CTC FROM HR_EMP_TENURE_CHANGE
		WHERE ID = :tenure_change_id AND EMP_CODE = :employee_code AND STATUS = 'T'", [
			':tenure_change_id' => $tenureChangeId,
			':employee_code' => $employeeCode
		]);
		if (empty($tenure_chg['ID'])) {
			throw new RuntimeException('The submitted tenure change was not found or has already been processed. Refresh the task and try again.');
		}

        if(is_numeric($tenure_chg['CURR_CTC'] ?? null)
			&& is_numeric($tenure_chg['PROP_CTC'] ?? null)
			&& (float)$tenure_chg['CURR_CTC'] == (float)$tenure_chg['PROP_CTC']){
            $tenureEffDate = singRec("SELECT * FROM HR_EMP_TENURE_CHANGE WHERE ID = :tenure_id", [
				':tenure_id' => $tenure_chg['ID']
			]);
			if (empty($tenureEffDate['ID'])) {
				throw new RuntimeException('Could not load the submitted tenure details.');
			}
			$tnu = singRec("SELECT * FROM (
			  SELECT * FROM HR_EMP_TENURE WHERE EMP_CODE = :employee_code ORDER BY ID DESC)
			  WHERE ROWNUM = 1", [':employee_code' => $employeeCode]);
			if (empty($tnu['ID'])) {
				throw new RuntimeException('Could not find the current tenure row for employee ' . $employeeCode . '.');
			}

			$eff_period = trim((string)($tenureEffDate['ETYPE_PERIOD'] ?? ''));
			$effToAd = $effectiveDate->modify('-1 day')->format('Y-m-d');
            if(!empty($eff_period)){
                $effToDate = $effectiveDate->modify('+' . (int)$eff_period . ' months');
				$eff_to = "TO_DATE('" . $effToDate->format('Y-m-d') . "', 'YYYY-MM-DD')";
            }else{
                $eff_to = '';
            }
			$effFromOracle = "TO_DATE('" . $effectiveDate->format('Y-m-d') . "', 'YYYY-MM-DD')";
			$effToOracle = "TO_DATE('" . $effToAd . "', 'YYYY-MM-DD')";
            
            $updatedTenure = execQry(
                array(
                    'type' => 'update', 'table' => 'HR_EMP_TENURE',
                    'data' => array(
                        'EFF_TO' => $effToOracle,
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'where' => array('ID' => trim($tnu['ID'])),
                    'return' => '',
                    'print' => 0
                )
            );
			if ($updatedTenure === false) {
				throw new RuntimeException('Could not close the employee\'s current tenure period.');
			}
        
            $updatedEmployee = execQry(
                array(
                    'type' => 'update', 'table' => 'HR_BCS_EMPLOYEE',
                    'data' => array(
                        'EMP_TYPE' => $tenureEffDate['ETYPE_ID'],
                        'DATE_CONF' => $effFromOracle,
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'where' => array('EMP_CODE' => trim($data['EMP_CODE'])),
                    'return' => '',
                    'print' => 0
                )
            );
			if ($updatedEmployee === false) {
				throw new RuntimeException('Could not update the employee type and confirmation date.');
			}
        
            $insertedTenure = execQry(array(
                'type' => 'insert', 'table' => 'HR_EMP_TENURE',
                'data' => array(
                    'ID' => '',
                    'EMP_ID' => trim($tnu['EMP_ID']),
                    'EMP_CODE' => trim($tenureEffDate['EMP_CODE']),
                    'ETYPE_ID' => trim($tenureEffDate['ETYPE_ID']),
                    'ETYPE_PERIOD' => trim($eff_period),
                    'EFF_FROM' => $effFromOracle,
                    'EFF_TO' => $eff_to,
                    'CHG_ON' => 'SYSDATE',
                    'CHG_BY' => trim($empCode)
                ),
                'return' => '',
                'print' => 0
            ));
			if ($insertedTenure === false) {
				throw new RuntimeException('Could not create the employee\'s new tenure period.');
			}

			$employeeName = getEmpInfoByCode($employeeCode);

            generateTaskHR(
                'PRT_TNU_CHG_LTR',
                $employeeCode,
                'Print CTC Change Letter for ' . $employeeName,
                '',
                'A',
                'O',
                '',
                $empCode,
                $jd_id['COMPANY'],
                $jd_id['DIVSN_ID'],
                $jd_id['DEPT_ID']
            );
            if($tenure_chg['ETYPE_ID'] == '1' || $tenure_chg['ETYPE_ID'] == '2'){
				$sessionEmployee = singRec(
					"SELECT WORK_SITE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :emp_code",
					[':emp_code' => $empCode]
				);
				$workSite = trim((string)($sessionEmployee['WORK_SITE'] ?? ''));
				if ($workSite === '') {
					throw new RuntimeException('Could not determine the submitting employee\'s work site.');
				}

				$tran_desc = 'Leave Credit for ' . $employeeName;
            
                $leaveTaskId = execQry( array(
                    'type' => 'insert', 
                    'table' => 'HR_BCS_USER_TASKS',
                    'data' => array(
                        'TASK_ID' => '373',
                        'CREATED_ON' => 'SYSDATE',  
                        'EXPIRE_ON' => 'SYSDATE + 2',
                        'STATUS' => 'O',
                        'TRAN_CODE' => $employeeCode,
                        'TASK_TYPE' => 'A',
                        'TRAN_DESC' => $tran_desc,
                        'SITE_CODE' => $workSite,
                        'CREATED_BY' => $empCode,  
                    ),
                    'print' => 0,
                    'return' => 'ID'
                ));
				if ($leaveTaskId === false || $leaveTaskId === null || $leaveTaskId === '') {
					throw new RuntimeException('Could not create the employee leave-credit task.');
				}
            }
        } else {
            generateTaskHR(
                'TNU_CHG_LTR',
                $employeeCode,
                'CTC Change Letter for ' . getEmpInfoByCode($employeeCode),
                '',
                'A',
                'O',
                '',
                $empCode,
                $jd_id['COMPANY'],
                $jd_id['DIVSN_ID'],
                $jd_id['DEPT_ID']
            );
        }

		taskUpdateHR('C', '', $userTaskId, null, null);
		if (($GLOBALS['qry_____result'] ?? 0) == 1) {
			throw new RuntimeException('Could not close the current manager task.');
		}
        endQry();
        apiResponse(true, "Manager Task Closed and New Task generate for further processing", null, 200);
        
    } else {
        apiResponse(false, "Form data is empty.", null, 200);       
    }
} catch (Throwable $e) {
    logOracleError($e);
	if (!empty($sql___func___con)) {
		oci_rollback($sql___func___con);
	}

    apiResponse(false, "Unable to generate Task.33: " . $e->getMessage(), null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
