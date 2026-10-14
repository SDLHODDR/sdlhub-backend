<?php

try {
    if (isset($data['closeTenureCTCTask']) && $data['closeTenureCTCTask'] == true){
        startQry();
        $jd_id = singRec("SELECT * FROM HR_ORGANOGRAM WHERE ID IN (
            SELECT ORG_ID FROM HR_EMP_OFFICE_DET WHERE
                EMP_CODE ='" . $data['EMP_CODE'] . "' AND SYSDATE 
                BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, '01-Mar-3000'))");
        
        foreach($data['AMOUNT_MNTHLY_VAR'] as $key => $val){
            $ctcpf =  execQry(
                array(
                    'type' => 'update', 'table' => 'HR_EMP_CTC_HEADS',
                    'data' => array(
                        'STATUS' => 'T',
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'where' => array(
                        'REF_ID' => $data['TENURE_CHG_ID'],
                        'AD_CODE' => $key,
                        'SALARY_TYPE' => 'A'
                    ),
                    'print' => 0
                )
            );
        }
        
        foreach ($data['AMOUNT_MNTHLY'] as $adcode => $amt) {
            $ctcid =  execQry(
                array(
                    'type' => 'update', 'table' => 'HR_EMP_CTC_HEADS',
                    'data' => array(
                        'STATUS' => 'T',
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'where' => array(
                        'REF_ID' => $data['TENURE_CHG_ID'],
                        'AD_CODE' => $adcode,
                        'SALARY_TYPE' => 'A'
                    ),
                    'print' => 0
                )
            );
        }

        generateTask(
            'AUTH_TNU_CHG_LTR',
            $data['EMP_CODE'],
            'CTC Approval ' . getEmpInfoByCode($data['EMP_CODE']),
            '',
            'A',
            'O',
            '',
            $empCode,
            $jd_id['COMPANY'],
            $jd_id['DIVSN_ID'],
            $jd_id['DEPT_ID']
        );
        taskUpdateHR('C', '', $data['TASK_ID'], null, null);
        endQry();
        apiResponse(true, "Task Closed and New Task generate for further processing", null, 200);
    } else {
        apiResponse(false, "Form data is empty.", null, 200);       
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "closeConfirmation.php"
    );

    apiResponse(false, "Unable to generate Task.33", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
