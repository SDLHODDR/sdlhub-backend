<?php

try {
    if (isset($data['CloseConfirmInt']) && $data['CloseConfirmInt'] == true){
        startQry();
        
        $jd_id = singRec("SELECT * FROM HR_ORGANOGRAM
        WHERE ID IN (
            SELECT ORG_ID FROM HR_EMP_OFFICE_DET WHERE
                EMP_CODE = '" . $data['emp_code'] . "' AND SYSDATE 
                BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, '01-Mar-3000'))");

        $tenure_chg = singRec("SELECT ID, ETYPE_ID, CURR_CTC, PROP_CTC FROM HR_EMP_TENURE_CHANGE 
        WHERE EMP_CODE = '".$data['emp_code']."' AND STATUS = 'T'");

        if($tenure_chg['CURR_CTC'] == $tenure_chg['PROP_CTC']){
            $tenureEffDate = singRec("SELECT * FROM HR_EMP_TENURE_CHANGE WHERE ID = '".$tenure_chg['ID']."'");
            $tnu = singRec("SELECT * FROM (
              SELECT * FROM HR_EMP_TENURE WHERE EMP_CODE = '".$data['emp_code']."' ORDER BY ID DESC)
              WHERE ROWNUM = 1");

            $eff_period = trim($tenureEffDate['ETYPE_PERIOD']);
            $effFrom = $data['effective_from'];
            $effToAd = date('d-M-y', strtotime($effFrom . ' -1 day'));
            if(!empty($eff_period)){
                $eff_to = date('d-M-Y', strtotime("$effFrom + $eff_period months"));
            }else{
                $eff_to = '';
            }
            
            execQry(
                array(
                    'type' => 'update', 'table' => 'HR_EMP_TENURE',
                    'data' => array(
                        'EFF_TO' => $effToAd,
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'where' => array('ID' => trim($tnu['ID'])),
                    'return' => '',
                    'print' => 0
                )
            );
        
            execQry(
                array(
                    'type' => 'update', 'table' => 'HR_BCS_EMPLOYEE',
                    'data' => array(
                        'EMP_TYPE' => $tenureEffDate['ETYPE_ID'],
                        'DATE_CONF' => $effFrom,
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'where' => array('EMP_CODE' => trim($data['emp_code'])),
                    'return' => '',
                    'print' => 0
                )
            );
        
            execQry(array(
                'type' => 'insert', 'table' => 'HR_EMP_TENURE',
                'data' => array(
                    'ID' => '',
                    'EMP_ID' => trim($tnu['EMP_ID']),
                    'EMP_CODE' => trim($tenureEffDate['EMP_CODE']),
                    'ETYPE_ID' => trim($tenureEffDate['ETYPE_ID']),
                    'ETYPE_PERIOD' => trim($eff_period),
                    'EFF_FROM' => $effFrom,
                    'EFF_TO' => $eff_to,
                    'CHG_ON' => SYSDATE,
                    'CHG_BY' => trim($empCode)
                ),
                'return' => '',
                'print' => 0
            ));

            generateTaskHR(
                'PRT_TNU_CHG_LTR',
                $data['emp_code'],
                'Print CTC Change Letter for ' . getEmpInfoByCode($data['emp_code']),
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
    
                $tran_desc = 'Leave Credit for '. getEmpInfoByCode($data['emp_code']);
            
                execQry( array(
                    'type' => 'insert', 
                    'table' => 'HR_BCS_USER_TASKS',
                    'data' => array(
                        'TASK_ID' => '373',
                        'CREATED_ON' => SYSDATE,  
                        'EXPIRE_ON' => SYSDATE + 2,
                        'STATUS' => 'O',
                        'TRAN_CODE' => $data['emp_code'],
                        'TASK_TYPE' => 'A',
                        'TRAN_DESC' => $tran_desc,
                        'SITE_CODE' => $session_empCode['WORK_SITE'],
                        'CREATED_BY' => $empCode,  
                    ),
                    'print' => 0,
                    'return' => 'ID'
                ));
            }
        } else {
            generateTaskHR(
                'TNU_CHG_LTR',
                $data['emp_code'],
                'CTC Change Letter for ' . getEmpInfoByCode($data['emp_code']),
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

        taskUpdateHR('C', '', $data['task_id'], null, null);
        endQry();
        apiResponse(true, "Manager Task Closed and New Task generate for further processing", null, 200);
        
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
