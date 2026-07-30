<?php

require_once "lr_head.php";

if($data['getLrdata']==true)
{
    try {

        $idd = $data['id'] ?? "";
        if($data['start_date']=='')
        {
        $data['start_date'] = date('Y-m-01');
        }
        $data['start_date'] = '2025-05-01'; 

        $pl_count = singRec("select EPT_get_leave_apply_cnt('".$empCode."', 'PL') CNT from dual");
        //printDetails($pl_count);

        $totalBalLeaves = multiRec("select lve_code,AVAIL_DAYS,BAL_DAYS from EPT_bcs_leave_balance where emp_code='" . $empCode . "' and to_date('".$data['start_date']."','YYYY-MM-DD') between EFF_DATE and UPTO_DATE");

        $totalBalLeaves_unapproved = multiRec("select emp_code , sum(total_days)td  , trim(lve_code)lve_code , 1 as T from EPT_bcs_emp_leaves_temp where emp_code='" . $empCode . "' and status= 'T' group by emp_code , lve_code");

        $lve_from_to = singRec("select * from EPT_bcs_leave_balance where emp_code='" . $empCode . "' and status='A' and lve_code!='LWP' AND to_date('".$data['start_date']."','YYYY-MM-DD') BETWEEN EFF_DATE AND UPTO_DATE");

        $leave_res = singRec("select EPT_GET_EMP_NAME(emp_code) emp_name , belt.*  from EPT_bcs_emp_leaves_temp belt where id='" . $idd . "'");

        $leave_bal_array  = array();
        foreach ($totalBalLeaves as $temp) {
            $leave_bal_array[$temp['LVE_CODE']][0] = $temp['AVAIL_DAYS'];
            $leave_bal_array[$temp['LVE_CODE']][1] = $temp['BAL_DAYS'];
        }
        foreach ($totalBalLeaves_unapproved as $temp2) {
            $leave_bal_array[$temp2['LVE_CODE']][2] = $temp2['TD'];
        }

        $returnArr = [];
        $res = [];

        /* ---------------------------------
            COMMON CLASS DEFINITIONS
        ----------------------------------*/

        $className = [
            "Divs" => [
                "small" => "col-lg-6 mb-3",
                //"medium" => "col-md-6",
                "large" => "col-lg-12 mb-3"
            ],
            "Labels" => [
                "general" => "fw-bold label-req"
            ],
            "Fields" => [
                "TEXT" => "form-control",
                "SELECT" => "select2 form-control",
                "TEXTAREA" => "form-control"
            ]
        ];

        $lveDate = $data["modal_date"]; // e.g. '2026-07-14T18:30:00.000Z'

        $sqlCheckLeave = "SELECT empcode, lveFrm, lveTO 
            FROM (
                SELECT EMP_CODE as empcode, lve_date_fr as lveFrm, lve_date_to as lveTO 
                FROM EPT_BCS_EMP_LEAVES 
                WHERE emp_code = '".$empCode."'
                AND lve_date_fr = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS')

                UNION ALL

                SELECT EMP_CODE as empcode, lve_date_fr as lveFrm, lve_date_to as lveTO  
                FROM EPT_BCS_EMP_LEAVES_TEMP 
                WHERE EMP_CODE = '".$empCode."'
                AND lve_date_fr = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS') 
                AND STATUS NOT IN ('R','X')

                UNION ALL

                SELECT EMP_CODE as empcode, lve_date_fr as lveFrm, lve_date_to as lveTO  
                FROM EPT_BCS_EMP_LEAVES_TEMP 
                WHERE EMP_CODE = '".$empCode."'
                AND lve_date_fr = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS') 
                AND STATUS IS NULL 

                UNION ALL

                SELECT EMP_CODE as empcode, TIME_FR as lveFrm,TIME_TO as lveTO  
                FROM EPT_BCS_ATTD_REGULARIZE
                WHERE EMP_CODE = '".$empCode."'
                AND REG_TYPE = 'T'
                AND STATUS = 'A'
                AND TIME_FR = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS')
            ) t";

        $persHolidOff = singRec($sqlCheckLeave);

        if (count($persHolidOff) != 0) {
            $flag = 'No';
        } else {
            $flag = 'Yes';
        }

        /* ---------------------------------
            FIELD DEFINITIONS - INFO-DIV
        ----------------------------------*/
        $returnArr["var"]["type"]["DIVINFO"] = [];

        foreach ($leave_bal_array as $key => $leave) {
        if($key !== "LWP"){
            $type = $key; //$leave['type']; // CL, PL, SL, OL
            $returnArr["var"]["type"]["DIVINFO"][$type] = [
                "type" => "DIV",
                "label" => $type,
                "labelClassName" => $className["Labels"]["general"],
                "name" => $type,
                "id" => strtolower($type) . "_bal",
                "value_1" => $leave[1] ?? '',
                "value_2" => $leave[0] ?? ''
            ];
            $returnArr["LEAVEBALARR"][$type] = [
                0 => $leave[0],
                1 => $leave[1],
                2 => $leave[2] 
            ];
        }
        }

        /* ---------------------------------
            FIELD DEFINITIONS - TEXT
        ----------------------------------*/
        $returnArr["var"]["type"]["TEXT"] = [
        
            "LVE_DATE_FR" => [
                "type" => "TEXT",
                "label" => "Leave From Date",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "LVE_DATE_FR",
                "id" => "LVE_DATE_FR",
                "value" => !empty($leave_res['LVE_DATE_FR']) ? strtoupper(date("d-M-Y", strtotime($leave_res['LVE_DATE_FR']))) : '',
                "rowPlacement" => "row-2|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],    
                "disabled" => "Yes",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "No"
            ],
            "LVE_DATE_TO" => [
                "type" => "TEXT",
                "label" => "Leave To Date",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "LVE_DATE_TO",
                "id" => "LVE_DATE_TO",
                "value" => !empty($leave_res['LVE_DATE_TO']) ? strtoupper(date("d-M-Y", strtotime($leave_res['LVE_DATE_TO']))) : '',
                "rowPlacement" => "row-3|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "leave_validate",
                "isRequired" => "Yes",
            ],
            "NO_DAYS" => [
                "type" => "TEXT",
                "label" => "No of Days",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "NO_DAYS",
                "id" => "NO_DAYS",
                "value" => '',
                "rowPlacement" => "row-4|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "Yes",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "handleLVECodeChange",
                "isRequired" => "Yes",
            ]
        ];

        /* ---------------------------------
            TEXTAREA
        ----------------------------------*/

        $returnArr["var"]["type"]["TEXTAREA"] = [

            "REASON" => [
                "type" => "TEXTAREA",
                "label" => "Reason",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "REASON",
                "id" => "REASON",
                "value" => $leave_res['REASON'] ?? '',
                "rowPlacement" => "row-5|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["large"],
                "fieldClassName" => $className["Fields"]["TEXTAREA"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "isRequired" => "Yes",
            ]
        ];

        /* ---------------------------------
            HIDDEN FIELDS
        ----------------------------------*/

        $returnArr["var"]["type"]["HIDDEN"] = [

            "EMP_CODE" => [
                "type" => "HIDDEN",
                "name" => "EMP_CODE", 
                "id" => "EMP_CODE", 
                "value" => $empCode ?? null
            ],
            "MIN_ALL" => [
                "type" => "HIDDEN",
                "name" => "MIN_ALL",
                "id" => "MIN_ALL",
                "value" => null ?? ''
            ],
            "MAX_ALL_AT_TIME" => [
                "type" => "HIDDEN",
                "name" => "MAX_ALL_AT_TIME",
                "id" => "MAX_ALL_AT_TIME",
                "value" => null ?? ''
            ],
            "MAX_ALL_DAYS_MONTHLY" => [
                "type" => "HIDDEN",
                "name" => "MAX_ALL_DAYS_MONTHLY",
                "id" => "MAX_ALL_DAYS_MONTHLY",
                "value" => null ?? ''
            ],
            "EFF_DATE" => [
                "type" => "HIDDEN",
                "name" => "EFF_DATE",
                "id" => "EFF_DATE",
                "value" => $lve_from_to['EFF_DATE'] ?? ''
            ],
            "UPTO_DATE" => [
                "type" => "HIDDEN",
                "name" => "UPTO_DATE",
                "id" => "UPTO_DATE",
                "value" => $lve_from_to['UPTO_DATE'] ?? ''
            ],
            "PL_COUNT" => [
                "type" => "HIDDEN",
                "name" => "PL_COUNT",
                "id" => "PL_COUNT",
                "value" => $pl_count['CNT'] ?? 0
            ],
        ];

        /* ---------------------------------
            SELECT FIELDS
        ----------------------------------*/
        $returnArr["var"]["type"]["SELECT"] = [
            "LVE_CODE" => [
                "type" => "SELECT",
                "label" => "Leave Code",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "LVE_CODE", 
                "id" => "LVE_CODE", 
                "value" => $leave_res['LVE_CODE'] ?? "",
                "rowPlacement" => "row-1|col-1",
                "family" => [],
                "dependsOn" => null,
                "onChangeFunc" => "handleLVECodeChange",
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "Yes",
                "isRequired" => "Yes",
                "options" => getOptionsCustom("
                    select LVE_CODE, (LVE_CODE||' - '||LVE_DESC) as lved
                        from EPT_bcs_leaves
                        where trim(lve_code) in(select lve_code from EPT_bcs_leave_balance where emp_code='" . $empCode . "' and status='A'  AND to_date('".$data['start_date']."','YYYY-MM-DD') BETWEEN EFF_DATE AND UPTO_DATE+1) AND trim(lve_code) not in (select trim(lve_code) from EPT_bcs_emp_leaves_temp where emp_code='" . $empCode . "' and lve_code='PL' and status='T' ) UNION
                    SELECT 'LWP' , 'LWP' || ' - '||  'LEAVE WITHOUT PAY' FROM DUAL")
            ],
            "LEAVE_STARTS" => [
                "type" => "SELECT",
                "label" => "Leave Starts",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "LEAVE_STARTS", 
                "id" => "LEAVE_STARTS", 
                "value" => $leave_res['LVE_START_ON'] ?? "",
                "rowPlacement" => "row-2|col-2",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => "handleChangeLV",
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "Yes",
                "isRequired" => "Yes",
                "options" => [
                    'B'=> 'Beginning Of The Day',
                    'M'=> 'Middle Of The Day'
                ]
            ],
            "LEAVE_ENDS" => [
                "type" => "SELECT",
                "label" => "Leave Ends",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "LEAVE_ENDS", 
                "id" => "LEAVE_ENDS", 
                "value" => $leave_res['LVE_END_ON'] ?? "",
                "rowPlacement" => "row-3|col-2",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => "handleChangeLV",
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "Yes",
                "isRequired" => "Yes",
                "options" => [
                    'E'=> 'End Of The Day',
                    'M'=> 'Middle Of The Day'
                ]
            ]
        ];

        $returnArr["flag"] = $flag;
        //$returnArr["leavesDT"] = $persHolidOff;
        //$returnArr['leavesDTCnt'] = count($persHolidOff);

        if($returnArr || !empty($returnArr)){
            apiResponse(
                true,
                "Leave data fetched successfully.",
                ["pass"   => $returnArr]
            );
        } else {
            apiResponse(false, "No data found.", null, 200);
        }
    } catch (Throwable $e) {
        logOracleError($e);
        apiResponse(false, "Unable to fetch outdoorduty form data.", null, 500);
    } finally {
        if ($sql___func___con) {
            oci_close($sql___func___con);
        }
    }

} else {

    try {

        $lveDate = $data["modal_date"]; // e.g. '2026-07-14T18:30:00.000Z'

        $sqlCheckLeave = "SELECT empcode, lveFrm, lveTO 
            FROM (
                SELECT EMP_CODE as empcode, lve_date_fr as lveFrm, lve_date_to as lveTO 
                FROM EPT_BCS_EMP_LEAVES 
                WHERE emp_code = '".$empCode."'
                AND lve_date_fr = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS')

                UNION ALL

                SELECT EMP_CODE as empcode, lve_date_fr as lveFrm, lve_date_to as lveTO  
                FROM EPT_BCS_EMP_LEAVES_TEMP 
                WHERE EMP_CODE = '".$empCode."'
                AND lve_date_fr = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS') 
                AND STATUS NOT IN ('R','X')

                UNION ALL

                SELECT EMP_CODE as empcode, lve_date_fr as lveFrm, lve_date_to as lveTO  
                FROM EPT_BCS_EMP_LEAVES_TEMP 
                WHERE EMP_CODE = '".$empCode."'
                AND lve_date_fr = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS') 
                AND STATUS IS NULL 

                UNION ALL

                SELECT EMP_CODE as empcode, TIME_FR as lveFrm,TIME_TO as lveTO  
                FROM EPT_BCS_ATTD_REGULARIZE
                WHERE EMP_CODE = '".$empCode."'
                AND REG_TYPE = 'T'
                AND STATUS = 'A'
                AND TIME_FR = TO_TIMESTAMP('".$lveDate."', 'YYYY-MM-DD HH24:MI:SS')
            ) t";

        $persHolidOff = singRec($sqlCheckLeave);

        if (count($persHolidOff) != 0) {
            $flag = 'No';
        } else {
            $flag = 'Yes';
        }

        $returnArr["flag"] = $flag;
        //$returnArr["leavesDT"] = $persHolidOff;
        //$returnArr['leavesDTCnt'] = count($persHolidOff);

        if($returnArr || !empty($returnArr)){
            apiResponse(
                true,
                "Leave data fetched successfully.",
                ["pass"   => $returnArr]
            );
        } else {
            apiResponse(false, "No data found.", null, 200);
        }
    } catch (Throwable $e) {
        logOracleError($e);
        apiResponse(false, "Unable to fetch leave form data.", null, 500);
    } finally {
        if ($sql___func___con) {
            oci_close($sql___func___con);
        }
    }
}
