<?php

require_once __DIR__ . '/tenureApply.php';

try {
    if (isset($data['CloseConfirmInt']) && $data['CloseConfirmInt'] == true){
        startQry();
		$employeeCode = trim((string)($data['EMP_CODE'] ?? ''));
		$effectiveFrom = trim((string)($data['EFF_FROM'] ?? ''));
		$workflowTaskId = trim((string)($data['TASK_ID'] ?? ''));
		$tenureChangeId = trim((string)($data['tenure_change_id'] ?? $data['TENURE_CHANGE_ID'] ?? ''));
		$userTaskId = trim((string)($data['user_task_id'] ?? $data['USER_TASK_ID'] ?? ''));
		if ($employeeCode === '' || $effectiveFrom === '' || $workflowTaskId === '' || $tenureChangeId === '' || $userTaskId === '') {
			throw new InvalidArgumentException('Employee code, tenure change ID, effective date, workflow task ID, and user task ID are required.');
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
        WHERE EMP_CODE = '".$data['EMP_CODE']."' AND STATUS = 'T' ORDER BY ID DESC");

        if (empty($tenure_chg['ID'])) {
            throw new RuntimeException('The saved tenure change record was not found.');
        }

        if($tenure_chg['CURR_CTC'] == $tenure_chg['PROP_CTC']){
            tenureApplyChange($tenure_chg['ID'], $employeeCode, $empCode);

            // No CTC change: approved here, as the final CTC approval does otherwise
            // (the print-letter step closes only approved 'A' records).
            $approved = execQry([
                'type' => 'update', 'table' => 'HR_EMP_TENURE_CHANGE',
                'data' => [
                    'STATUS' => 'A',
                    'AUTH_ON' => 'SYSDATE',
                    'AUTH_BY' => $empCode,
                    'CHG_ON' => 'SYSDATE',
                    'CHG_BY' => $empCode,
                ],
                'where' => ['ID' => (string)$tenure_chg['ID']],
                'print' => 0,
            ]);
            if ($approved === false) {
                throw new RuntimeException('Could not approve the tenure change.');
            }

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

            tenureCreateLeaveCreditTask($tenure_chg['ETYPE_ID'], $employeeCode, getEmpInfoByCode($employeeCode), $empCode);
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

        // Close the HR_USER_TASKS row (legacy TASK_ID held this row ID, not the workflow task ID).
        taskUpdateHR('C', '', $userTaskId, null, null);
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
