<?php
include_once('db.php');
session_start();
requestDecoder();
$GLOBALS['hrms'] = "http://192.168.10.143";
$GLOBALS['eppnew'] = "http://192.168.10.142";
$GLOBALS['teamsdl'] = "http://192.168.10.141";
$GLOBALS['eppold'] = "http://192.168.10.144";
include_once('sql_func.php');
// if (!isset($_POST['texttransform']))
	// $_POST = array_change_value_case($_POST, CASE_UPPER);
function get_emp_type($etypid)
{
	$res = singRec("select emp_type from hr_emp_type where etype_id='" . $etypid . "'");
	echo $res['EMP_TYPE'];
}
function ceiling($number, $significance) {
    if ($significance != null) {
        return (is_numeric($number) && is_numeric($significance) ) ? (ceil($number / $significance) * $significance) : $number;
    } else {
        return $number;
    }

}
   
function array_change_value_case($input, $case = CASE_UPPER)
{
	if (!is_array($input)) {
		return $input;
	}
	$retArr = array();
	foreach ($input as $key => $value) {
		if (is_array($value)) {
			$retArr[$key] = array_change_value_case($value, $case);
			continue;
		} else {
			if (stristrarray(array('EMAIL', 'FORMULA'), $key) && $key != '0') {
				$retArr[$key] = ($value);
			} else {
				$retArr[$key] = ($case == CASE_UPPER ? strtoupper($value) : strtolower($value));
			}
		}
	}
	return $retArr;
}
function generateTask(
	$task_group = null,
	$tran_code = null,
	$tran_desc = null,
	$task_grp_desc = null,
	$task_type = null,
	$status = null,
	$emp_code_for = null,
	$created_by = null,
	$comp_id = null,
	$div_id = null,
	$dept_id = null
) {
	$task_id = singRec("select id from hr_task_master where task_grp='" . $task_group . "'");
	$newId = execQry(array(
		'type' => 'insert',
		'table' => 'HR_USER_TASKS',
		'data' => array(
			'ID' => '',
			'TASK_ID' => trim($task_id['ID']),
			'STATUS' => $status,
			'TRAN_CODE' => $tran_code,
			'TASK_TYPE' => $task_type,
			'TRAN_DESC' => trim($tran_desc),
			'TASK_GRP_DESC' => $task_grp_desc,
			'EMP_CODE_FOR' => $emp_code_for,
			'COMP_ID' => $comp_id,
			'DIVSN_ID' => $div_id,
			'DEPT_ID' => $dept_id,
			'CREATED_ON' => 'SYSDATE',
			'CREATED_BY' => $created_by
		),
		'return' => 'ID',
		'print' => 0
	));
	return $newId;
}
function taskUpdate($status, $remark, $task_id, $taskGrp = null, $notChek = 0)
{
	$task = singRec("select ID from HR_TASK_MASTER where TASK_GRP='" . $taskGrp . "' ");
	$chk = singRec("select TASK_ID, STATUS, AUTH_BY, to_char(AUTH_ON,'dd-Mon-yyyy') AUTH_ON, to_char(AUTH_ON,'hh24:MI') AUTH_TM, TRAN_CODE,EMP_CODE_FOR
							from HR_USER_TASKS where id='" . $task_id . "' and task_id = nvl('" . $task['ID'] . "',task_id) ");
	if (empty($taskGrp))
		$task = singRec("select TASK_GRP from HR_TASK_MASTER where id='" . $chk['TASK_ID'] . "' ");
	if ($task_id) {
		executeQry("update HR_USER_TASKS set 
							STATUS='" . trim(strtoupper($status)) . "',
							AUTH_BY='" . trim(strtoupper($_SESSION['EmpCode'])) . "',
							AUTH_ON=SYSDATE,
							REMARKS='" . trim(strtoupper($remark)) . "',
							IP_ADDR='" . $_SERVER['REMOTE_ADDR'] . "'
						where id='" . $task_id . "' ");
	}
}
function getCompfromReq($reqid)
{
	$comp = singRec("select ho.company from hr_organogram ho inner join hr_recruitment hr on hr.org_id=ho.id WHERE hr.ID='" . $reqid . "'");
	return $comp['COMPANY'];
}

function getCapaLevel($lvlid)
{
	$comp = singRec("select CAPALVL_DESC from HR_CAPA_LEVEL where CAPALVL_ID='" . $lvlid . "'");
	return $comp['CAPALVL_DESC'];
}
function getDeptfromReq($reqid)
{
	$comp = singRec("select hr.dept_id from hr_organogram ho inner join hr_recruitment hr on hr.org_id=ho.id WHERE HR.ID='" . $reqid . "'");
	return $comp['DEPT_ID'];
}
function getDesifromReq($reqid)
{
	$comp = singRec("select hr.desig_id from hr_organogram ho inner join hr_recruitment hr on hr.org_id=ho.id WHERE HR.ID='" . $reqid . "'");
	return $comp['DESIG_ID'];
}
function getLocfromReq($reqid)
{
	$comp = singRec("select hr.ORG_LOC_ID from hr_organogram ho inner join hr_recruitment hr on hr.org_id=ho.id WHERE HR.ID='" . $reqid . "'");
	return $comp['ORG_LOC_ID'];
}
function getDivsnfromReq($reqid)
{
	$comp = singRec("select hr.DIVSN_ID from hr_organogram ho inner join hr_recruitment hr on hr.org_id=ho.id WHERE HR.ID='" . $reqid . "'");
	return $comp['DIVSN_ID'];
}
function stristrarray($array, $str)
{
	$indexes = array();
	$ex = null;
	foreach ($array as $k => $v) {
		if (stristr($str, $v)) {
			$ex = stristr($str, $v);
			continue;
		}
	}
	return $ex;
}
//function to encrypt the string
function encode($str)
{
	for ($i = 0; $i < 5; $i++) {
		$str = strrev(base64_encode($str)); //apply base64 first and then reverse the string
	}
	return $str;
}
//function to decrypt the string
function decode($str)
{
	if (is_array($str)) return;
	for ($i = 0; $i < 5; $i++) {
		//if($_SERVER['REQUEST_METHOD']=='GET' && !empty($_SERVER['QUERY_STRING']))
		$str = base64_decode(strrev($str)); //apply base64 first and then reverse the string}
	}
	return $str;
}
function requestDecoder()
{
	foreach ($_REQUEST as $key => $val) {
		if (substr($key, -3) == 'btn') continue;
		$keysNot = array('logout', 'SESSION', 'REQUEST', 'tempId', 'random', 'PHPSESSID', 'content', 'DELETE', 'query', 'pdf', 'slideshow', 'web_ques');
		if (!is_array($val)) {
			if (encode(decode($val)) === $val) {
				if (decode($val) && !in_array($key, $keysNot)) $_REQUEST[$key] = decode($val);
			}
		}
	}
	foreach ($_GET as $key => $val) {
		if (substr($key, -3) == 'btn') continue;
		$keysNot = array('logout', 'SESSION', 'REQUEST', 'tempId', 'random', 'PHPSESSID', 'content', 'DELETE', 'query', 'pdf', 'slideshow', 'web_ques');
		if (!is_array($val)) {
			if (encode(decode($val)) === $val) {
				if (decode($val) && !in_array($key, $keysNot)) $_GET[$key] = decode($val);
			}
		}
	}
	foreach ($_POST as $key => $val) {
		if (substr($key, -3) == 'btn') continue;
		$keysNot = array('logout', 'SESSION', 'REQUEST', 'tempId', 'random', 'PHPSESSID', 'content', 'DELETE', 'query', 'pdf', 'slideshow', 'web_ques');
		if (!is_array($val)) {
			if (encode(decode($val)) === $val) {
				if (decode($val) && !in_array($key, $keysNot)) $_POST[$key] = decode($val);
			}
		}
	}
}
function myReq($request = null)
{
	#requestDecoder();
	echo '<pre>';
	if (empty($request))
		print_r($_REQUEST);
	else
		print_r($request);
	echo '</pre>';
}
function arr($request = null)
{
	#requestDecoder();
	echo '<pre>';
	if (empty($request))
		print_r($_REQUEST);
	else
		print_r($request);
	echo '</pre>';
}
function redirect($url)
{
	header('location:' . $url);
}
function check_array($array)
{
	if (!is_array($array)) {
		$array = array();
	}
	return $array;
}
function getEmpInfoByCode($id)
{
	$EmpName = singRec("SELECT FNAME,LNAME 
						FROM HR_EMPLOYEE_INFO
						WHERE EMP_CODE = '" . $id . "'");
	$name = $EmpName['FNAME'] . ' ' . $EmpName['LNAME'];
	return ucwords(strtolower($name));
}
function insertLog()
{
	$page_name = end(explode("/", $_SERVER["PHP_SELF"]));
	if (!empty($page_name)) {
		startQry();
		$res = singrec("select ID from sdl_user_access_log 
						where ASON_DATE=trunc(SYSDATE)
						and EMP_ID='" . $_SESSION['EMP_ID'] . "' 
						and COMP_ID='" . $_SESSION['COMP_ID'] . "'
						and ACCESS_URL='" . trim($page_name) . "' 
					");
		if (empty($res['ID']) && $page_name != 'dashboard.php') {
			executeQry(" insert into sdl_user_access_log(
							ID,ASON_DATE,EMP_ID,COMP_ID,ACCESS_URL)
							values(
							null,
							trunc(SYSDATE),
							'" . trim($_SESSION['EMP_ID']) . "',
							'" . trim($_SESSION['COMP_ID']) . "',
							'" . trim($page_name) . "')  
					  ");
		}
		endQry();
	}
}
function encodel($str)
{
	for ($i = 0; $i < 5; $i++) {
		$str = strrev(base64_encode($str));
	}
	return $str;
}
function decodel($str)
{
	for ($i = 0; $i < 5; $i++) {
		$str = base64_decode(strrev($str));
	}
	return $str;
}
function url_encode($in)
{
	return base64_encode(rawurlencode(serialize($in)));
}
function url_decode($in)
{
	return unserialize(rawurldecode(base64_decode($in)));
}
function reptviewlog($rptnm = "")
{
	startQry();
	if ($rptnm == '') {
		$pagenm = end(explode("/", $_SERVER['PHP_SELF']));
		$page = singRec("select LABEL from sdl_submenu where prog_url='" . $pagenm . "' ");
	} else {
		$page['LABEL'] = $rptnm;
	}
	if ($_REQUEST['EMP_ID_hidden']) {
		$emp_cust_id = $_REQUEST['EMP_ID_hidden'];
		$type = "E";
	} else if ($_REQUEST['CUST_ID_hidden']) {
		$emp_cust_id = $_REQUEST['CUST_ID_hidden'];
		$type = "C";
	}
	if (trim($emp_cust_id) != '' and trim($page['LABEL']) != '' and trim($type) != '') {
		executeQry("insert into sdl_download_log(
						ID,DLD_BY,DLD_ON,DLD_FOR,REPT_NAME,RPT_PERIOD,RPT_DT,
						COMP_ID,DLDFOR_TYPE,CHG_ON,CHG_BY
						)
						values(
						null,					
						'" . trim($_SESSION['LOGIN_ID']) . "',
						SYSDATE,
						'" . trim($emp_cust_id) . "',
						'" . trim($page['LABEL']) . "',
						'" . trim($_REQUEST['MONTH']) . "',
						'" . trim($_REQUEST['FROM_DATE']) . "',
						'" . trim($_SESSION['COMP_ID']) . "',
						'" . trim($type) . "',
						SYSDATE,
						'" . trim($_SESSION['LOGIN_ID']) . "' )  ");
	}
	endQry();
}
function reptviewlogblank($rptnm = "")
{
	startQry();
	if ($rptnm == '') {
		$pagenm = end(explode("/", $_SERVER['PHP_SELF']));
		$page = singRec("select LABEL from sdl_submenu where prog_url='" . $pagenm . "' ");
	} else {
		$page['LABEL'] = $rptnm;
	}
	if ($_REQUEST['EMP_ID_hidden']) {
		$emp_cust_id = $_REQUEST['EMP_ID_hidden'];
		$type = "E";
	} else if ($_REQUEST['CUST_ID_hidden']) {
		$emp_cust_id = $_REQUEST['CUST_ID_hidden'];
		$type = "C";
	}
	//if (trim($emp_cust_id) != '' and trim($page['LABEL']) != '' and trim($type) != '')
	//{
	executeQry("insert into sdl_download_log(
						ID,DLD_BY,DLD_ON,DLD_FOR,REPT_NAME,RPT_PERIOD,RPT_DT,
						COMP_ID,DLDFOR_TYPE,CHG_ON,CHG_BY
						)
						values(
						null,					
						'" . trim($_SESSION['LOGIN_ID']) . "',
						SYSDATE,
						'" . trim($emp_cust_id) . "',
						'" . trim($page['LABEL']) . "',
						'" . trim($_REQUEST['MONTH']) . "',
						'" . trim($_REQUEST['FROM_DATE']) . "',
						'" . trim($_SESSION['COMP_ID']) . "',
						'" . trim($type) . "',
						SYSDATE,
						'" . trim($_SESSION['LOGIN_ID']) . "' )  ");
	//}		
	endQry();
}
function routeSeq()
{
	$rt = array();
	for ($i = 1; $i <= 100; $i++) {
		$rt[$i] = $i;
	}
	return $rt;
}
function nbsp($num = 1)
{
	$str = '&nbsp;';
	for ($i = 0; $i < $num; $i++) {
		$str .= '&nbsp;';
	}
	return $str;
}
function getMonthDates($mon = null)
{
	$sql = multiRec("SELECT to_char(TRUNC(to_date('" . $mon . "','Month-yyyy'), 'MM') + LEVEL - 1,'dd-Mon-yyyy') AS day
						FROM dual
						CONNECT BY TRUNC(TRUNC(to_date('" . $mon . "','Month-yyyy'), 'MM') + LEVEL - 1, 'MM') = TRUNC(to_date('" . $mon . "','Month-yyyy'), 'MM') ");
	return singDymention($sql);
}
function getMonthNumber($monthName = null)
{
	$monthName = trim(ucwords(strtolower($monthName)));
	$monarr = array('January' => 10, 'February' => 11, 'March' => 12, 'April' => 1, 'May' => 2, 'June' => 3, 'July' => 4, 'August' => 5, 'September' => 6, 'October' => 7, 'November' => 8, 'December' => 9);
	return  $monarr[$monthName];
}
function getMonths($mon, $num = 1)
{
	if ($num > 0) {
		$monArr = [];
		for ($i = 1; $i <= $num; $i++) {
			$sql = singRec("select to_char(add_months(to_date('" . $mon . "','Month-yyyy'),'" . $i . "'),'Month yyyy') mon from dual ");
			$aa = explode(" ", $sql['MON']);
			$monArr[] = $aa[0] . ' ' . end($aa);
		}
	} else {
		$monArr = [];
		for ($i = $num; $i < 0; $i++) {
			$sql = singRec("select to_char(add_months(to_date('" . $mon . "','Month-yyyy'),'" . $i . "'),'Month yyyy') mon from dual ");
			$aa = explode(" ", $sql['MON']);
			$monArr[] = $aa[0] . ' ' . end($aa);
		}
	}
	return  $monArr;
}
function getFyMonths($fy)
{
	$fyRes = singRec("select to_char(FROM_DT,'dd-Mon-yyyy') formdt ,to_char(TO_DATE,'dd-Mon-yyyy') todt  from HR_FINYEAR where LABEL='" . $fy . "' ");
	$sqlMonth = multiRec("select to_char(which_month, 'Month-yyyy') month from(
							select add_months(to_date('" . $fyRes['FORMDT'] . "','dd-Mon-yyyy'), rownum-1) which_month from dba_objects
							where rownum <= months_between(to_date('" . $fyRes['TODT'] . "','dd-Mon-yyyy'), add_months(to_date('" . $fyRes['FORMDT'] . "','dd-Mon-yyyy'), -1))
							order by which_month
						)
					");
	$monArr = singDymention($sqlMonth);
	return $monArr;
}
function getCumMonths($mon, $year)
{
	$fyRes = singRec("select to_char(FROM_DT,'dd-Mon-yyyy') formdt from HR_FINYEAR
								where TO_DATE('" . $mon . "-" . $year . "','Month-yyyy') between from_dt and to_date ");
	$sqlMonth = multiRec("select to_char(which_month, 'Month-yyyy') month from(
							select add_months(to_date('" . $fyRes['FORMDT'] . "','dd-Mon-yyyy'), rownum-1) which_month from dba_objects
							where rownum <= months_between(to_date('" . $mon . "-" . $year . "','Month-yyyy'), add_months(to_date('" . $fyRes['FORMDT'] . "','dd-Mon-yyyy'), -1))
						order by which_month )
					");
	$monArr = singDymention($sqlMonth);
	return $monArr;
}
function getFyFirstMonth($mon)
{
	$fyRes = singRec("select to_char(FROM_DT,'Month-yyyy') FMON from HR_FINYEAR where to_date('" . $mon . "','Month-yyyy') BETWEEN from_dt and to_date ");
	return $fyRes['FMON'];
}
function getWeakDays()
{
	$wdays = multiRec("select to_char((wdays),'yyyy-mm-dd') from (   
					  Select next_day(trunc(sysdate) - 14, 'Monday') + (Level - 1) wdays
					  From   dual
					  Connect By Level <=14 
					) ");
	return singDymention($wdays);
}
function getLastWeakDays()
{
	$wdays = multiRec("select to_char((wdays),'yyyy-mm-dd') from (   
					  Select next_day(trunc(sysdate) - 8, 'Sunday') - (Level - 1) wdays
					  From   dual
					  Connect By Level <=7 )
					  order by wdays
					 ");
	return singDymention($wdays);
}
function isDateInList($date)
{
	$wdays = singRec("select count(1) CNT from (   
						  Select next_day(trunc(sysdate) - 14, 'Monday') + (Level - 1) wdays
						  From   dual 
						  Connect By Level <=14
						union
						select to_date(DAY_DATE) wdays
							from HR_DAY_PLANS_OPEN
							where  STATUS='A' and emp_id='" . $_SESSION['empId'] . "'
					  )
					where wdays=to_date('" . $date . "')
				");
	if ($wdays['CNT']) return 1;
	else return 0;
}
function getFY($month, $field)
{
	$r = singRec("select " . $field . " from HR_FINYEAR where to_date('" . $month . "','Month-yyyy') between FROM_DT and TO_DATE ");
	return $r[$field];
}
function getBetweenDate($fr, $to)
{
	$r = multiRec("select to_char(to_date('" . $fr . "','dd-Mon-yyyy') + rownum -1,'dd-Mon-yyyy') DT
				from all_objects
				where rownum <= to_date('" . $to . "','dd-Mon-yyyy')-to_date('" . $fr . "','dd-Mon-yyyy')+1 ");
	return singDymention($r);
}
function convertToLacs($string1)
{
	if (strlen($string1) > 2) {
		if (strpos($string1, ',') !== false) {
			$ab = str_replace(",", "", $string1);
			$abc = (int)($ab) / 100000;
		} elseif (strpos($string1, '.') !== false) {
			$abc = (float)$string1;
		} else {
			if (has_letter($string1) && has_number($string1)) {
				$abc = $string1;
			} elseif (has_letter($string1) && !has_number($string1)) {
				$abc = $string1;
			} elseif (!has_letter($string1) && has_number($string1)) {
				$abc = round((int)($string1) / 100000, 2);
			}
		}
	} elseif (strlen($string1) < 2) {
		if (strpos($string1, ',') !== false) {
			$ab = str_replace(",", "", $string1);
			$abc = (int)($ab);
		} else {
			$abc = (int)($string1);
		}
	}
	return $abc;
}
// To check string for letter only	
function has_letter($x)
{
	if (preg_match("/[\p{L}]/u", $x)) {
		return true;
	}
	return false;
}
// To check string for number only
function has_number($x)
{
	if (preg_match("/[\p{N}]/u", $x)) {
		return true;
	}
	return false;
}
//To Calculate total experience
function totExpCal($id)
{
	$diff = 0;
	$expArr = multiRec("select * from HR_CAN_EXPERIENCE where CAN_ID=" . $id . "");
	foreach ($expArr as $curr) {
		$diff = $diff + abs(strtotime($curr['TO_DATE']) - strtotime($curr['FROM_DATE']));
	}
	$years = floor($diff / (365 * 60 * 60 * 24));
	$months = floor(($diff - $years * 365 * 60 * 60 * 24) / (30 * 60 * 60 * 24));
	$tot_exp = $years . ' Years ' . $months . ' Months';
	return $tot_exp;
}
//sql function
function getEmpDivisions($empId, $date = null)
{
	$sql = multiRec("select DIVSN_ID from HR_EMP_DIVISIONS where emp_id='" . $empId . "'
						and nvl('" . $date . "',trunc(sysdate)) between EFFEC_FROM and nvl(EFFEC_TO,'01-Jan-3000') ");
	$empDivArr = singDymention($sql);
	return $empDivArr;
}
function getEmpLevel($empId)
{
	$sql = singRec("select LEVEL_ID from HR_EMPLOYEE_INFO where id='" . $empId . "' ");
	return $sql['LEVEL_ID'];
}
function createTask($taskGrp, $tranId, $tranDesc, $empId, $empIdFor, $status)
{
	$chkTask = singRec("select id from HR_USER_TASKS
						where TASK_GROUP='" . $taskGrp . "'
							and TRAN_ID='" . $tranId . "'
							and EMP_ID='" . $empId . "'
							and STATUS='" . $status . "'
					");
	if ($chkTask['ID']) {
		$newId = $chkTask['ID'];
	} else {
		$newId = executeQry("insert into HR_USER_TASKS(
						ID,TASK_DATE,EXPIRY_DATE,TASK_GROUP,TRAN_ID,TRAN_DESC,EMP_ID,EMP_ID_FOR,STATUS,AUTH_ON,AUTH_BY,REMARKS)
						values (
						null,
						SYSDATE,
						SYSDATE+2,
						'" . $taskGrp . "',
						'" . $tranId . "',
						'" . $tranDesc . "',
						'" . $empId . "',
						'" . $empIdFor . "',
						'" . $status . "',
						null,
						null,
						null
						)
				returning  ID into :newId ", 'newId');
		$checkEmp = singRec("select count(1) CNT from hr_dash_taskdata where EMP_ID='" . $empIdFor . "' ");
		if ($checkEmp['CNT'] == 0) {
			executeQry("Insert into HR_DASH_TASKDATA
							(EMP_ID,DIVSN_ID,HQ_ID,ROUTEAUTH,TOURAUTH,DAYPLANAUTH,NEWCUSTAUTH,DAILYEXPAUTH,MONTHLYEXPAUTH,NONFLDAUTH,
							INPUTUSGAUTH,HQCOMM,CMPTRCOMM,TRADECOMM,MFGCOMM,TECHCOMM,SMPRCP,YRBRAVGAUTH,MNBRTGTAUTH,DPOPENAUTH,MGRDLYEXPAUTH,PMTACTAUTH,
							SCHORDAUTH,SCHINV,SCHINVAUTH,SCHRCP,SCHRCPAUTH,SCHACCTAUTH,SCHGIFTDESP,SCHGIFTRCP,OPDCAMPAIGNAUTH,OPDMATISSUE,OPDCAMPAIGNACT,CMEACTAUTH,CMEMATISSUE,CMEACTCLS
							)
								values
							(
							'" . $empIdFor . "',null,null,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0 )");
		}
		if ($newId && $taskGrp) {
			executeQry("update hr_dash_taskdata set " . $taskGrp . "=nvl(" . $taskGrp . ",0)+1 where emp_id='" . $empIdFor . "' ");
		}
	}
	return $newId;
}
function updateTask($taskId, $status, $remark = null)
{
	$task = singRec("select TASK_GROUP, STATUS, AUTH_BY, to_char(AUTH_ON,'dd-Mon-yyyy') AUTH_ON, to_char(AUTH_ON,'hh24:MI') AUTH_TM
						from HR_USER_TASKS where id='" . $taskId . "' ");
	if ($task['AUTH_BY'] == $_SESSION['EmpCode']) $task['AUTH_BY'] = 'You';
	if ($task['STATUS'] == 'C') {
		$_SESSION['status'] = 'Task Already Authorized By ' . $task['AUTH_BY'] . ' on Dated ' . $task['AUTH_ON'] . ' and Time ' . $task['AUTH_TM'];
		redirect('auth_list.php?grp=' . encode($task['TASK_GROUP']));
		exit;
	} else if ($task['STATUS'] == 'R') {
		$_SESSION['status'] = 'Task Already Rejected By ' . $task['AUTH_BY'] . ' on Dated ' . $task['AUTH_ON'] . ' and Time ' . $task['AUTH_TM'];
		redirect('auth_list.php?grp=' . encode($task['TASK_GROUP']));
		exit;
	} else {
		executeQry("update HR_USER_TASKS set
						status='" . $status . "', remarks='" . $remark . "', AUTH_ON=sysdate, AUTH_BY='" . $_SESSION['EmpCode'] . "'
					where id='" . $taskId . "' ");
		executeQry("update hr_dash_taskdata set " . $task['TASK_GROUP'] . "=" . $task['TASK_GROUP'] . "-1 where emp_id='" . $_SESSION['empId'] . "' ");
	}
}
function getEmpName($empId = null)
{
	$res = singRec("select (FNAME||' ' ||MNAME||' '||LNAME ) EMP_NAME from HR_EMPLOYEE_INFO where id='" . $empId . "' ");
	return $res['EMP_NAME'];
}
function getCandidateInfo($empId = null)
{
	$res = singRec("select a.CND_NAME as NM  from HR_CANDIDATE_INFO a where id='" . $empId . "' ");
	return ucwords(strtolower($res['NM']));
}
function getUserName($loginId = null)
{
	$res = singRec("select (emp_code||' - '||fname||' '||mname||' '||e.LNAME) NM from HR_USERS u 
					inner join HR_EMPLOYEE_INFO e on u.EMP_ID=e.id and u.FLAG='E' 
					where u.LOGIN_ID='" . $loginId . "' ");
	return $res['NM'];
}
function getCustName($custId = null)
{
	$res = singRec("select cust_name from HR_CUSTOMER_INFO where id='" . $custId . "' ");
	return $res['CUST_NAME'];
}
function getPartyName($custId = null)
{
	$res = singRec("select party_name from HR_party_master where id='" . $custId . "' ");
	return $res['PARTY_NAME'];
}
function getItemName($itemId = null)
{
	$res = singRec("select DESCR from HR_ITEM_MASTER where (id='" . $itemId . "' or item_code='" . $itemId . "') ");
	return $res['DESCR'];
}
function getItemCategory($catId = null)
{
	$res = singRec("select CAT_DESC from HR_ITEM_CATEGORY where cat_id='" . $catId . "' ");
	return $res['CAT_DESC'];
}
function getItemGroup($grpId = null)
{
	$res = singRec("select GRP_DESC from HR_ITEM_GROUPS where GRP_ID='" . $grpId . "' ");
	return $res['GRP_DESC'];
}
function getCustIdType($custId, $flag = null)
{
	$res = singRec("select t.CTYP_ID, t.CTYP_DESC 
					from HR_CUSTOMER_INFO c
					inner join HR_CUST_TYPES t on c.CUST_TYPE=t.CTYP_ID 
					where id='" . $custId . "' ");
	if ($flag == 1)
		return $res['CTYP_ID'];
	if ($flag == 2)
		return $res['CTYP_DESC'];
	else $res;
}
function getCustType($ctypId)
{
	$res = singRec("select CTYP_DESC from HR_CUST_TYPES where CTYP_ID='" . $ctypId . "' ");
	return $res['CTYP_DESC'];
}
function getEmpMgr($empId, $flag = null)
{
	$res = singRec("select MANAGER_ID from HR_EMP_MGR
					where emp_id='" . $empId . "'
					and trunc(sysdate) between EFFEC_FROM and nvl(EFFEC_TO,'01-Jan-3000')  ");
	return $res['MANAGER_ID'];
}
function getEmpoffice($empId)
{
	$res = singRec("SELECT ddmonyyyy(b.doj)doj , ddmonyyyy(b.dol)dol , get_division_name(a.divsn_id)div , get_dept_name(a.dept_id)dept , 
						get_design_name(a.desi_id)desig , get_org_loc_name(org_loc_id)loc , a.org_id,a.divsn_id,a.dept_id,c.company,d.TITLE_DESC
						FROM hr_emp_office_det a 
						inner join hr_employee_info b on a.emp_code=b.emp_code
						inner join hr_organogram c on a.org_id = c.id 
						inner join hr_titles d on d.title_id = b.title
						where trunc(sysdate) between effec_from and nvl(effec_to , '01-Mar-3000') and a.emp_code='".$empId."'");
 	return $res;
}
function getEmpofficeDated($empId, $date)
{
	$res = singRec("SELECT ddmonyyyy(b.doj)doj , ddmonyyyy(b.dol)dol , get_division_name(a.divsn_id)div , get_dept_name(a.dept_id)dept , 
						get_design_name(a.desi_id)desig , get_org_loc_name(org_loc_id)loc , a.org_id,a.divsn_id,a.dept_id,c.company
						FROM hr_emp_office_det a 
						inner join hr_employee_info b on a.emp_code=b.emp_code
						inner join hr_organogram c on a.org_id = c.id 
						where to_date('".$date."') between effec_from and nvl(effec_to , '01-Mar-3000') and a.emp_code='".$empId."'");
 	return $res;
}
function getEmpNsmMgr($empId, $flag = null)
{
	$res = singRec(" select id from (
					select id,level_id,manager_id from hr_employee_info 
					start with id='" . $empId . "' 
					CONNECT BY PRIOR manager_id = id 
					) where level_id=1  ");
	return $res['ID'];
}
function getDivisionName($devsnId = null)
{
	$res = singRec("select DIVSN_DESC from HR_DIVISIONS where DIVSN_ID='" . $devsnId . "' ");
	return $res['DIVSN_DESC'];
}
function getDepartmentName($deptId = null)
{
	$res = singRec("select DEPT_DESC from HR_DEPARTMENT where DEPT_CODE='" . $deptId . "' ");
	return $res['DEPT_DESC'];
}
function getDesignationName($desiId = null)
{
	$res = singRec("select DESI_DESC from HR_DESIGNATION where DESI_ID='" . $desiId . "' ");
	return $res['DESI_DESC'];
}
function getEmpCustStgCode($pempid = null, $pcustid = null, $date = null)
{
	$SqlStg = multiRec("select s.STG_CODE from HR_STG_CUSTOMER c 
					inner join HR_STRATEGY s on c.STG_ID=s.ID
					where c.EMP_ID='" . $pempid . "' and c.CUST_ID='" . $pcustid . "'
					and c.status='A'
					and to_date('" . $date . "') between s.STG_FROM and s.stg_to ");
	$stgArr = singDymention($SqlStg);
	return $stgArr;
}
function getStgClassCount($empId = null, $stgId = null)
{
	$specCnt = singRec("select sum(s.DOCT_CNT) DCNT from hr_emp_hq h
						inner join HR_STG_HQ_PDCLS c on h.HQ_ID=c.HQ_ID and c.stg_id='" . $stgId . "' 
						inner join HR_STG_SPEC_COUNT s on c.STG_ID=s.STG_ID and c.PCLS_ID=s.HCLS_ID
						where  h.emp_id='" . $empId . "'  and h.geo_scope='HQ'
						and h.EFFEC_TO is null");
	return $specCnt['DCNT'];
}
function getStgCode($stgId = null)
{
	$s = singRec("select STG_CODE from HR_STRATEGY where id='" . $stgId . "' ");
	return $s['STG_CODE'];
}
function getStgName($stgId = null)
{
	$s = singRec("select STG_DESC from HR_STRATEGY where id='" . $stgId . "' ");
	return $s['STG_DESC'];
}
function getpullCount($empId = null, $divsnId = null, $dt = null)
{
	$p = singRec("select get_hq_pool_cnt ( get_empid_hqlbl('" . $empId . "',last_day(to_date('" . $dt . "','Month-yyyy'))), '" . $divsnId . "', last_day(to_date('" . $dt . "','Month-yyyy'))) CNT from dual ");
	if (!$p['CNT']) return 1;
	else return $p['CNT'];
}
function getHqPullCount($hqLabel = null, $divsnId = null, $dt = null)
{
	$p = singRec("select get_hq_pool_cnt ( '" . $hqLabel . "', '" . $divsnId . "', last_day(to_date('" . $dt . "','Month-yyyy'))) CNT from dual ");
	if (!$p['CNT']) return 1;
	else return $p['CNT'];
}
/*
 * This function is used to create final json response for web services
 * name: createResponse
 * @param : array
 * @return : json string
 * 
 */
function createResponse($arr = null)
{
	// Set header type
	header('Content-Type:application/json');
	echo json_encode($arr);
	exit;
}
/*
 * This function is used to get login id from emp id
 * name: getLoginID
 * @param : int
 * @return : int 
 * 
 */
function getLoginId($empId = null)
{
	$res = singRec("select LOGIN_ID from hr_users where EMP_ID = '" . $empId . "' and status='A'");
	return $res['LOGIN_ID'];
}
/*
 * This function is used to check if user is login 
 * name: checkAppUser
 * @param1 : int
 * @param2 : int
 * @return : boolean
 * 
 */
function checkAppUser($key = null, $empId = null)
{
	$retFlag = '0';
	$res = singRec("select ID from hr_login_log where ID = '" . $key . "' and  EMP_ID = '" . $empId . "'");
	if ($res['ID']) {
		$retFlag = '1';
	}
	return $retFlag;
}
/*
 * This function is used to get emp name and manager id 
 * name: unknown
 * @param
 * @return
 * 
 */

function get_req_info($reqid)
{

	$cmp = singRec("select SH_DESC from hr_company where comp_id='" . getCompfromReq($reqid) . "'");
	$div = singRec("select divsn_desc from hr_divisions where divsn_id='" . getDivsnfromReq($reqid) . "'");
	$dept = singRec("select dept_desc from hr_department where dept_id='" . getDeptfromReq($reqid) . "'");
	$desig = singRec("select DESI_DESC from hr_designation where desi_id='" . getDesifromReq($reqid) . "'");
	$loc = singRec("select GEO_DESC from hr_organogram_loc where id='" . getLocfromReq($reqid) . "'");
	echo $cmp['SH_DESC'] . ' - ' . $div['DIVSN_DESC'] . ' - ' . $dept['DEPT_DESC'] . ' - ' . $desig['DESI_DESC'] . ' - ' . $loc['GEO_DESC'];
}
function getEmpInfo($empId = null)
{
	$res = singRec("select get_emp_nm(id) empName,MANAGER_ID from hr_employee_info
						where ID='" . $empId . "' ");
	return $res;
}
function getScreenerInfo($Id)
{
	$res = singRec("SELECT (FNAME||' '||MNAME||' '||LNAME) AS SCREENER 
		            FROM hr_employee_info
				    WHERE EMP_CODE='" . $Id . "'");
	return $res;
}
function getTaskGroupId($group)
{
	$g = singRec("select id from hr_task_master where TASK_GRP='" . $group . "' ");
	return $g['ID'];
}
function bcs_bulk_mail($emailArr = array(), $emailArrCC = array(), $emailArrBCC = array(), $sub = null, $mailBody = null, $attchUrl = array(), $mailDescr = null)
{
	$body1 = substr($mailBody, 0, 3500);
	$body2 = substr($mailBody, 3500, 3500);
	$body3 = substr($mailBody, 7000, 3500);
	$body4 = substr($mailBody, 10500, 3500);
	$body5 = substr($mailBody, 14000, 3500);
	$newid = executeQry("insert into hr_bcs_mailbox(
						ID,SUBJECT,MAIL_BODY,MAIL_BODY1,MAIL_BODY2,MAIL_BODY3,MAIL_BODY4,ATTACHMENT,STATUS,CHG_ON,CHG_BY,MAIL_DESCR)values( 
						null,
						'" . trim($sub) . "',
						'" . trim($body1) . "',
						'" . trim($body2) . "',
						'" . trim($body3) . "',
						'" . trim($body4) . "',
						'" . trim($body5) . "',
						null,
						'N',
						SYSDATE,
						'" . ($_SESSION['userId'] ? $_SESSION['userId'] : 'SYSTEM') . "',
						'" . $mailDescr . "'
						)returning id into :newid", 'newid');
	foreach (check_array($attchUrl) as $attc) {
		executeQry("insert into hr_BCS_MAILBOX_ATTACHMENT(
					ID,MAIL_ID,ATTACHMENT)
					values( 
					null,
					'" . $newid . "',
					'" . $attc . "'
				)");
	}
	if (count(check_array($emailArr))) {
		executeQry("insert into hr_bcs_mailbox_details(
					ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC)
					values( 
					null,
					'" . $newid . "',
					'" . implode(",", check_array($emailArr)) . "',
					'" . implode(",", check_array($emailArrCC)) . "',
					'" . implode(",", check_array($emailArrBCC)) . "'
				)");
	}
	return $newid;
}
function getAttenReq($empid)
{
	$skipdivisions = array();
	$skipdivisions = [4, 6];
	$allempdivs = getEmpDivisions($empid);
	$ret = 0;
	foreach ($skipdivisions as $a) {
		if (in_array($a, $allempdivs)) {
			$ret = 1;
		}
	}
	return $ret;
}
function getRouteName($empId, $hq, $rt)
{
	$res = singRec("select get_townid_name(town_id) town from hr_route_plans
					where hq_id='" . $hq . "'
					and emp_id='" . $empId . "'
					and route_seq='" . $rt . "'
					and status='A'");
	return $res['TOWN'];
}
function getEmpDesc($keyVal, $keyCol, $outcols = array())
{
	if (count($outcols)) {
		$cols = implode(',', $outcols);
	} else {
		$cols = '*';
	}
	$empinfo = singRec("select " . $cols . " from HR_EMPLOYEE_INFO where " . $keyCol . "='" . $keyVal . "' ");
	return $empinfo;
}
function getOrgLabelSeq($num)
{
	return sprintf("%'.05d\n", $num);
}
function in_array_any($needles, $haystack)
{
	return !empty(array_intersect($needles, $haystack));
}
function validate_data($data)
{
	$data = trim($data);
	$data = stripslashes($data);
	return $data;
}
function empCompData()
{
	$emp_complience = array();
	$x = 0;
	$offcial_email = singRec("SELECT count(*) as off_email 
		                	FROM HR_EMPLOYEE_INFO HEI
		                	left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                	left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                	left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                	left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
		                	WHERE HEI.com_email is null 
							AND HEI.status = 'A'
		                	order by HEI.ID desc");
	$cell = singRec("SELECT count(*) as cellno 
                   FROM HR_EMPLOYEE_INFO HEI
                   left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
                   left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
                   left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
                   left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
				   WHERE HEI.cell is null 
				   AND HEI.status = 'A'");
	$uid = singRec("SELECT count(*) as aadhar 
                  FROM HR_EMPLOYEE_INFO HEI
                  left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
                  left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
                  left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
                  left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
				  WHERE HEI.adhar_no is null 
				  AND HEI.status = 'A'");
	$gender = singRec("SELECT count(*) as gen 
                  	 FROM HR_EMPLOYEE_INFO HEI
                     left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
                     left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
                     left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
                     left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
					 WHERE HEI.gender is null 
					 AND HEI.status = 'A'");
	$marrigests = singRec("SELECT count(*) as marri 
                  	 	 FROM HR_EMPLOYEE_INFO HEI
                         left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
                         left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
                         left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
                         left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
						 WHERE HEI.m_status is null 
						 AND HEI.status = 'A'");
	$pan = singRec("SELECT count(*) as panno 
                  FROM HR_EMPLOYEE_INFO HEI
                  left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
                  left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
                  left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
                  left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
				  WHERE HEI.it_no is null 
				  AND HEI.status = 'A'");
	$mname = singRec("SELECT count(*) as midname 
                    FROM HR_EMPLOYEE_INFO HEI
                    left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
                    left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
                    left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
                    left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
					WHERE (HEI.mname = '.'
					OR HEI.mname = ''
					OR HEI.mname = ' ')
					AND HEI.status = 'A'");
	if ($offcial_email['OFF_EMAIL'] != 0) {
		$emp_complience[$x]['country'] = 'Email';
		$emp_complience[$x]['litres'] = (int)$offcial_email['OFF_EMAIL'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a1';
		$x++;
	}
	if ($cell['CELLNO'] != 0) {
		$emp_complience[$x]['country'] = 'Mobile';
		$emp_complience[$x]['litres'] = (int)$cell['CELLNO'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a2';
		$x++;
	}
	if ($uid['AADHAR'] != 0) {
		$emp_complience[$x]['country'] = 'UID';
		$emp_complience[$x]['litres'] = (int)$uid['AADHAR'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a3';
		$x++;
	}
	if ($gender['GEN'] != 0) {
		$emp_complience[$x]['country'] = 'Gender';
		$emp_complience[$x]['litres'] = (int)$gender['GEN'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a4';
		$x++;
	}
	if ($marrigests['MARRI'] != 0) {
		$emp_complience[$x]['country'] = 'Married';
		$emp_complience[$x]['litres'] = (int)$marrigests['MARRI'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a5';
		$x++;
	}
	if ($pan['PANNO'] != 0) {
		$emp_complience[$x]['country'] = 'Pan No';
		$emp_complience[$x]['litres'] = (int)$pan['PANNO'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a6';
		$x++;
	}
	if ($mname['MIDNAME'] != 0) {
		$emp_complience[$x]['country'] = 'Middle Name';
		$emp_complience[$x]['litres'] = (int)$mname['MIDNAME'];
		$emp_complience[$x]['url'] = 'emp_complience_wise_list.php?emptype=a7';
		$x++;
	}
	return $emp_complience;
}
// function vacanStsData()
// {
// 	$vacan_sts = array();
// 	$y = 0;
// 	$applic_count = singRec("SELECT count(HCAD.ID) as appcnt 
// 					       FROM  HR_CANDIDATE_INFO HCA
// 					       INNER JOIN HR_CAN_APPLICATION_DETAILS HCAD ON HCAD.CAN_ID = HCA.ID
// 					       INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HCAD.ORG_ID
// 					       WHERE HO.DIVSN_ID IN(SELECT DISTINCT(DIVISION_ID) 
// 					                            FROM HR_PROFILE_DIVISIONS 
// 		                                        WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))");
// 	$inprocc_count = singRec("SELECT count(HCAD.ID) as inproces 
// 					        FROM  HR_CANDIDATE_INFO HCA
// 					        INNER JOIN HR_CAN_APPLICATION_DETAILS HCAD ON HCAD.CAN_ID = HCA.ID
// 					        INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HCAD.ORG_ID
// 					        WHERE HO.DIVSN_ID IN(SELECT DISTINCT(DIVISION_ID) 
// 					                             FROM HR_PROFILE_DIVISIONS 
// 		                                         WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))
// 		                    AND HCAD.POOL_STATUS = 'I'");
// 	$shortli_count = singRec("SELECT count(HCAD.ID) as shortlist
// 					        FROM  HR_CANDIDATE_INFO HCA
// 					        INNER JOIN HR_CAN_APPLICATION_DETAILS HCAD ON HCAD.CAN_ID = HCA.ID
// 					        INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HCAD.ORG_ID
// 					        WHERE HO.DIVSN_ID IN(SELECT DISTINCT(DIVISION_ID) 
// 					                             FROM HR_PROFILE_DIVISIONS 
// 		                                         WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))
// 		                    AND HCAD.POOL_STATUS = 'S'");
// 	$rejct_count = singRec("SELECT count(HCAD.ID) as rejct 
// 					      FROM  HR_CANDIDATE_INFO HCA
// 					      INNER JOIN HR_CAN_APPLICATION_DETAILS HCAD ON HCAD.CAN_ID = HCA.ID
// 					      INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HCAD.ORG_ID
// 					      WHERE HO.DIVSN_ID IN(SELECT DISTINCT(DIVISION_ID) 
// 					                             FROM HR_PROFILE_DIVISIONS 
// 		                                         WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))
// 		                  AND HCAD.POOL_STATUS = 'N'");
// 	if ($applic_count['APPCNT'] != 0) {
// 		$vacan_sts[$y]['country'] = 'Applicant';
// 		$vacan_sts[$y]['visits'] = (int)$applic_count['APPCNT'];
// 		$vacan_sts[$y]['url'] = 'applicant_info_list.php?applictype=1';
// 		$y++;
// 	}
// 	if ($inprocc_count['INPROCES'] != 0) {
// 		$vacan_sts[$y]['country'] = 'In Process';
// 		$vacan_sts[$y]['visits'] = (int)$inprocc_count['INPROCES'];
// 		$vacan_sts[$y]['url'] = 'applicant_info_list.php?applictype=2';
// 		$y++;
// 	}
// 	if ($shortli_count['SHORTLIST'] != 0) {
// 		$vacan_sts[$y]['country'] = 'Shortlisted';
// 		$vacan_sts[$y]['visits'] = (int)$shortli_count['SHORTLIST'];
// 		$vacan_sts[$y]['url'] = 'applicant_info_list.php?applictype=3';
// 		$y++;
// 	}
// 	if ($rejct_count['REJCT'] != 0) {
// 		$vacan_sts[$y]['country'] = 'Rejected';
// 		$vacan_sts[$y]['visits'] = (int)$rejct_count['REJCT'];
// 		$vacan_sts[$y]['url'] = 'applicant_info_list.php?applictype=4';
// 		$y++;
// 	}
// 	return $vacan_sts;
// }
function empPositiSts()
{
	$date1 = date("d-M-y", strtotime(date('Y-m-01')));
	$date2 = date("d-M-y", strtotime(date('Y-m-t')));
	$date3 = date("d-M-y", strtotime("-7 months", strtotime($date2)));
	$emp_position_sts = array();
	$z = 0;
	$prob_count = singRec("select count(hei.id) as prob 
						 from hr_employee_info hei 
						 inner join hr_organogram ho on ho.id = hei.org_id 
						 where ho.company IN(SELECT DISTINCT(comp_id) 
					                         FROM HR_PROFILE_company 
		                                     WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))
						 and hei.probation_date between '" . $date1 . "' AND '" . $date2 . "'
						 and hei.probation = 'Y'
						 and hei.status = 'A'");
	$retir_count = singRec("select count(hei.id) as retir
						 from hr_employee_info hei 
						 inner join hr_organogram ho on ho.id = hei.org_id 
						 where ho.company IN(SELECT DISTINCT(comp_id) 
					                         FROM HR_PROFILE_company 
		                                     WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))
			        	 and (add_months(hei.dob,(hei.retire_age * 12))) between '" . $date3 . "' AND '" . $date2 . "' 
						 and hei.status = 'A'");
	$conf_count = singRec("SELECT count(hei.id) as confirm
						 from hr_employee_info hei 
						 inner join hr_organogram ho on ho.id = hei.org_id 
						 inner join hr_employee_period hp on hei.emp_code = hp.emp_code
						 LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
						 LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
						 LEFT join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
						 LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
						 where ho.company IN(SELECT DISTINCT(comp_id) 
					                         FROM HR_PROFILE_company 
		                                     WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . ")) 
						 and '" . $date2 . "' >= hp.to_date
						 AND hei.status = 'A'
						 AND hp.emp_type = 5 
						 AND '" . $date2 . "' between hp.from_date and nvl(hp.to_date, '31-Dec-3000')");
	$contra_count = singRec("select count(hei.id) as contract
		                  FROM HR_EMPLOYEE_INFO HEI
						  inner join hr_organogram ho on ho.id = hei.org_id 
		                  LEFT join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                  LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                  LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                  LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
		                  INNER JOIN hr_employee_period HEP on HEP.EMP_CODE = HEI.EMP_CODE
		                  WHERE HO.COMPANY IN(SELECT DISTINCT(comp_id) 
						 	                  FROM HR_PROFILE_company 
						 	                  WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))  
						  AND HEP.TO_DATE - 210 between '" . $date1 . "' AND '" . $date2 . "'
		                  AND HEI.STATUS = 'A' AND HEP.EMP_TYPE = 2");
	$tempora_count = singRec("select count(hei.id) as confirm
						 	from hr_employee_info hei 
						 	inner join hr_employee_period hep on hep.emp_code = hei.emp_code
						 	inner join hr_organogram ho on ho.id = hei.org_id 
						 	where ho.company IN(SELECT DISTINCT(comp_id) 
					                            FROM HR_PROFILE_company 
		                                        WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . ")) 
							and sysdate between hep.FROM_DATE AND hep.TO_DATE
						    and hei.emp_type = 4
						    and hei.status = 'A'");
	if ($prob_count['PROB'] != 0) {
		$emp_position_sts[$z]['country'] = 'probationary';
		$emp_position_sts[$z]['value'] = (int)$prob_count['PROB'];
		$emp_position_sts[$z]['url'] = 'emp_sts_wise_list.php?emptype=1';
		$z++;
	}
	if ($retir_count['RETIR'] != 0) {
		$emp_position_sts[$z]['country'] = 'Retired';
		$emp_position_sts[$z]['value'] = (int)$retir_count['RETIR'];
		$emp_position_sts[$z]['url'] = 'emp_sts_wise_list.php?emptype=2';
		$z++;
	}
	if ($conf_count['CONFIRM'] != 0) {
		$emp_position_sts[$z]['country'] = 'Confirmed';
		$emp_position_sts[$z]['value'] = (int)$conf_count['CONFIRM'];
		$emp_position_sts[$z]['url'] = 'emp_sts_wise_list.php?emptype=3';
		$z++;
	}
	if ($contra_count['CONTRACT'] != 0) {
		$emp_position_sts[$z]['country'] = 'Contractual';
		$emp_position_sts[$z]['value'] = (int)$contra_count['CONTRACT'];
		$emp_position_sts[$z]['url'] = 'emp_sts_wise_list.php?emptype=4';
		$z++;
	}
	if ($tempora_count['TEMPOR'] != 0) {
		$emp_position_sts[$z]['country'] = 'Temporary';
		$emp_position_sts[$z]['value'] = (int)$tempora_count['TEMPOR'];
		$emp_position_sts[$z]['url'] = 'emp_sts_wise_list.php?emptype=5';
		$z++;
	}
	return $emp_position_sts;
}
function candComplience()
{
	$candi_complien = array();
	$a = 0;
	$educ_comp = singRec("SELECT COUNT(*) abc
						FROM HR_CANDIDATE_INFO
						WHERE NOT EXISTS(SELECT DISTINCT(CAN_ID) 
						                 FROM HR_CAN_EDUCATION 
						                 WHERE HR_CAN_EDUCATION.CAN_ID = HR_CANDIDATE_INFO.ID)
						ORDER BY ID DESC");
	$experi_comp = singRec("SELECT COUNT(*) abc
						  FROM HR_CANDIDATE_INFO
						  WHERE NOT EXISTS(SELECT DISTINCT(CAN_ID) 
						                   FROM HR_CAN_EXPERIENCE 
						                   WHERE HR_CAN_EXPERIENCE.CAN_ID = HR_CANDIDATE_INFO.ID)
						  ORDER BY ID DESC");
	$fam_deait_comp = singRec("SELECT COUNT(*) abc
						     FROM HR_CANDIDATE_INFO
						     WHERE NOT EXISTS(SELECT DISTINCT(CAN_ID) 
						                      FROM HR_CAN_FAMILY_INFO 
						                      WHERE HR_CAN_FAMILY_INFO.CAN_ID = HR_CANDIDATE_INFO.ID)
						     ORDER BY ID DESC");
	$refer_comp = singRec("SELECT COUNT(*) abc
						 FROM HR_CANDIDATE_INFO
						 WHERE NOT EXISTS(SELECT DISTINCT(CAN_ID) 
						                  FROM HR_CAN_REFERENCE 
						                  WHERE HR_CAN_REFERENCE.CAN_ID = HR_CANDIDATE_INFO.ID)
						 ORDER BY ID DESC");
	$social_comp = singRec("SELECT COUNT(*) abc
						FROM HR_CANDIDATE_INFO
						WHERE NOT EXISTS(SELECT DISTINCT(CAN_ID) 
						                 FROM HR_CAN_SOCIALMEDIA 
						                 WHERE HR_CAN_SOCIALMEDIA.CAN_ID = HR_CANDIDATE_INFO.ID)
						ORDER BY ID DESC");
	if ($educ_comp['ABC'] != 0) {
		$candi_complien[$a]['country'] = 'Educational';
		$candi_complien[$a]['value'] = (int)$educ_comp['ABC'];
		//$candi_complien[$a]['url']='candidate_info_list.php?cantype=1';
		$a++;
	}
	if ($experi_comp['ABC'] != 0) {
		$candi_complien[$a]['country'] = 'Experience';
		$candi_complien[$a]['value'] = (int)$experi_comp['ABC'];
		//$candi_complien[$a]['url']='candidate_info_list.php?cantype=2';
		$a++;
	}
	if ($fam_deait_comp['ABC'] != 0) {
		$candi_complien[$a]['country'] = 'Family';
		$candi_complien[$a]['value'] = (int)$fam_deait_comp['ABC'];
		//$candi_complien[$a]['url']='candidate_info_list.php?cantype=3';
		$a++;
	}
	if ($refer_comp['ABC'] != 0) {
		$candi_complien[$a]['country'] = 'Referential';
		$candi_complien[$a]['value'] = (int)$refer_comp['ABC'];
		//$candi_complien[$a]['url']='candidate_info_list.php?cantype=4';
		$a++;
	}
	if ($social_comp['ABC'] != 0) {
		$candi_complien[$a]['country'] = 'Social Media';
		$candi_complien[$a]['value'] = (int)$social_comp['ABC'];
		//$candi_complien[$a]['url']='candidate_info_list.php?cantype=5';
		$a++;
	}
	return $candi_complien;
}
// function candType($num)
// {
// 	//$candidateType : 1:education_comp,2:experience_comp,3:family_detail,4:reference_comp,5:social_comp
// 	if ($num == 0) {
// 		$sqlEm = multiRec(" (SELECT ID,CND_NAME,ddmonyyyy(CHG_ON) CHG_ON, ISMAILSENT ,
// 			                    (CND_ADDRESS||' ' ||CND_CITY||' '||CND_PINCODE||' '||CND_STATE) address, 
// 			                    CND_MOBILE,CND_EMAIL,DOC_PATH,CND_QUALIFICATION,
// 								CND_NOTICE,ddmonyyyy(DOB)DOB,YRSEXP,LAST_DESIG,SCREEN_REMARKS,POOL_STATUS,
// 								decode(POOL_STATUS,'Y','Keep In CV Pool','N','Rejected','H','On Hold','I',
// 								'Interview Process','S','Shortlisted','P','Prospect') POOL_STATUS_DISPLAY, 
// 								chg_on ord_chg_by,CND_LOCALITY,get_design_name(DESIG_ID) applied_for,
// 								get_emptitle(title_id) title,get_req_title(req_id) req_title, get_can_mappid(id) mappid, req_id 
// 						   FROM HR_CANDIDATE_INFO where DESIG_ID is null and ID not in (select CAN_ID from hr_can_application_details))
// 					   ORDER BY ID DESC");
// 	} elseif ($num == 1) {
// 		$sqlEm = multiRec("SELECT ID,CND_NAME,ddmonyyyy(CHG_ON) CHG_ON, ISMAILSENT ,
// 			                    (CND_ADDRESS||' ' ||CND_CITY||' '||CND_PINCODE||' '||CND_STATE) address, 
// 			                    CND_MOBILE,CND_EMAIL,DOC_PATH,CND_QUALIFICATION,
// 								CND_NOTICE,ddmonyyyy(DOB)DOB,YRSEXP,LAST_DESIG,SCREEN_REMARKS,POOL_STATUS,
// 								decode(POOL_STATUS,'Y','Keep In CV Pool','N','Rejected','H','On Hold','I',
// 								'Interview Process','S','Shortlisted','P','Prospect') POOL_STATUS_DISPLAY, 
// 								chg_on ord_chg_by,CND_LOCALITY,get_design_name(DESIG_ID) applied_for,
// 								get_emptitle(title_id) title,get_req_title(req_id) req_title, get_can_mappid(id) mappid, req_id 
// 						   FROM HR_CANDIDATE_INFO where DESIG_ID is null and ID not in (select CAN_ID from hr_can_application_details))
// 					   ORDER BY ID DESC");
// 	} elseif ($num == 2) {
// 		$sqlEm = multiRec("SELECT ID,CND_NAME,ddmonyyyy(CHG_ON) CHG_ON, ISMAILSENT ,
// 			                    (CND_ADDRESS||' ' ||CND_CITY||' '||CND_PINCODE||' '||CND_STATE) address, 
// 			                    CND_MOBILE,CND_EMAIL,DOC_PATH,CND_QUALIFICATION,
// 								CND_NOTICE,ddmonyyyy(DOB)DOB,YRSEXP,LAST_DESIG,SCREEN_REMARKS,POOL_STATUS,
// 								decode(POOL_STATUS,'Y','Keep In CV Pool','N','Rejected','H','On Hold','I',
// 								'Interview Process','S','Shortlisted','P','Prospect') POOL_STATUS_DISPLAY, 
// 								chg_on ord_chg_by,CND_LOCALITY,get_design_name(DESIG_ID) applied_for,
// 								get_emptitle(title_id) title,get_req_title(req_id) req_title, get_can_mappid(id) mappid, req_id 
// 						   FROM HR_CANDIDATE_INFO where DESIG_ID is null and ID not in (select CAN_ID from hr_can_application_details))
// 					   ORDER BY ID DESC");
// 	} elseif ($num == 3) {
// 		$sqlEm = multiRec("SELECT ID,CND_NAME,ddmonyyyy(CHG_ON) CHG_ON, ISMAILSENT ,
// 			                    (CND_ADDRESS||' ' ||CND_CITY||' '||CND_PINCODE||' '||CND_STATE) address, 
// 			                    CND_MOBILE,CND_EMAIL,DOC_PATH,CND_QUALIFICATION,
// 								CND_NOTICE,ddmonyyyy(DOB)DOB,YRSEXP,LAST_DESIG,SCREEN_REMARKS,POOL_STATUS,
// 								decode(POOL_STATUS,'Y','Keep In CV Pool','N','Rejected','H','On Hold','I',
// 								'Interview Process','S','Shortlisted','P','Prospect') POOL_STATUS_DISPLAY, 
// 								chg_on ord_chg_by,CND_LOCALITY,get_design_name(DESIG_ID) applied_for,
// 								get_emptitle(title_id) title,get_req_title(req_id) req_title, get_can_mappid(id) mappid, req_id 
// 						   FROM HR_CANDIDATE_INFO where DESIG_ID is null and ID not in (select CAN_ID from hr_can_application_details))
// 					   ORDER BY ID DESC");
// 	} elseif ($num == 4) {
// 		$sqlEm = multiRec("SELECT ID,CND_NAME,ddmonyyyy(CHG_ON) CHG_ON, ISMAILSENT,
// 			                    (CND_ADDRESS||' ' ||CND_CITY||' '||CND_PINCODE||' '||CND_STATE) address, 
// 			                    CND_MOBILE,CND_EMAIL,DOC_PATH,CND_QUALIFICATION,
// 								CND_NOTICE,ddmonyyyy(DOB)DOB,YRSEXP,LAST_DESIG,SCREEN_REMARKS,POOL_STATUS,
// 								decode(POOL_STATUS,'Y','Keep In CV Pool','N','Rejected','H','On Hold','I',
// 								'Interview Process','S','Shortlisted','P','Prospect') POOL_STATUS_DISPLAY, 
// 								chg_on ord_chg_by,CND_LOCALITY,get_design_name(DESIG_ID) applied_for,
// 								get_emptitle(title_id) title,get_req_title(req_id) req_title, get_can_mappid(id) mappid, req_id 
// 						   FROM HR_CANDIDATE_INFO where DESIG_ID is null and ID not in (select CAN_ID from hr_can_application_details))
// 					   ORDER BY ID DESC");
// 	} elseif ($num == 5) {
// 		$sqlEm = multiRec("SELECT ID,CND_NAME,ddmonyyyy(CHG_ON) CHG_ON, ISMAILSENT ,
// 			                    (CND_ADDRESS||' ' ||CND_CITY||' '||CND_PINCODE||' '||CND_STATE) address, 
// 			                    CND_MOBILE,CND_EMAIL,DOC_PATH,CND_QUALIFICATION,
// 								CND_NOTICE,ddmonyyyy(DOB)DOB,YRSEXP,LAST_DESIG,SCREEN_REMARKS,POOL_STATUS,
// 								decode(POOL_STATUS,'Y','Keep In CV Pool','N','Rejected','H','On Hold','I',
// 								'Interview Process','S','Shortlisted','P','Prospect') POOL_STATUS_DISPLAY, 
// 								chg_on ord_chg_by,CND_LOCALITY,get_design_name(DESIG_ID) applied_for,
// 								get_emptitle(title_id) title,get_req_title(req_id) req_title, get_can_mappid(id) mappid, req_id 
// 						   FROM HR_CANDIDATE_INFO where DESIG_ID is null and ID not in (select CAN_ID from hr_can_application_details))
// 					   ORDER BY ID DESC");
// 	}
// 	return $sqlEm;
// }
function empPositionData($num)
{
	//$emptype : 1:Probationary, 2:Retired, 3:Confirmation, 4:Contract, 5:Temporary
	$date1 = date("d-M-y", strtotime(date('Y-m-01')));
	//$date1 =  "2021-07-01";
	$date2 = date("d-M-y", strtotime(date('Y-m-t')));
	$date3 = date("d-M-y", strtotime("-7 months", strtotime($date2)));
	if ($num == 1) {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                    HD.DEPT_DESC,HDEG.DESI_DESC,HC.COMP_DESC,
		                    (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME
		                 FROM HR_EMPLOYEE_INFO HEI
		                 LEFT JOIN HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                 LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                 LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                 LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID 
		                 WHERE HEI.STATUS ='A'
		                 AND HEI.PROBATION = 'Y'
		                 AND HEI.PROBATION_DATE BETWEEN '" . $date1 . "' AND '" . $date2 . "'
		                 ORDER BY HEI.ID DESC");
	} elseif ($num == 2) {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		     HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		     HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                         HD.DEPT_DESC,HDEG.DESI_DESC,HC.COMP_DESC,
		                         add_months(HEI.DOB,(HEI.RETIRE_AGE * 12)) RETIREDATE,
		                    	 (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME
		                  from HR_EMPLOYEE_INFO HEI
		                  LEFT JOIN HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                  LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                  LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                  LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID 
		                  WHERE HEI.STATUS ='A'
		                  AND (add_months(HEI.DOB,(HEI.RETIRE_AGE * 12))) between '" . $date3 . "' AND '" . $date2 . "'
		                  ORDER BY HEI.ID desc");
	} elseif ($num == 3) {
		$sqlEm = multiRec("SELECT DISTINCT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               			HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               			HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                    	HD.DEPT_DESC,HDEG.DESI_DESC,HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME
		                  FROM HR_EMPLOYEE_INFO HEI
						  inner join hr_organogram ho on ho.id = hei.org_id 
						  inner join hr_employee_period hp on hei.emp_code = hp.emp_code
						  LEFT join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                  LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                  LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                  LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
		                  WHERE HO.COMPANY IN(SELECT DISTINCT(comp_id) 
						 	                  FROM HR_PROFILE_company 
						 	                  WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))  
		                  AND '" . $date2 . "' between hp.from_date and nvl(hp.to_date, '31-Dec-3000') 
						  AND '" . $date2 . "' >=  hp.to_date
		                  AND HEI.STATUS = 'A' AND hp.emp_type = 5 
						  ORDER BY HEI.ID desc");
	} elseif ($num == 4) {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		     HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		     HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                    	 HD.DEPT_DESC,HDEG.DESI_DESC,HC.COMP_DESC,
		                    	 (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME, HEP.FROM_DATE, HEP.TO_DATE
		                  FROM HR_EMPLOYEE_INFO HEI
						  inner join hr_organogram ho on ho.id = hei.org_id 
		                  LEFT join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                  LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                  LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                  LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
		                  INNER JOIN hr_employee_period HEP on HEP.EMP_CODE = HEI.EMP_CODE
		                  WHERE HO.COMPANY IN(SELECT DISTINCT(comp_id) 
						 	                  FROM HR_PROFILE_company 
						 	                  WHERE PROFILE_ID IN (" . implode(',', $_SESSION['empProfile']) . "))  
						  AND HEP.TO_DATE - 210 between '" . $date1 . "' AND '" . $date2 . "'
		                  AND HEI.STATUS = 'A' AND HEP.EMP_TYPE = 2
						  ORDER BY HEI.ID desc");
	} elseif ($num == 5) {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		     HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		     HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                         HD.DEPT_DESC,HDEG.DESI_DESC,HC.COMP_DESC,
		                    	 (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME
		                  FROM HR_EMPLOYEE_INFO HEI
		                  LEFT JOIN HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
		                  LEFT JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
		                  LEFT JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
		                  LEFT JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
		                  INNER JOIN hr_employee_period HEP on HEP.EMP_CODE = HEI.EMP_CODE 
		                  WHERE sysdate between HEP.FROM_DATE AND HEP.TO_DATE
		                  AND HEI.STATUS = 'A'
		                  AND HEI.EMP_TYPE = 4
		                  ORDER BY HEI.ID desc");
	}
	return $sqlEm;
}
function empComplinceData($num)
{
	if ($num == 'a1') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,HEI.COM_EMAIL email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
	                	 WHERE HEI.com_email is null 
						 AND HEI.status = 'A'
	                	 order by HEI.ID desc");
	} elseif ($num == 'a2') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
				   		 WHERE HEI.cell is null 
				   		 AND HEI.status = 'A'");
	} elseif ($num == 'a3') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
				         WHERE HEI.adhar_no is null 
				         AND HEI.status = 'A'");
	} elseif ($num == 'a4') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
					 	 WHERE HEI.gender is null 
					 	 AND HEI.status = 'A'");
	} elseif ($num == 'a5') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
						 WHERE HEI.m_status is null 
						 AND HEI.status = 'A'");
	} elseif ($num == 'a6') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
					     WHERE HEI.it_no is null 
					     AND HEI.status = 'A'");
	} elseif ($num == 'a7') {
		$sqlEm = multiRec("SELECT HEI.ID, HEI.EMP_CODE, (HEI.FNAME||' '||HEI.MNAME||' '||HEI.LNAME) empname,
		               		    HEI.CELL,nvl(HEI.PER_EMAIL,HEI.COM_EMAIL) email,to_char(HEI.DOJ,'dd-Mon-yyyy')doj,
		               		    HEI.DEPT_ID,HEI.DESIGNATION,HEI.EMP_COMPANY,HEI.MANAGER_ID,
		                        HD.DEPT_DESC,
		                        HDEG.DESI_DESC,
		                        HC.COMP_DESC,
		                        (HEINFO.FNAME||' '||HEINFO.MNAME||' '||HEINFO.LNAME) NAME 
	                	 FROM HR_EMPLOYEE_INFO HEI
	                	 left join HR_DEPARTMENT HD ON HD.DEPT_CODE = HEI.DEPT_CODE
	                	 left JOIN HR_DESIGNATION HDEG ON HDEG.DESI_ID = HEI.DESIGNATION
	                	 left JOIN HR_COMPANY HC ON HC.COMP_ID = HEI.EMP_COMPANY
	                	 left JOIN HR_EMPLOYEE_INFO HEINFO ON HEINFO.ID = HEI.MANAGER_ID
					     WHERE (HEI.mname = '.'
					     OR HEI.mname = ''
					     OR HEI.mname = ' ')
					     AND HEI.status = 'A'");
	}
	return $sqlEm;
}
function profileStsSolidGauge($num1)
{
	$maxvalue  = 100;
	$prof_sts = array();
	//=======================For Basic Info total percentage=========================// 
	$point = 0;
	$openPostInfo = singRec("SELECT CND_FNAME,CND_MNAME,CND_LNAME,CND_MOBILE,CND_EMAIL,
		                          DOB,GEND_ID,CND_ADDR_LINE1,CND_ADDR_LINE2,CND_LOCALITY,
		                          CND_CITY,CND_STATE,IMAGE_PATH
		                     FROM hr_candidate_info
						    WHERE id = " . $num1 . "");
	if ($openPostInfo) {
		if ($openPostInfo['CND_FNAME'] != '') {
			$point = $point + 8;
		} else if ($openPostInfo['CND_FNAME'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_MNAME'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_MNAME'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_LNAME'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_LNAME'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_MOBILE'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_MOBILE'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_EMAIL'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_EMAIL'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['DOB'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['DOB'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['GEND_ID'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['GEND_ID'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_ADDR_LINE1'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_ADDR_LINE1'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_ADDR_LINE2'] != '') {
			$point  = $point + 6;
		} else if ($openPostInfo['CND_ADDR_LINE2'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_LOCALITY'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_LOCALITY'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_CITY'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_CITY'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_STATE'] != '') {
			$point  = $point + 8;
		} else if ($openPostInfo['CND_STATE'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['IMAGE_PATH'] != NULL) {
			$point  = $point + 6;
		} else if ($openPostInfo['IMAGE_PATH'] == NULL) {
			$point = $point += 0;
		}
	}
	$basicInfo = $point;
	//=======================For Qualification Info=========================//
	$point = 0;
	$openPostInfo = multiRec("SELECT * FROM hr_can_education WHERE can_id = " . $num1 . "");
	if ($openPostInfo) {
		if (sizeof($openPostInfo) == 1) {
			$point = 35;
		} elseif (sizeof($openPostInfo) == 2) {
			$point = 70;
		} elseif (sizeof($openPostInfo) == 3) {
			$point = 100;
		} elseif (sizeof($openPostInfo) > 3) {
			$point = 100;
		}
	} else {
		$point = 0;
	}
	$EduInfo = $point;
	//=======================For Experience Info=========================//
	$point = 0;
	$openPostInfo = multiRec("SELECT * FROM hr_can_experience WHERE can_id = " . $num1 . "");
	if ($openPostInfo) {
		if (sizeof($openPostInfo) == 1) {
			$point = 100;
		} elseif (sizeof($openPostInfo) > 1) {
			$point = 100;
		}
	} else {
		$point = 0;
	}
	$ExpInfo = $point;
	//======================For Family Info=========================// 
	$point = 0;
	$openPostInfo = multiRec("SELECT * FROM hr_can_family_info WHERE can_id = " . $num1 . "");
	if ($openPostInfo) {
		if (sizeof($openPostInfo) == 1) {
			$point = 50;
		} elseif (sizeof($openPostInfo) == 2) {
			$point = 100;
		} elseif (sizeof($openPostInfo) > 2) {
			$point = 100;
		}
	} else {
		$point = 0;
	}
	$FamInfo = $point;
	//======================For Language Info=========================//
	$point = 0;
	$openPostInfo = multiRec("SELECT * FROM hr_can_language WHERE can_id = " . $num1 . "");
	if ($openPostInfo) {
		foreach ($openPostInfo as $curr) {
			if ($curr['LANGUAGE'] == 'English') {
				$point = $point + 50;
			}
			if ($curr['LANGUAGE'] == 'Hindi') {
				$point = $point + 30;
			}
		}
	} else {
		$point = 0;
	}
	$LangDetailInfo = $point;
	//======================For Social Media Info=========================//
	$point = 0;
	$openPostInfo = multiRec("SELECT * FROM hr_can_socialmedia WHERE can_id = " . $num1 . "");
	if ($openPostInfo) {
		if (sizeof($openPostInfo) == 1) {
			$point = 50;
		} elseif (sizeof($openPostInfo) > 1) {
			$point = 100;
		}
	} else {
		$point = 0;
	}
	$SocMediaInfo = $point;
	//======================For Reference Info=========================//
	$point = 0;
	$openPostInfo = multiRec("SELECT * FROM hr_can_reference WHERE can_id = " . $num1 . "");
	if ($openPostInfo) {
		if (sizeof($openPostInfo) == 1) {
			$point = 50;
		} elseif (sizeof($openPostInfo) == 2) {
			$point = 100;
		} elseif (sizeof($openPostInfo) > 2) {
			$point = 100;
		}
	} else {
		$point = 0;
	}
	$ReferenceInfo = $point;
	//======================For Salary Details=========================// 
	$point = 0;
	$openPostInfo = multiRec("SELECT expt_ctc,curr_ctc,cnd_notice FROM hr_candidate_info WHERE id = " . $num1 . "");
	if ($openPostInfo) {
		if ($openPostInfo['EXPT_CTC'] != '') {
			$point = $point + 35;
		} else if ($openPostInfo['EXPT_CTC'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CURR_CTC'] != '') {
			$point  = $point + 35;
		} else if ($openPostInfo['CURR_CTC'] == '') {
			$point = $point += 0;
		}
		if ($openPostInfo['CND_NOTICE'] != '') {
			$point  = $point + 30;
		} else if ($openPostInfo['CND_NOTICE'] == '') {
			$point = $point += 0;
		}
	} else {
		$point = 0;
	}
	$salInfo = $point;
	//======================For CV=========================//
	$point = 0;
	$openPostInfo = multiRec("SELECT doc_path FROM hr_candidate_info WHERE id = " . $num1 . "");
	if ($openPostInfo['DOC_PATH'] != NULL) {
		$point = 100;
	} else {
		$point = 0;
	}
	$cvInfo = $point;
	$prof_sts['basic'] = (int)$basicInfo;
	$prof_sts['edu'] = (int)$EduInfo;
	$prof_sts['exp'] = (int)$ExpInfo;
	$prof_sts['fam'] = (int)$FamInfo;
	$prof_sts['lang'] = (int)$LangDetailInfo;
	$prof_sts['social'] = (int)$SocMediaInfo;
	$prof_sts['sal'] = (int)$salInfo;
	$prof_sts['ref'] = (int)$ReferenceInfo;
	return $prof_sts;
}
function getProfFileName($id)
{
	$files = glob("assets/dist/img/profile/$id.*"); // Will find 2.txt, 2.php, 2.gif
	if ($files) {
		$filePath = $files[0];
	} else {
		$filePath = "assets/dist/img/profile/user.jpg";
	}
	return $filePath;
}
function candidate_log($can_id, $req_id, $status, $task_id)
{
	$can_det = singRec("select * from hr_can_application_details where can_id='" . $can_id . "' and req_id='" . $req_id . "'");
	execQry(
		array(
			'type' => 'insert', 'table' => 'HR_CAN_APP_DET_LOG',
			'data' => array(
				'ID' => null,
				'CAN_APP_DET_ID' => $can_det['ID'],
				'CAN_ID' => $can_det['CAN_ID'],
				'REQ_ID' => $can_det['REQ_ID'],
				'STATUS' => $status,
				'TASK_ID' => $task_id,
				'CHG_ON' => 'sysdate',
				'CHG_BY' => $_SESSION['EmpCode']
			),
			'print' => 0
		)
	);
	// endQry();
}
function findParentOrgEmp($empCode)
{

	$emp_res = singRec("select ORG_LOC_ID from hr_emp_office_det where emp_code='" . $empCode . "' and sysdate between effec_from and nvl(effec_to,'01-Mar-3000')");
	$parentLocId = $emp_res['ORG_LOC_ID'];
	$orgEmp = null;
	while (true) {
		$resLoc = singRec("SELECT PARENT_LOCID, GEO_DESC, geo_desc || ' (' || loc_label || ') ' AS GEO_LABEL,
                            EFFEC_FROM, EFFEC_TO, ID AS LOC_ID
                            FROM HR_ORGANOGRAM_LOC 
                            WHERE  ID='" . $parentLocId . "'");

		if (!$resLoc) {
			break;
		}
		$orgEmp = singRec("SELECT HO.EMP_CODE , HO.FNAME , HO.LNAME
			FROM HR_EMPLOYEE_INFO HO
			INNER JOIN HR_EMP_OFFICE_DET HD ON HD.EMP_CODE = HO.EMP_CODE
			WHERE HD.ORG_LOC_ID ='" . $resLoc['PARENT_LOCID'] . "' and sysdate between effec_from and nvl(effec_to , '01-Mar-3000')");

		// If an employee is found, break the loop
		if ($orgEmp) {
			break;
		} else {
		}
		$parentLocId = $resLoc['PARENT_LOCID'];
	}
	return $orgEmp['EMP_CODE'];
}



function get_descr_table($colnm , $tablename , $key ,  $val  ){

	$res = singRec("select ".$colnm." from ".$tablename." where ".$key." = '".$val."' ");
	return $res[0];
}

function getUserIpAddress() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        // Check for IP from shared internet
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Check for the IP address passed from a proxy
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        // Default IP address
        $ip = $_SERVER['REMOTE_ADDR'];
		// $ip = exec('getmac');
    }
    return $ip;
}

function get_jd_count($jdid , $tblnm ){

	$rescnt = singRec("select count(JD_ID)CNT from ".$tblnm." where jd_id=".$jdid." ");
	return $rescnt['CNT'];
}
function hr_bulk_mail($emailArr=array(),$emailArrCC=array(),$emailArrBCC=array(),$sub=null,$mailBody=null,$attchUrl=null)
{

	$newid = executeQry("insert into hr_BCS_MAILBOX(
						ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS,CHG_ON,CHG_BY)values( 
						null,
						'".trim($sub)."',
						'".trim($mailBody)."',
						'".$attchUrl."',
						'N',
						SYSDATE,
						'".trim($_SESSION['EmpCode'])."')
						returning id into :newid",'newid');

	if(count(check_array($emailArr)))
	{
		executeQry("insert into hr_BCS_MAILBOX_details(
					ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC)
					values( 
					null,
					'".$newid."',
					'".implode(",",check_array($emailArr))."',
					'".implode(",",check_array($emailArrCC))."',
					'".implode(",",check_array($emailArrBCC))."'
				)");			

	}

	return $newid;	
}



function formatIndianNumber($num) {
    $num = (string)$num;

    $parts = explode('.', $num);

    // Get the integer part of the number
    $integerPart = $parts[0];

    // Reverse the integer part of the number
    $reversed = strrev($integerPart);

    // Insert a comma after every two digits for the reversed string, except after the first three digits
    $reversed = preg_replace('/(\d{2})(?=\d)/', '$1,', $reversed);

    // Reverse the string back to its original order
    $formattedInteger = strrev($reversed);

    // Rejoin the integer part with the decimal part (if any)
    return isset($parts[1]) ? $formattedInteger . '.' . $parts[1] : $formattedInteger;
}
function checkData($tableName, $can_id) {
	$rcount = singRec("SELECT COUNT(*) as CNT FROM $tableName WHERE can_id = '". intval($can_id) ."'");

	if (is_array($rcount) && isset($rcount['CNT']) && $rcount['CNT'] > 0) {
	   return true;
   } else {
	   return false;
   }
}

function GetEmpCTCfromOffer($empcode) {

	$offr_lttr = singRec("select CTC_OFFER from hr_can_application_details a inner join hr_offers b  on a.req_id=b.req_id and a.can_id=b.can_id where a.emp_code='".$empcode."'");
	return $offr_lttr['CTC_OFFER'];
}

function GetEmpCTCfromTrfer($empcode) {

	$offr_lttr = singRec("select * from (select PROP_CTC from HR_EMP_TRANFER where a.emp_code='".$empcode."' order by id desc) where rownum <=1 " );
	return $offr_lttr['CTC_OFFER'];
}
function get_ctc_structure($empCode,$ctc,$refId,$org_id) {
   
    $empjd = singRec("SELECT jd_id FROM hr_organogram WHERE id = '" . $org_id . "'");
	$xpfbs = singRec("select amount_mnth from hr_emp_ctc_heads where emp_code='".$empCode."' 
	and ad_code='XPFBS' AND REF_ID = '".$refId."'");                  
    $ctc_heads = multiRec("SELECT a.*, b.sh_descr AS DESCR, b.ad_type, b.rnd_off, b.rnd_to 
        FROM HR_JD_CTC_HEADS a
        INNER JOIN hr_bcs_allw_dedn b ON a.AD_ID = b.ID
        WHERE JD_ID = '" . $empjd['JD_ID'] . "'  
        AND SYSDATE BETWEEN a.effec_from AND NVL(a.effec_to, '01-Mar-3000')ORDER BY b.seq_no");
 
    foreach ($ctc_heads as $resctc) {
        $ctc_key = $resctc['KEY'];
        $ctc_val = $resctc['VAL'];
        $ctc_head = $resctc['AD_CODE'];
        $mctc = ($ctc) / 12;
 
        if ($ctc_key != 'V') {
            if ($ctc_head == 'MBAS') {
                if ($ctc_key == 'C') {
                    $mbasic_sal = ($mctc) * $ctc_val / 100;
                    $mbasic_sal = ceiling($mbasic_sal, 50);
                    $ctc_arr['MBAS'] = number_format($mbasic_sal, 2, '.', '');
                } else if ($ctc_key == 'F') {
                    $mbasic_sal = number_format($ctc_val, 2, '.', '');
                    $monthly_amt = number_format($ctc_val, 2, '.', '');
                }
            }
    
            if ($ctc_head != 'MSPL') {
                if ($ctc_head == 'CPF') {
                    if ($mbasic_sal < 15000) {
                        $monthly_amt = number_format(($mbasic_sal * 12) / 100, 2, '.', '');
                    } else {
                        if ($_REQUEST['XPFBS'] == 1) {
                            $monthly_amt = number_format($ctc_val, 2, '.', '');
                        } else if ($_REQUEST['XPFBS'] == 0) {
                            $ctc_val = 12;
                            $monthly_amt = number_format(($mbasic_sal * $ctc_val) / 100, 2, '.', '');
                        }
                    }
                } else {
                    if ($ctc_key == 'C') {
                        $monthly_amt = number_format(($mctc * $ctc_val) / 100, 2, '.', '');
                    } else if ($ctc_key == 'B') {
                        $monthly_amt = number_format(($mbasic_sal * $ctc_val) / 100, 2, '.', '');
                    } else if ($ctc_key == 'F') {
                        $monthly_amt = number_format($ctc_val, 2, '.', '');
                    }
                }
    
                if ($resctc['RND_OFF'] != 'N' || $resctc['AD_CODE'] == 'MHRA') {
                    if ($ctc_key != 'F') {
                        $monthly_amt = number_format(ceiling($monthly_amt, $resctc['RND_TO']), 2, '.', '');
                    }
                }
                $ctc_arr[$ctc_head] = number_format($monthly_amt, 2, '.', '');
            }
        }
    }
    
    // Summing net CTC
    $net_ctc = 0;
    foreach ($ctc_arr as $key => $val) {
        $net_ctc += floatval($val);
    }
	foreach ($ctc_arr as $key => $val) {
        // echo $key.'=>'.$val;
    }
	// echo "NET : ".$net_ctc;

    if ($net_ctc <= $mctc) {
        $mspl = singRec("SELECT a.*, b.sh_descr AS DESCR, b.ad_type, b.rnd_off, b.rnd_to 
                FROM HR_JD_CTC_HEADS a INNER JOIN hr_bcs_allw_dedn b ON a.AD_ID = b.ID 
                WHERE JD_ID = '" . $empjd['JD_ID'] . "' AND a.AD_CODE = 'MSPL' 
                AND SYSDATE BETWEEN a.effec_from AND NVL(a.effec_to, '01-Mar-3000') 
                ORDER BY b.seq_no");
    
        if ($mspl['AD_CODE'] != '') {
            $mspecial = $mctc - $net_ctc;
            $mspecial = number_format(ceiling($mspecial, 10), 2, '.', '');
            $ctc_arr['MSPL'] = $mspecial;
        } else {
            $diff = $mctc - $net_ctc;
            $ctc_arr['MHRA'] = number_format(ceiling(($ctc_arr['MHRA'] + $diff), 10), 2, '.', '');
        }
		
    } else {
        $diff = $net_ctc - $mctc;
        $ctc_arr['MHRA'] = number_format(ceiling(($ctc_arr['MHRA'] - $diff), 10), 2, '.', '');
    } 
    return 
        $ctc_arr;
    
}
function get_curr_ctc_structure($empCode, $ctc, $refId, $org_id){

    $empjd = singRec("SELECT jd_id FROM hr_organogram WHERE id='".$org_id."'");
    $ctc_heads = multiRec("SELECT a.AD_CODE,b.sh_descr,b.rnd_off,b.rnd_to
            FROM HR_JD_CTC_HEADS a
            INNER JOIN hr_bcs_allw_dedn b ON a.AD_ID=b.ID
            WHERE JD_ID='".$empjd['JD_ID']."'
            ORDER BY b.seq_no");

    $ctc_arr = [];
    // MBAS
    $mbas = singRec("SELECT AMOUNT FROM hr_bcs_employee_ad
        WHERE emp_code='".$empCode."'
        AND AD_CODE='MBAS'
        AND SYSDATE BETWEEN eff_date AND NVL(exp_date,DATE '3000-03-01')");

    $mbasic_amount = $mbas['AMOUNT'];
    $ctc_arr['MBAS'] = $mbasic_amount;

    // Fetch payroll heads exactly like working code
    $cpf  = singRec("select id,type,amount from epplive.bcs_employee_ad 
            where emp_code='".$empCode."' and sysdate between eff_date and exp_date and ad_code='CPF'");
    $cbon = singRec("select id,type,amount from epplive.bcs_employee_ad 
            where emp_code='".$empCode."' and sysdate between eff_date and exp_date and ad_code='CBON'");
    $cgra = singRec("select id,type,amount from epplive.bcs_employee_ad 
            where emp_code='".$empCode."' and sysdate between eff_date and exp_date and ad_code='CGRA'");
    $cesi = singRec("select id,type,amount from epplive.bcs_employee_ad 
            where emp_code='".$empCode."' and sysdate between eff_date and exp_date and ad_code='CESI'");

    foreach($ctc_heads as $resctc){
        $head = $resctc['AD_CODE'];
        if($head=='MBAS' || $head=='MSPL'){
            continue;
        }
        $monthly_amt = 0;
        // PF
        if($head=='CPF' && $cpf['ID']){

            if($cpf['TYPE']=='P'){
                $monthly_amt = round(($mbasic_amount * $cpf['AMOUNT'])/100,0);
            }else{
                $monthly_amt = round(($cpf['AMOUNT'])/12,0);
            }

        }
        // BONUS
        elseif($head=='CBON' && $cbon['ID']){

            if($cbon['TYPE']=='P'){
                $monthly_amt = round(($mbasic_amount * $cbon['AMOUNT'])/100,0);
            }else{
                $monthly_amt = round(($cbon['AMOUNT'])/12,0);
            }

        }
        // GRATUITY
        else if($head=='CGRA' && $cgra['ID']){
            if($cgra['TYPE']=='P'){
                $monthly_amt = round(($mbasic_amount * $cgra['AMOUNT'])/100,0);
            }else{
                $monthly_amt = round(($cgra['AMOUNT'])/12,0);
            }
        }
        // ESI
        else if($head=='CESI' && $cesi['ID']){

            if($cesi['TYPE']=='P'){
                $monthly_amt = round(($mbasic_amount * $cesi['AMOUNT'])/100,0);
            }else{
                $monthly_amt = round(($cesi['AMOUNT'])/12,0);
            }

        }
        // Other heads (MHRA,MATT,MSEN etc)
        else{

            $emp_head = singRec("SELECT amount,type FROM hr_bcs_employee_ad
                WHERE emp_code='".$empCode."'
                AND ad_code='".$head."'
                AND sysdate between eff_date and NVL(exp_date,DATE '3000-03-01')");

            if(!empty($emp_head)){

                if($emp_head['TYPE']=='P'){
                    $monthly_amt = round(($mbasic_amount * $emp_head['AMOUNT'])/100,2);
                }else{
                    $monthly_amt = $emp_head['AMOUNT'];
                }
            }
        }

        if($resctc['RND_OFF']!='N'){
            $monthly_amt = ceiling($monthly_amt,$resctc['RND_TO']);
        }

        if($monthly_amt>0){
            $ctc_arr[$head] = number_format($monthly_amt,2,'.','');
        }
    } 
    $net_ctc = array_sum($ctc_arr);
    $mctc = $ctc/12;
    if($net_ctc < $mctc){
        $diff = $mctc - $net_ctc;
        if(isset($ctc_arr['MSPL'])){
            $ctc_arr['MSPL'] += $diff;
        }else{
            if(isset($ctc_arr['MHRA'])){
                $ctc_arr['MHRA'] += $diff;
            }
        }
    }
    return $ctc_arr;
}
function inr_ctc($num) {
	$num = (string) $num;
	$decimal = '';

	if (strpos($num, '.') !== false) {
		list($num, $decimal) = explode('.', $num);
		$decimal = '.' . $decimal; 
	}
	$length = strlen($num);

	if ($length > 3) {
		 $lastThree = substr($num, -3); 
		$remaining = substr($num, 0, -3);
		 $remaining = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);
	 
		$num = $remaining . ',' . $lastThree;
	}
	return $num . $decimal;
}
 ?>