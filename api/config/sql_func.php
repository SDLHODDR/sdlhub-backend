<?php
$sql___func___con = db_connect();
function singRec($sqlVal, $echo = '')
{
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con;
	$record = array();
	$sql = oci_parse($sql___func___con, $sqlVal);
	if (!oci_execute($sql, OCI_DEFAULT)) {
		$e = oci_error($sql);
		echo $sql['message'];
		showError($e);
		$qry_____result = 1;
		//print_r($e) ; exit;
		if ($_SESSION['DEBUG'] == 'Y') write_log($sqlVal);
		if ($_SESSION['DEBUG'] == 'Y') write_log('Error On Above Query');
	} else {
		$record = oci_fetch_array($sql);
		if (!is_array($record)) $record = array();
		foreach ($record as $key => $value) {
			$record[$key] = htmlentities($value, ENT_QUOTES);
		}
	}
	return $record;
}
function multiRec($sqlVal, $echo = '')
{
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con;
	$record = array();
	$sql = oci_parse($sql___func___con, $sqlVal);
	if (!oci_execute($sql, OCI_DEFAULT)) {
		$e = oci_error($sql);
		showError($e);
		$qry_____result = 1;
		if ($_SESSION['DEBUG'] == 'Y') write_log($sqlVal);
		if ($_SESSION['DEBUG'] == 'Y') write_log('Error On Above Query');
	} else {
		while ($res = oci_fetch_array($sql)) {
			$record[] = $res;
		}
		if (!is_array($record)) $record = array();
		foreach ($record as $value) {
			foreach ($value as $key => $value1) {
				$value[$key] = htmlentities($value1, ENT_QUOTES);
			}
		}
	}
	return $record;
}
function singDymention($array)
{
	if (!is_array($array)) $array = array();
	$newArray = array();
	foreach ($array as $key => $value) {
		if (is_array($value)) {
			foreach ($value as $keys => $vals) {
				if (is_numeric($keys)) {
					$newArray[] = $vals;
				}
			}
		} else {
			if (is_numeric($key)) {
				$newArray[] = $value;
			}
		}
	}
	return $newArray;
}
function getOptions($sqlVal, $value = '', $output = '', $echo = '')
{
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con;
	$optionList = null;
	$sql = oci_parse($sql___func___con, $sqlVal);
	if (oci_execute($sql, OCI_DEFAULT)) {
		while ($record = oci_fetch_array($sql)) {
			$output == 2 ? $record[1] = $record[0] . '&nbsp;-&nbsp;' . $record[1] : '';
			$record[1] == '' ? $record[1] = $record[0] : '';
			if (is_array($value)) {
				foreach ($value as $key => $val)
					$value[$key] = trim(strtolower($val));
				if (in_array(strtolower($record[0]), $value))
					$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
				else
					$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
			} else {
				if ($record[0] == $value)
					$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
				else
					$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
			}
		}
	} else {
		$log_file = '/var/log/apache2/error.log';
		error_log($sqlVal, 3, $log_file);
	}
	return $optionList;
}
function getOptionsYear($value = '', $output = '', $echo = '')
{
	$sqlVal = "select year_desc,year_desc from 
			(WITH yrs AS (
							SELECT LEVEL-1 AS ID FROM DUAL
							CONNECT BY LEVEL <= 6
						) 
				SELECT (ID) as year_id,
				TO_CHAR((trunc(trunc(add_months(sysdate,12)))-(ID*365)),'RRRR') as year_desc 
				FROM yrs
			)";
	if (empty($value)) $value = date('Y');
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con;
	$sql = oci_parse($sql___func___con, $sqlVal);
	oci_execute($sql, OCI_DEFAULT);
	while ($record = oci_fetch_array($sql)) {
		$output == 2 ? $record[1] = $record[0] . '&nbsp;-&nbsp;' . $record[1] : '';
		$record[1] == '' ? $record[1] = $record[0] : '';
		if (is_array($value)) {
			foreach ($value as $key => $val)
				$value[$key] = trim(strtolower($val));
			if (in_array(strtolower($record[0]), $value))
				$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
			else
				$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
		} else {
			if ($record[0] == $value)
				$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
			else
				$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
		}
	}
	return $optionList;
}
function getOptionsMonth($value = '', $output = '', $echo = '')
{
	$sqlVal = "select initcap(trim(mnth_desc)) mnth_desc,initcap(trim(mnth_desc)) mnth_desc from 
			(WITH mnths AS (
							SELECT LEVEL-1 AS ID FROM DUAL
							CONNECT BY LEVEL <= 12
						) 
				SELECT (ID+1) as mnth_id,
				TO_CHAR(ADD_MONTHS(TO_DATE(add_months(sysdate,-1), 'DD/MM/RRRR'), ID),'MONTH') as mnth_desc 
				FROM mnths
			)";
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con;
	$sql = oci_parse($sql___func___con, $sqlVal);
	oci_execute($sql, OCI_DEFAULT);
	while ($record = oci_fetch_array($sql)) {
		$output == 2 ? $record[1] = $record[0] . '&nbsp;-&nbsp;' . $record[1] : '';
		$record[1] == '' ? $record[1] = $record[0] : '';
		if (is_array($value)) {
			foreach ($value as $key => $val)
				$value[$key] = trim(strtolower($val));
			if (in_array(strtolower($record[0]), $value))
				$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
			else
				$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
		} else {
			if ($record[0] == $value)
				$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
			else
				$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
		}
	}
	return $optionList;
}
function getOptionsMulti($sqlVal, $valueArr = array(), $output = '', $echo = '')
{
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con;
	$optionList = null;
	$sql = oci_parse($sql___func___con, $sqlVal);
	if (oci_execute($sql, OCI_DEFAULT)) {
		while ($record = oci_fetch_array($sql)) {
			$output == 2 ? $record[1] = $record[0] . '&nbsp;-&nbsp;' . $record[1] : '';
			$record[1] == '' ? $record[1] = $record[0] : '';
			if (in_array($record[0], check_array($valueArr)))
				$optionList .= '<option value="' . $record[0] . '" selected>' . $record[1] . '</option>';
			else
				$optionList .= '<option value="' . $record[0] . '">' . $record[1] . '</option>';
		}
	}
	return $optionList;
}
function getOptionsArray($optsArray, $value = array(), $output = '')
{
	foreach (check_array($optsArray) as $key => $val) {
		if ($output == 2) $val = $key . ' - ' . $val;
		if (array_key_exists($key, check_array($value)))
			$optionList .= '<option value="' . $key . '" selected>' . $val . '</option>';
		else
			$optionList .= '<option value="' . $key . '">' . $val . '</option>';
	}
	return $optionList;
}
function startQry()
{
	$qry_____result = 0;
}
function execQry($paramArr = array())
{
	if ($paramArr['type'] == 'insert') {
		$strVal = null;
		foreach (check_array($paramArr['data']) as $data) {
			if (stristrarray(array('TO_DATE', 'SYSDATE'), strtoupper($data))) {
				$strVal .= "," . $data;
			} else {
				$strVal .= ",'" . $data . "'";
			}
		}
		$strVal = substr($strVal, 1);
		$sqlVal = null;
		$sqlVal = 'insert into ' . $paramArr['table'] . ' (' . implode(", ", array_keys($paramArr['data'])) . ') values (' . $strVal . ') ';
		//print_r($sqlVal);exit;
	} else if ($paramArr['type'] == 'update') {
		$strVal = null;
		foreach (check_array($paramArr['data']) as $field => $data) {
			if (stristrarray(array('TO_DATE', 'SYSDATE'), strtoupper($data))) {
				$strVal .= "," . $field . "=" . $data;
			} else {
				$strVal .= "," . $field . "='" . $data . "'";
			}
			//~ if(stristrarray(array('TO_DATE','SYSDATE'),strtoupper($data))) {
			//~ $strVal .= ",".$data ;
			//~ }
			//~ else {
			//~ $strVal .= ",'".$data."'" ;
			//~ }
		}
		$strVal = substr($strVal, 1);
		$strWhere = null;
		foreach (check_array($paramArr['where']) as $field => $data) {
			if (stristrarray(array('TO_DATE', 'SYSDATE'), strtoupper($data))) {
				$strWhere .= "and " . $field . "=" . $data . " ";
			} else {
				$strWhere .= "and " . $field . "='" . $data . "' ";
			}
		}
		$strWhere = substr($strWhere, 3);
		$sqlVal = 'update ' . $paramArr['table'] . ' set ' . $strVal . '  where ' . $strWhere;
	}
	$returnVal = null;
	if (!empty($paramArr['return'])) {
		$sqlVal .= ' returning ' . $paramArr['return'] . ' into :returnVal';
		$returnVal = 'returnVal';
	}
	if ($paramArr['print'] == 1) echo '<p>' . $sqlVal . '</p>';
	else if ($paramArr['print'] == 2) {
		arr($paramArr);
		echo '<p>' . $sqlVal . '</p>';
	} else if ($paramArr['print'] == 3) arr($paramArr);
	return executeQry($sqlVal, $returnVal);
}
function executeQry($sqlVal, $returnId = '', $echo = '')
{
	if (!empty($echo) or !empty($_SESSION['echo'])) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con, $qry_____result;
	$sql = oci_parse($sql___func___con, $sqlVal);
	if ($_SESSION['DEBUG'] == 'Y') write_log($sqlVal);
	if ($returnId != '') {
		ocibindbyname($sql, ':' . $returnId, $newId, 10);
		if (!oci_execute($sql, OCI_DEFAULT)) {
			$e = oci_error($sql);
			showError($e);
			$qry_____result = 1;
		} else {
			return $newId;
		}
	} else {
		if (!oci_execute($sql, OCI_DEFAULT)) {
			$e = oci_error($sql);
			echo $e;
			showError($e);
			$qry_____result = 1;
			if ($_SESSION['DEBUG'] == 'Y') write_log('Error On Above Query');
		}
	}
}
function executeProc($sqlVal, $bindVal = array(), $echo = '')
{
	//echo $sqlVal;
	if (!empty($echo)) echo $sqlVal . '<hr style="border:2px solid #000000;" />';
	global $sql___func___con, $qry_____result;
	$sql = oci_parse($sql___func___con, $sqlVal);
	if ($_SESSION['DEBUG'] == 'Y') write_log($sqlVal);
	$returnVal = array();
	foreach ($bindVal as $ociBindVal) {
		ocibindbyname($sql, ':' . $ociBindVal, $returnVal[$ociBindVal], 100);
	}
	if (!oci_execute($sql, OCI_DEFAULT)) {
		$e = oci_error($sql);
		showError($e);
		$qry_____result = 1;
		if ($_SESSION['DEBUG'] == 'Y') write_log('Error On Above Proc');
	} else {
		return $returnVal;
	}
}
function forceRollback($message = '')
{
	global $qry_____result;
	echo $message;
	if ($_SESSION['DEBUG'] == 'Y') write_log($message);
	$qry_____result = 1;
}
function forceCommit()
{
	global $qry_____result;
	$qry_____result = 0;
}
function endQry($message = '')
{
	global $sql___func___con, $qry_____result;
	if ($qry_____result == 1) {
		oci_rollback($sql___func___con);
		if (empty($_SESSION['status'])) {
			//~ if(!empty($message) && str_word_count($message)==1)$_SESSION['status']=$message.' Failed';
			//~ else
			if (!empty($message)) $_SESSION['status'] = $message;
			//~ else $_SESSION['status']='Insert Failed';
		}
	} else {
		oci_commit($sql___func___con);
		if (empty($_SESSION['status'])) {
			//~ if(!empty($message) && str_word_count($message)==1)$_SESSION['status']=$message.' Successfully';
			//~ else
			if (!empty($message)) $_SESSION['status'] = $message;
			//~ else $_SESSION['status']='Insert Successfully';
		}
	}
	//oci_close($con);	
}
function showError($sqlVal = null)
{
	echo '<h3><font color="red">Above Error is Generated From Following Query/Procedure </font></h3>' . $sqlVal['sqltext'] . '<hr style="border:5px solid red;" />';
	echo $sqlVal['message'];
	$file = 'reports/output/error_log.txt';
	$current = file_get_contents($file);
	$current .= '
				{[Code : ' . $sqlVal['code'] . '
				Date : ' . date('d-M-Y') . '
				Message : ' . $sqlVal['message'] . '
				Offset : ' . $sqlVal['offset'] . '
				sqltext : ' . $sqlVal['sqltext'] . '
				Page : ' . $_SERVER['REQUEST_URI'] . '
				Env : ' . $_SERVER['HTTP_USER_AGENT'] . ']}';
	file_put_contents($file, $current);
}
function jsArray($sql)
{
	$record = multiRec($sql);
	$jsArray = '{ ';
	foreach ($record as $key => $val) {
		$jsArray .= '\'' . $val[0] . '\':\'' . $val[1] . '\', ';
	}
	$jsArray = substr($jsArray, 0, -2);
	$jsArray .= ' };';
	return $jsArray;
}
function notAccess()
{
	echo '	<div class="span12 well">
				<div>					
					<h3>&nbsp;&nbsp;HO Personnal do not have access to this menu !</h3>
				</div>
			</div>
			<script>
			document.getElementById("my_loader").style.display="none";
			</script>';
	exit;
}
function write_log($val)
{
}
function hr_mail($emailArray, $subject, $body, $attachedFile = null)
{
	include_once('mail/mail.php');
}
