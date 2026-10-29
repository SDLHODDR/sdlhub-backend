<?php

try {
    if (isset($data['generateTenure']) && $data['generateTenure'] == true) {
        $employeeCode = trim((string) ($data['EMP_CODE'] ?? ''));
        $managerCode = trim((string) ($data['MGR_CODE'] ?? ''));
        if ($employeeCode === '') {
            throw new InvalidArgumentException('Employee code is required to generate a tenure task.');
        }
        if ($managerCode === '') {
            throw new InvalidArgumentException('Manager code is required to generate a tenure task.');
        }
        $data['EMP_CODE'] = $employeeCode;
        $data['MGR_CODE'] = $managerCode;

        startQry();
        $ctc = singRec("SELECT GET_TOTAL_CTC(:employee_code, SYSDATE) VAL FROM DUAL", [':employee_code' => $employeeCode]);
        $empDet = singRec("SELECT HRC.COMP_ID, HEOD.DIVSN_ID, HEOD.DEPT_ID
            FROM HR_EMP_OFFICE_DET HEOD
            INNER JOIN HR_ORGANOGRAM HRO ON HEOD.ORG_ID = HRO.ID
            INNER JOIN HR_COMPANY HRC ON HRO.COMPANY = HRC.COMP_ID
            WHERE HEOD.EMP_CODE = :employee_code
              AND SYSDATE BETWEEN HEOD.EFFEC_FROM AND NVL(HEOD.EFFEC_TO, DATE '3000-03-01')",
            [':employee_code' => $employeeCode]);
        if (empty($empDet['COMP_ID'])) {
            throw new RuntimeException('Could not resolve company, division, and department for employee ' . $employeeCode . '.');
        }

        $manager = singRec(
            'SELECT EMP_CODE FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :manager_code',
            [':manager_code' => $managerCode]
        );
        if (empty($manager['EMP_CODE'])) {
            throw new RuntimeException('Manager ' . $managerCode . ' was not found in HR employee records.');
        }

        $checkTenure = singRec("SELECT ID, EMP_CODE, STATUS FROM HR_EMP_TENURE_CHANGE WHERE EMP_CODE = :employee_code AND STATUS = 'N'", [':employee_code' => $employeeCode]);

        if ($checkTenure && (isset($checkTenure['EMP_CODE']) && $checkTenure['EMP_CODE'] === $data['EMP_CODE'])) {
            apiResponse(false, 'Teneure Change already exists. ', null, 200);
            exit;
        } else {
            $tnu_id = execQry(array(
                'type' => 'insert',
                'table' => 'HR_EMP_TENURE_CHANGE',
                'data' => array(
                    'ID' => '',
                    'EMP_CODE' => $data['EMP_CODE'],
                    'CURR_CTC' => $ctc['VAL'],
                    'STATUS' => 'N',
                    'CHG_ON' => 'SYSDATE',
                    'CHG_BY' => trim($empCode)
                ),
                'return' => 'ID',
                'print' => 0
            ));
            if ($tnu_id === false || $tnu_id === null || $tnu_id === '') {
                throw new RuntimeException('Could not create the tenure change record for employee ' . $employeeCode . '.');
            }

            $task_id = generateTaskHR(
                'TNU_CHG',
                $data['EMP_CODE'],
                'Confirmation/Tenure change of ' . getEmpInfoByCode($data['EMP_CODE']),
                '',
                'A',
                'O',
                $managerCode,
                $empCode,
                $empDet['COMP_ID'],
                $empDet['DIVSN_ID'],
                $empDet['DEPT_ID']
            );
            // $emp_mgr_id = execQry(
            //     array(
            //         'type' => 'insert',
            //         'table' => 'HR_EMP_TENURE_MGR',
            //         'data' => array(
            //             'ID' => null,
            //             'TENURE_CHG_ID' => $tnu_id,
            //             'APPR_LEVEL' => 1,
            //             'EMP_CODE' => $data['EMP_CODE'],
            //             'EMP_CODE_APPR' => $data['MGR_CODE'],
            //             'ASON_DATE' => 'SYSDATE',
            //             'STATUS' => 'I',
            //             'TASK_ID' => $task_id,
            //             'CHG_BY' => $empCode,
            //             'CHG_ON' => 'SYSDATE'
            //         ),
            //         'return' => 'ID',
            //         'print' => 0
            //     )
            // ); //Need to confirm with Tamankar sir/Savita madam for flow then uncomment

            endQry();
            apiResponse(true, 'Tenure Change Task generated successfully. ', [
                'tenureId' => $tnu_id,
                'task_id' => $task_id
            ]);
        }
    } else {
        apiResponse(false, 'Form data is empty.', null, 200);
    }
} catch (Throwable $e) {
    logOracleError($e);
    if (!empty($sql___func___con)) {
        oci_rollback($sql___func___con);
    }
    apiResponse(false, $e->getMessage(), null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
