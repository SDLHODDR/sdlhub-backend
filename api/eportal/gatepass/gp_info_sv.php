<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "gp_head.php";

try {

    if($data['getGpdata']==true)
    {
        $idd = $data['id'] ?? "";
        $res = singRec("SELECT * FROM EPT_EMPLOYEE_GPASS WHERE ID='".$idd."'");
        $empCodeFromRow = $res['EMP_CODE'] ?? $empCode ?? null;
        //printDetails($res);
        $returnArr = [];

        $returnArr["hidden"] = [
            [
                "name" => "ID",
                "id" => "ID",
                "value" => $res['ID']
            ],
            [
                "name" => "OUT_TYPE_HIDDEN",
                "id" => "OUT_TYPE_HIDDEN",
                "value" => $res['OUT_TYPE']
            ],
            [
                "name" => "EMP_CODE",
                "id" => "EMP_CODE",
                "value" => $empCodeFromRow
            ]    
        ];
        
        
        $returnArr["form_data"] = [
            [
                "name" => "GPASS_DATE",
                "id" => "GPASS_DATE",
                "value" => $res['GPASS_DATE'] ?? "",
                "is_readonly" => true
            ],
            [
                "name" => "OUT_TYPE",
                "id" => "OUT_TYPE",
                "value" => $res['OUT_TYPE'] ?? "",
                "options" => [
                    'OI'=> 'In/Out same day',
                    'OD'=>'Out for full day',
                    'FO'=> 'First Half Out',
                    'SO'=>'Second Half Out',
                    'FW' => 'Field Work',
                    'TO' => 'Tour'
                ],
                "is_readonly" => false
            ],
            [
                "name" => "REMARKS",
                "id" => "REMARKS",
                "value" => $res['REMARKS'] ?? "",
                "is_readonly" => false
            ],
            [
                "name" => "POST_REMARKS",
                "id" => "POST_REMARKS",
                "value" => $res['POST_REMARKS'] ?? "",
                "is_readonly" => false
            ],
        ];

        $returnArr["form_data"]["employee_name"] = getEmpInfoByCode($empCodeFromRow);

        $status = $res['STATUS'] ?? null;

        $returnArr["form_data"]["GPSTATUS"] = (!empty($status) && isset($statusMap[$status]))
            ? $statusMap[$status]
            : null;   // or "" if you prefer empty string

        if($returnArr || !empty($returnArr)){
            apiResponse(
                true,
                "Outdoor Duty fetched successfully.",
                $returnArr
            );
        }
    } else if($data['getGpAttddata']==true)
    {
        // $punchLogTbl = singRec("SELECT to_date (ATTD_DATE,'dd-mm-yyyy') AT_DATE,
        // to_char (IN_TIME, 'HH24:MI:SS') IN_TIM,
        // to_char (OUT_TIME, 'HH24:MI:SS') OUT_TIM,WORK_HOUR,OT_HOUR 
        // FROM EPT_BCS_ATTD_REG
        // WHERE emp_code='" . $data['emp_code'] . "' AND attd_date = '" . $data['gpass_date'] . "' ");
        
        $punchLogTbl = singRec("SELECT to_date (ATTD_DATE,'dd-mm-yyyy') AT_DATE,
        to_char (IN_TIME, 'HH24:MI:SS') IN_TIM,
        to_char (OUT_TIME, 'HH24:MI:SS') OUT_TIM,WORK_HOUR,OT_HOUR 
        FROM EPT_BCS_ATTD_REG
        WHERE emp_code='" . $data['emp_code'] . "' AND attd_date = '04-Aug-22' ");

        $returnArr = [];
        if($punchLogTbl || !empty($punchLogTbl))
        {
            
            if($data['out_type']=='FO')
            {
                $returnArr['keyRt'] = "Office in time";
                $returnArr['valRt'] = $punchLogTbl['IN_TIM'];
            } else if ($data['out_type']=='SO')
            {
                $returnArr['keyRt'] = "Office out time";
                $returnArr['valRt'] = $punchLogTbl['OUT_TIM'];
            } else if($data['out_type']=='OI')
            {
                $offDesk=singRec("select 
                to_char(to_date(EPT_GET_OFFDESK_TIME('".$data['emp_code']."',
                to_date('".$data['gpass_date']."')) - GET_OFFDESKTERRACE_TIME@EPPLIVE_LINK('".$data['emp_code']."',
                to_date('".$data['gpass_date']."')), 'sssss') ,'hh24:mi') tm from dual ");

                $terrace=singRec("select 
                to_char(to_date(EPT_GET_TERRACE_TIME('".$data['emp_code']."',
                to_date('".$data['gpass_date']."')) + GET_OFFDESKTERRACE_TIME@EPPLIVE_LINK('".$data['emp_code']."',
                to_date('".$data['gpass_date']."')), 'sssss') ,'hh24:mi') tm from dual ");
                                                                
                $tmOffDesk = sum_the_time($offDesk['TM'].':00',$terrace['TM'].':00');

                $returnArr['keyRt'] = "Out of office";
                $returnArr['valRt'] = $tmOffDesk . " Hrs";
            }

            if($returnArr || !empty($returnArr)){
                apiResponse(
                    true,
                    "Attance fetched successfully.",
                    $returnArr
                );
            }
        }

    }
    exit;
} catch (Throwable $e) {
    //logOracleError($e);
    apiResponse(false, "Unable to fetch outdoorduty form data.", null, 500);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}