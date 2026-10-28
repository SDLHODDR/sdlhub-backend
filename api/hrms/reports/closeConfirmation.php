<?php

try {
    if (isset($data['CloseConfirmInt']) && $data['CloseConfirmInt'] == true){
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
                EMP_CODE = '" . $data['EMP_CODE'] . "' AND SYSDATE 
                BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, '01-Mar-3000'))");

        $tenure_chg = singRec("SELECT ID, ETYPE_ID, CURR_CTC, PROP_CTC FROM HR_EMP_TENURE_CHANGE 
        WHERE EMP_CODE = '".$data['EMP_CODE']."' AND STATUS = 'T'");

        if($tenure_chg['CURR_CTC'] == $tenure_chg['PROP_CTC']){
            $tenureEffDate = singRec("SELECT * FROM HR_EMP_TENURE_CHANGE WHERE ID = '".$tenure_chg['ID']."'");
            $tnu = singRec("SELECT * FROM (
              SELECT * FROM HR_EMP_TENURE WHERE EMP_CODE = '".$data['EMP_CODE']."' ORDER BY ID DESC)
              WHERE ROWNUM = 1");

            $eff_period = trim($tenureEffDate['ETYPE_PERIOD']);
            $effFrom = $data['EFF_FROM'];
            $effToAd = date('d-M-y', strtotime($effFrom . ' -1 day'));
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
                    'where' => array('EMP_CODE' => trim($data['emp_code'])),
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
                    'CHG_ON' => SYSDATE,
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
                $data['EMP_CODE'],
                'Print CTC Change Letter for ' . getEmpInfoByCode($data['EMP_CODE']),
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
    
                $tran_desc = 'Leave Credit for '. getEmpInfoByCode($data['EMP_CODE']);
            
                $leaveTaskId = execQry( array(
                    'type' => 'insert', 
                    'table' => 'HR_BCS_USER_TASKS',
                    'data' => array(
                        'TASK_ID' => '373',
                        'CREATED_ON' => SYSDATE,  
                        'EXPIRE_ON' => SYSDATE + 2,
                        'STATUS' => 'O',
                        'TRAN_CODE' => $data['EMP_CODE'],
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
                $data['EMP_CODE'],
                'CTC Change Letter for ' . getEmpInfoByCode($data['EMP_CODE']),
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

        taskUpdateHR('C', '', $data['TASK_ID'], null, null);
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
