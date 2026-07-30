<?php

require_once "lr_head.php";

if($data['ClValidate']==true)
{
    $clLeaves = singRec("
        SELECT 
            COUNT(*)CNT 
        FROM EPT_bcs_emp_leaves 
        WHERE emp_code = '" . $data['EMP_CODE'] . "'
        AND lve_code = 'CL' 
        AND EXTRACT(MONTH FROM TO_DATE( '" . $data['fr_dt'] . "',  'YYYY-MM-DD')) = EXTRACT(MONTH FROM LVE_DATE_FR)
        AND EXTRACT(YEAR FROM TO_DATE('" . $data['fr_dt'] . "',  'YYYY-MM-DD')) = EXTRACT(YEAR FROM LVE_DATE_FR)");
    
    $clLeavedays = singRec("
        SELECT 
            sum(NO_DAYS)CNT 
        FROM EPT_bcs_emp_leaves
        WHERE emp_code = '" . $data['EMP_CODE'] . "'
        AND lve_code = 'CL' 
        AND EXTRACT(MONTH FROM TO_DATE( '" . $data['fr_dt'] . "',  'YYYY-MM-DD')) = EXTRACT(MONTH FROM LVE_DATE_FR)
        AND EXTRACT(YEAR FROM TO_DATE('" . $data['fr_dt'] . "',  'YYYY-MM-DD')) = EXTRACT(YEAR FROM LVE_DATE_FR)");

    $diff = abs(strtotime($data['to_dt']) - strtotime($data['fr_dt']));
	$years = floor($diff / (365 * 60 * 60 * 24));
	$months = floor(($diff - $years * 365 * 60 * 60 * 24) / (30 * 60 * 60 * 24));
	$days = floor(($diff - $years * 365 * 60 * 60 * 24 - $months * 30 * 60 * 60 * 24) / (60 * 60 * 24));
	
    if ($clLeaves['CNT'] >= 3 || $clLeavedays['CNT'] >= 3 || $days >= 2 && ($data['to_dt'] != '' && $data['fr_dt'] != '')) {
        apiResponse(false, "CL Leaves verification failed", 
            [
                "data"   => 1
            ], 200);
	} else {
        apiResponse(
            true,
            "CL Leaves verified",
            [
                "data"   => 1
            ]
        );
	}
} else if($data['OlValidate']==true) {
    $holVal = singRec("select * from EPT_bcs_holidays where hol_type = 'O' and hol_date = to_date('".$data['attd_date']."','YYYY-MM-DD') and hol_grp in (select hol_tblno from EPT_bcs_employee where emp_code='" . $data['EMP_CODE'] . "')");
	
	if (($holVal['HOL_GRP'] !='')) {
        apiResponse(
            true,
            "OL",
            [
                "data"   => 1
            ]
        );
	} else {
        apiResponse(
            true,
            "OL",
            [
                "data"   => 0
            ]
        );
	}
}
