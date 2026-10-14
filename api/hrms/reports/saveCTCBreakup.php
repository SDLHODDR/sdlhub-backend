<?php

try {

    if (isset($data['saveCTCBreakup']) && $data['saveCTCBreakup'] == true){
        startQry();
        // print '<pre/>';
        // print_r($data);
        //exit;

        $sumCTC = 0;
        foreach ($data['AMOUNT_ANNUALLY'] as $key => $value) {
            $sumCTC += $value;
        }  
        $diff = $sumCTC - $data['sum_annually_struct'];
        echo "====================<br/>==" . $sumCTC . " = " . $diff; 
        $num = 100;
        if($num > $diff){
            $data['SALARY_TYPE'] = 'T';
            $effto = singRec("SELECT * FROM EPPLIVE.BCS_ACCT_PERIOD 
            WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE");

            execQry(array(
                'type' => 'update', 'table' => 'HR_EMP_TENURE_CHANGE',
                'data' => array(
                    'CTC_EFF_FROM' => $data['CTC_EFF_FROM'],
                    'CHG_ON' => 'SYSDATE',
                    'CHG_BY' => trim($empCode)
                ),
                'where' => array('ID' => trim($data['TENURE_CHG_ID'])),
                'return' => '',
                'print' => 0
            ));
            
            foreach($data['AMOUNT_MNTHLY'] as $key => $val) {
                executeQry("DELETE FROM HR_EMP_CTC_HEADS 
                    WHERE EMP_CODE='" . $data['EMP_CODE'] . "' 
                    AND AD_CODE = '".$key."' 
                    AND REF_ID = '".$data['TENURE_CHG_ID']."'");

                $type = singRec("SELECT AD_TYPE FROM HR_BCS_ALLW_DEDN WHERE AD_CODE = '".$key."'");
                if($type['AD_TYPE'] == 'A') {
                    $calType = 'F';
                } else if($type['AD_TYPE'] == 'D') {
                    $calType = 'P';
                }

                execQry(array(
                    'type' => 'insert',
                    'table' => 'HR_EMP_CTC_HEADS',
                    'data' => array(
                        'ID' =>'',
                        'EMP_CODE' => $data['EMP_CODE'],
                        'SALARY_TYPE' => $data['SALARY_TYPE'],
                        'REF_ID' => $data['TENURE_CHG_ID'],
                        'CTC' => $sumCTC,
                        'AD_CODE' => $key,
                        'AMOUNT_MNTH' => $val,
                        'AMOUNT_YRLY' => $data['AMOUNT_ANNUALLY'][$key],
                        'STATUS' =>'N',
                        'CALC_TYPE' => $calType,
                        'EFF_FROM' => $data['CTC_EFF_FROM'],
                        'EFF_TO' => $effto['TO_DATE'],
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'return' => '',
                    'print' => 0
                ));
            }

            foreach($_REQUEST['AMOUNT_MNTHLY_VAR'] as $key => $val) {
                executeQry("DELETE FROM HR_EMP_CTC_HEADS
                    WHERE EMP_CODE='" . $data['EMP_CODE'] . "'
                    AND AD_CODE = '".$key."' 
                    AND REF_ID = '".$data['TENURE_CHG_ID']."'");

                $type = singRec("SELECT AD_TYPE FROM HR_BCS_ALLW_DEDN 
                    WHERE AD_CODE = '".$key."'");

                if($type['AD_TYPE'] == 'A') {
                    $calType = 'F';
                }else if($type['AD_TYPE'] == 'D') {
                    $calType = 'P';
                }

                execQry(array(
                    'type' => 'insert',
                    'table' => 'HR_EMP_CTC_HEADS',
                    'data' => array(
                        'ID' =>'',
                        'EMP_CODE' =>$data['EMP_CODE'],
                        'SALARY_TYPE' =>$data['SALARY_TYPE'],
                        'REF_ID' =>$data['TENURE_CHG_ID'],
                        'CTC' => $sumCTC,
                        'AD_CODE' => $key,
                        'AMOUNT_MNTH' => $val,
                        'STATUS' =>'N',
                        'CALC_TYPE' => $type['AD_TYPE'],
                        'EFF_FROM' => $data['CTC_EFF_FROM'],
                        'EFF_TO' => $effto['TO_DATE'],
                        'CHG_ON' => 'SYSDATE',
                        'CHG_BY' => trim($empCode)
                    ),
                    'return' => '',
                    'print' => 0 
                ));
            }
            endQry("CTC saved!");
            apiResponse(true, "Employee CTC saved successfully. ", null, 200);
        } else {
            endQry("Entered Values are greater than Proposed CTC!");
            apiResponse(false, "Entered Values are greater than Proposed CTC!", null, 200);
            exit;
        }
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
        "saveCTCBreakup.php"
    );

    apiResponse(false, "Unable to generate Task.44", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}