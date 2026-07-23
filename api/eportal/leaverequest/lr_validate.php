<?php

require_once "lr_head.php";

//printDetails($data);

if($data['ClValidate']==true)
{
    //$data['fr_dt'] = "12-Jul-2010";
    //$data['to_dt'] = "12-Jul-2010";

    // $result = singRec("
    //     SELECT 
    //         COUNT(*) AS CNT,
    //         NVL(SUM(NO_DAYS), 0) AS TOTAL_DAYS
    //     FROM EPT_bcs_emp_leaves
    //     WHERE emp_code = '" . $data['EMP_CODE'] . "'
    //     AND lve_code = 'CL'
    //     AND LVE_DATE_FR >= TRUNC(TO_DATE('" . $data['fr_dt'] . "', 'YYYY-MM-DD'), 'MM')
    //     AND LVE_DATE_FR <  ADD_MONTHS(TRUNC(TO_DATE('" . $data['fr_dt'] . "', 'YYYY-MM-DD'), 'MM'), 1)");

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
        echo json_encode([
            "status" => false,
            "data"   => 0
        ]);
	} else {
		echo json_encode([
            "status" => true,
            "data"   => 1
        ]);
	}

    // // $clLeaves['CNT']    = $result['CNT'] ?? 0;
    // // $clLeaveDays['CNT'] = $result['TOTAL_DAYS'] ?? 0;

	// // $diff = abs(strtotime($data['to_dt']) - strtotime($data['fr_dt']));
	// // $years = floor($diff / (365 * 60 * 60 * 24));
	// // $months = floor(($diff - $years * 365 * 60 * 60 * 24) / (30 * 60 * 60 * 24));
	// // $days = floor(($diff - $years * 365 * 60 * 60 * 24 - $months * 30 * 60 * 60 * 24) / (60 * 60 * 24));

    // // echo "clLeaves-CNT : " . $clLeaves['CNT'] . "<br/>clLeavedays-CNT : " . $clLeavedays['CNT'] . "<br/>days : " . $days . "<br/>to_dt : " . $data['to_dt'] . "<br/>fr_dt : " . $data['fr_dt'] . "SELECT 
    // //         COUNT(*) AS CNT,
    // //         NVL(SUM(NO_DAYS), 0) AS TOTAL_DAYS
    // //     FROM EPT_bcs_emp_leaves
    // //     WHERE emp_code = '" . $data['EMP_CODE'] . "'
    // //     AND lve_code = 'CL'
    // //     AND LVE_DATE_FR >= TRUNC(TO_DATE('" . $data['fr_dt'] . "', 'DD-Mon-YYYY'), 'MM')
    // //     AND LVE_DATE_FR <  ADD_MONTHS(TRUNC(TO_DATE('" . $data['fr_dt'] . "', 'DD-Mon-YYYY'), 'MM'), 1)";

    // if ($clLeaves['CNT'] >= 3 || $clLeavedays['CNT'] >= 3 || $days >= 2 && ($data['to_dt'] != '' && $data['fr_dt'] != '')) {
	// 	//echo "0";
    //     echo json_encode([
    //         "status" => false,
    //         "data"   => 0
    //     ]);
	// } else {
	// 	//echo "1";
    //     echo json_encode([
    //         "status" => true,
    //         "data"   => 1
    //     ]);
	// }

} else if($data['OlValidate']==true) {
    //$holVal = singRec("select * from EPT_bcs_holidays where hol_type = 'O' and hol_date = '" . $data['attd_date'] . "' and hol_grp in (select hol_tblno from EPT_bcs_employee where emp_code='" . $data['EMP_CODE'] . "')");

    $holVal = singRec("select * from EPT_bcs_holidays where hol_type = 'O' and hol_date = to_date('".$data['attd_date']."','YYYY-MM-DD') and hol_grp in (select hol_tblno from EPT_bcs_employee where emp_code='" . $data['EMP_CODE'] . "')");
	
	if (($holVal['HOL_GRP']!='')) {
		echo json_encode([
            "status" => true,
            "data"   => 0,
        ]);
	} else {
		echo json_encode([
            "status" => true,
            "data"   => 1,
        ]);
	}
}
