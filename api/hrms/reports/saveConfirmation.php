<?php

try {

    

    if (isset($data['saveConfirmation']) && $data['saveConfirmation'] == true){
        startQry();
        // print '<pre/>';
        // print_r($data);
        // exit;

        // Access individual fields
        $questions        = $data['question'] ?? [];
        
        $tenure_chg = singRec("SELECT * FROM HR_EMP_TENURE_CHANGE WHERE EMP_CODE = '".$data['emp_code']."' AND STATUS in  ('N','T')");

        // print_r($tenure_chg);
        // echo '------------------';
        // print_r($data);
        // exit;

       
        execQry(array(
            'type'  => 'update',
            'table' => 'HR_EMP_TENURE_CHANGE',
            'data'  => array(
                'ETYPE_ID'     => $data['etype_id'],

                'ETYPE_PERIOD' => (
                    trim((string)($data['etype_period'] ?? '')) === ''
                        ? null
                        : $data['etype_period']
                ),

                'EFF_FROM' => "TO_DATE('" .
                    str_replace("'", "''", $data['effective_from']) .
                    "', 'YYYY-MM-DD')",

                'CTC_EFF_FROM' => "TO_DATE('" .
                    str_replace("'", "''", $data['effective_from']) .
                    "', 'YYYY-MM-DD')",

                // NXT_APPR_DUE is a DATE column, not a year/number column.
                'NXT_APPR_DUE' => (
                    trim((string)($data['next_appraisal_year'] ?? '')) === ''
                        ? null
                        : "TO_DATE('" .
                            str_replace(
                                "'",
                                "''",
                                trim((string)$data['next_appraisal_year']) . '-01-01'
                            ) .
                            "', 'YYYY-MM-DD')"
                ),

                'REMARKS' => $data['remarks'],

                'CURR_CTC' => (
                    trim(str_replace(',', '', (string)($data['CURR_CTC'] ?? ''))) === ''
                        ? null
                        : str_replace(',', '', $data['CURR_CTC'])
                ),

                'PROP_CTC' => (
                    trim(str_replace(',', '', (string)($data['proposed_ctc'] ?? ''))) === ''
                        ? null
                        : str_replace(',', '', $data['proposed_ctc'])
                ),

                'STATUS' => 'T',
                'CHG_ON' => 'SYSDATE',
                'CHG_BY' => trim($empCode)
            ),

            'where'  => array('ID' => trim($tenure_chg['ID'])),
            'return' => '',
            'print'  => 0
        ));

        executeQry("DELETE FROM HR_TENURE_INTRV WHERE EMP_CODE='".$data['emp_code']."'");


        // Process nested questions
        foreach ($questions as $qid => $answers) {
            execQry(
                array(
                    'type' => 'insert', 'table' => 'HR_TENURE_INTRV',
                    'data' => array(
                        'ID' => '',
                        'TENURE_CHG_ID' => $tenure_chg['ID'],
                        'EMP_CODE' => $data['emp_code'],
                        'ASON_DATE' => 'sysdate',
                        'EMP_CODE_INTRV' => $empCode,
                        'JD_ID' => $data['jd_id'],
                        'QUES_ID' => $qid,
                        'EMP_OPTS_ID' => '',
                        'EMP_ANS_TEXT' => implode(",", $answers),
                        'CHG_BY' => $empCode,
                        'CHG_ON' => 'sysdate'
                    ),
                    'print' => 0
                )
            );
        }

        // exit;
        endQry("Saved Successfully");
        apiResponse(true, "Employee Teneure Change saved successfully. ", null, 200);
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
        "saveConfirmation.php"
    );

    apiResponse(false, "Unable to generate Task.33", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
