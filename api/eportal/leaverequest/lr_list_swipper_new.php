<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "lr_head.php";
include_once('numbertoword.php');

$response = [
	"status" => true,
	"data" => []
];

$prdYear = singRecEPP("
	select to_char(FR_DATE,'dd-Mon-yyyy') FR_DATE
	from BCS_PERIOD
	where CODE='" . $data['PRD_CODE'] . "'
");

//echo "<pre>"; print_r($data); print_r($prdYear); echo "</pre>"; exit;

$yr = singRecEPP("
	SELECT
		to_char(TRUNC(to_date('" . $prdYear['FR_DATE'] . "') , 'YEAR'),'dd-Mon-yyyy') FR_DATE,
		to_char(ADD_MONTHS(TRUNC(to_date('" . $prdYear['FR_DATE'] . "') ,'YEAR'),12)-1,'dd-Mon-yyyy') TO_DATE
	FROM DUAL
");

//echo "<pre>"; print_r($yr); echo "</pre>"; exit;

$sql_emp = multiRecEPP("
	select
		e.emp_code,
		(e.emp_fname||' '||e.emp_mname||' '||e.emp_lname) emp,
		e.DEPT_CODE,
		d.dept_desc DESCR
	from bcs_employee e
	left join hrmslive.hr_DEPARTMENT d
		on e.DEPT_CODE=d.DEPT_CODE
	where 1=1
	and e.work_site ='" . $data['SITE_CODE'] . "'
	and e.proc_group between
		nvl('" . $data['PROC_GRP_FROM'] . "',e.proc_group)
		and nvl('" . $data['PROC_GRP_TO'] . "',e.proc_group)
	and e.emp_code between
		nvl('" . $data['EMP_FROM'] . "',e.emp_code)
		and nvl('" . $data['EMP_TO'] . "',e.emp_code)
	order by e.DEPT_CODE,e.emp_code
");

//echo "<pre>"; print_r($sql_emp); echo "</pre>"; exit;

foreach ($sql_emp as $emp)
{
	$empArr = [
		"DEPT" => $emp['DESCR'],
		"EMP_CODE" => $emp['EMP_CODE'],
		"EMP_NAME" => $emp['EMP'],
		"leave_details" => []
	];

	$SqlLv = multiRecEPP("
		select *
		from (
			select
				LVE_CODE,
				AVAIL_DAYS
			from BCS_LEAVE_BALANCE
			where emp_code='" . $emp['EMP_CODE'] . "'
			and '" . $prdYear['FR_DATE'] . "'
				between EFF_DATE and UPTO_DATE
			and status='A'
			and LVE_CODE=nvl('" . $data['LEAVE_CODE'] . "',LVE_CODE)
		)
		order by 1
	");

	foreach ($SqlLv as $resLv)
	{
		$leaveArr = [
			"LEAVE_CODE" => $resLv['LVE_CODE'],
			"OPENING_BALANCE" => (float)$resLv['AVAIL_DAYS'],
			"periods" => []
		];

		$balance = (float)$resLv['AVAIL_DAYS'];

		$SqlEnj = multiRecEPP("
			select
				prd_code,
				sum(NO_DAYS) LV_CNT
			from BCS_EMP_LEAVES
			where emp_code='" . $emp['EMP_CODE'] . "'
			and STATUS='A'
			and lve_code='" . $resLv['LVE_CODE'] . "'
			and LVE_DATE_FR between
				'" . $yr['FR_DATE'] . "'
				and '" . $yr['TO_DATE'] . "'
			group by prd_code
			order by prd_code
		");

		foreach ($SqlEnj as $resEnj)
		{
			$balance = $balance - $resEnj['LV_CNT'];

			$lvDate = multiRecEPP("
				select to_char(LVE_DATE_FR,'dd') LV_DATE
				from BCS_EMP_LEAVES
				where EMP_CODE='" . $emp['EMP_CODE'] . "'
				and PRD_CODE='" . $resEnj['PRD_CODE'] . "'
				and lve_code='" . $resLv['LVE_CODE'] . "'
				order by LVE_DATE_FR
			");

			$dateArr = [];

			foreach ($lvDate as $dt)
			{
				$dateArr[] = $dt['LV_DATE'];
			}

			$leaveArr['periods'][] = [
				"PERIOD" => $resEnj['PRD_CODE'],
				"ENJOYED" => (float)$resEnj['LV_CNT'],
				"BALANCE" => $balance,
				"MONTH_DATES" => implode(", ", $dateArr)
			];
		}

		$empArr['leave_details'][] = $leaveArr;
	}

	$response['data'][] = $empArr;
}

echo json_encode($response);

?>