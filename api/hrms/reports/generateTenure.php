<?php

try {

    

    if (isset($data['generateTenure']) && $data['generateTenure'] == true){
        startQry();
        $tmp = $data['EMP_CODE'];
        $data['EMP_CODE'] = '01148';
        $ctc = singRec("SELECT GET_TOTAL_CTC('" . $data['EMP_CODE'] . "', '31-MAR-2025') VAL FROM DUAL");
        $data['EMP_CODE'] = $tmp;
        //$empDet = getEmpOffice($data['EMP_CODE']);
        $empDet = singRec("SELECT HRC.COMP_ID, HEOD.DIVSN_ID, HEOD.DEPT_ID 
            FROM HR_EMP_OFFICE_DET HEOD
            INNER JOIN HR_ORGANOGRAM HRO ON HEOD.ORG_ID = HRO.ID
            INNER JOIN HR_COMPANY HRC ON HRO.COMPANY = HRC.COMP_ID
            WHERE HEOD.EMP_CODE = '" . $data['EMP_CODE'] . "'");

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
       
        $data['MGR_CODE'] = '00575'; //Temporary added need to remove post implementation
        $task_id =  generateTaskHR(
            'TNU_CHG', 
            $data['EMP_CODE'], 
            'Confirmation/Tenure change of ' . getEmpInfoByCodeHR($data['EMP_CODE']), 
            '', 
            'A', 
            'O', 
            $data['MGR_CODE'], 
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
        apiResponse(true, "Teneure Change Task generated successfully. ", [
            "teneureId" => $tnu_id,
            "task_id" => $task_id
        ]);
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
        "tenureChange.php"
    );

    apiResponse(false, "Unable to generate Task.22", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
