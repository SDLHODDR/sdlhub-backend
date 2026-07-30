<?php

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
    }
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch outdoorduty form data.", null, 500);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}