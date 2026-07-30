<?php
require_once "logger.php";

function logOracleError($oracleError, $sql = "")
{
    $fileName = basename($_SERVER['SCRIPT_NAME']);

    $message =
        "Oracle Error : " . $oracleError['message'];

    if (!empty($fileName)) {
        $message .= " | File : " . $fileName;
    }

    if (!empty($sql)) {
        $message .= " | SQL : " . $sql;
    }

    writeErrorLog($message);
}

function logFuncError($message, $level = 'ERROR') {
    $logFile = __DIR__ . '/logs/errorDB.log';
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [$level] $message" . PHP_EOL;

    error_log($formatted, 3, $logFile);
}

function singRec($sqlVal, $binds = [], $echo = '')
{
    global $sql___func___con;

    if (!empty($echo)) {
        echo $sqlVal . '<hr class="mt-10" style="border:2px solid #000000;" />';
    }

    $stmt = oci_parse($sql___func___con, $sqlVal);

    if (!$stmt) {
        $e = oci_error($sql___func___con);
        logOracleError($e, $sqlVal);
        return [];
    }

    foreach ($binds as $key => &$value) {
        oci_bind_by_name($stmt, $key, $value);
    }

    if (!oci_execute($stmt, OCI_DEFAULT)) {
        $e = oci_error($stmt);
        logOracleError($e, $sqlVal);
        oci_free_statement($stmt);
        return [];
    }

    $record = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS);

    if (!is_array($record)) {
        $record = [];
    }

    foreach ($record as $key => $value) {
        $record[$key] = htmlentities((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    oci_free_statement($stmt);

    return $record;
}

function singRecEPP($sqlVal,$echo='')
{
	if(!empty($echo)) echo $sqlVal.'<hr class="mt-10" style="border:2px solid #000000;" />';
	//GLOBAL $sql___func___con;
	$sql___func___con = db_eppprod();
	$record=array();
	$sql=oci_parse($sql___func___con,$sqlVal);
	if(!oci_execute($sql,OCI_DEFAULT))
	{
		$e = oci_error($sql);
		showError($e);
		$qry_____result=1;
		//print_r($e) ; exit;
		if($_SESSION['DEBUG']=='Y')writeErrorLog($sqlVal);
		if($_SESSION['DEBUG']=='Y')writeErrorLog('Error On Above Query');
	}
	else
	{
		$record=oci_fetch_array($sql);
		if(!is_array($record))$record=array();
		foreach($record as $key=>$value)
		{
			//$record[$key]=htmlentities($value,ENT_QUOTES);
			$record[$key] = htmlentities((string)($value ?? ''), ENT_QUOTES, 'UTF-8');

		}
	}
	return $record;
}

function multiRec($sqlVal, $binds = [], $options = [])
{
    global $sql___func___con;

    $defaults = [
        'debug' => false,
        'encodeHtml' => false,
    ];

    $options = array_merge($defaults, $options);

    if ($options['debug']) {
        echo $sqlVal . '<hr style="border:2px solid #000;" />';
    }

    $stmt = oci_parse($sql___func___con, $sqlVal);

    if (!$stmt) {
        $e = oci_error($sql___func___con);
        logOracleError($e, $sqlVal);
        return [];
    }

    foreach ($binds as $key => &$value) {
        oci_bind_by_name($stmt, $key, $value);
    }

    if (!oci_execute($stmt, OCI_DEFAULT)) {
        $e = oci_error($stmt);
        logOracleError($e, $sqlVal);
        oci_free_statement($stmt);
        return [];
    }

    $records = [];

    while ($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {

        foreach ($row as $key => $value) {

            $cleanValue = ($value === null) ? '' : $value;

            if ($options['encodeHtml']) {
                $cleanValue = htmlspecialchars(
                    (string)$cleanValue,
                    ENT_QUOTES,
                    'UTF-8'
                );
            }

            $row[$key] = $cleanValue;
        }

        $records[] = $row;
    }

    oci_free_statement($stmt);

    return $records;
}

function multiRecEPP($sqlVal, $options = [])
{
    //GLOBAL $sql___func___con;
	$sql___func___con = db_eppprod();

    // Default options
    $defaults = [
        'debug'      => false,
        'encodeHtml' => false,   // false for API, true for HTML pages
    ];

    $options = array_merge($defaults, $options);

    if ($options['debug']) {
        echo $sqlVal . '<hr style="border:2px solid #000;" />';
    }

    $record = [];

    $stmt = oci_parse($sql___func___con, $sqlVal);

    if (!oci_execute($stmt, OCI_DEFAULT)) {

        $e = oci_error($stmt);
        showError($e);

        if (!empty($_SESSION['DEBUG']) && $_SESSION['DEBUG'] == 'Y') {
            write_log($sqlVal);
            write_log('Error On Above Query');
        }

        return [];
    }

    // Fetch associative only (IMPORTANT: removes duplicate numeric keys)
    while ($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {

        foreach ($row as $key => $value) {

            // Normalize NULL to empty string
            $cleanValue = ($value === null) ? '' : $value;

            // Encode only if required
            if ($options['encodeHtml']) {
                $cleanValue = htmlspecialchars(
                    (string)$cleanValue,
                    ENT_QUOTES,
                    'UTF-8'
                );
            }

            $row[$key] = $cleanValue;
        }

        $record[] = $row;
    }

    return $record;
}

function singDymention($array)
{
	if(!is_array($array))$array=array();
	$newArray=array();
	foreach($array as $key=>$value)
	{
		if(is_array($value))
		{
			foreach($value as $keys=>$vals)
			{
				if(is_numeric($keys))
				{
					$newArray[]=$vals;
				}
			}
		}
		else
		{
			if(is_numeric($key))
			{
				$newArray[]=$value;
			}
		}
	}
	return $newArray;
}

function singDymentionNew($array)
{
    if(!is_array($array)) {
        return [];
    }

    $newArray = [];

    foreach($array as $value)
    {
        if(is_array($value))
        {
            foreach($value as $vals)
            {
                $newArray[] = $vals;
            }
        }
        else
        {
            $newArray[] = $value;
        }
    }

    return $newArray;
}

function getOptions($sqlVal,$value='',$output='',$echo='')
{
	if(!empty($echo)) echo $sqlVal.'<hr style="border:2px solid #000000;" />';
	GLOBAL $sql___func___con;
	$optionList=null;
	$sql=oci_parse($sql___func___con,$sqlVal);
	if(oci_execute($sql,OCI_DEFAULT))
	{
		while($record=oci_fetch_array($sql))
		{
			$output==2?$record[1]=$record[0].'&nbsp;-&nbsp;'.$record[1]:'';
			$record[1]==''?$record[1]=$record[0]:'';
			if(is_array($value))
			{
				foreach($value as $key=>$val)
					$value[$key]=trim(strtolower($val));
				if(in_array(strtolower($record[0]),$value))
					$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
				else
					$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
			}
			else
			{
				if($record[0]==$value)
					$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
				else
					$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
			}
		}
	}
	else
	{
		$log_file='/var/log/apache2/error.log';
		error_log($sqlVal, 3, $log_file);
	}
	return $optionList;
}

function getOptionsYear($value='',$output='',$echo='')
{
	$sqlVal="select year_desc,year_desc from
			(WITH yrs AS (
							SELECT LEVEL-1 AS ID FROM DUAL
							CONNECT BY LEVEL <= 6
						)
				SELECT (ID) as year_id,
				TO_CHAR((trunc(trunc(add_months(sysdate,12)))-(ID*365)),'RRRR') as year_desc
				FROM yrs
			)";
	if(empty($value)) $value=date('Y');
	if(!empty($echo)) echo $sqlVal.'<hr style="border:2px solid #000000;" />';
	GLOBAL $sql___func___con;
	$sql=oci_parse($sql___func___con,$sqlVal);
	oci_execute($sql,OCI_DEFAULT);
	while($record=oci_fetch_array($sql))
	{
		$output==2?$record[1]=$record[0].'&nbsp;-&nbsp;'.$record[1]:'';
		$record[1]==''?$record[1]=$record[0]:'';
		if(is_array($value))
		{
			foreach($value as $key=>$val)
				$value[$key]=trim(strtolower($val));
			if(in_array(strtolower($record[0]),$value))
				$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
			else
				$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
		}
		else
		{
			if($record[0]==$value)
				$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
			else
				$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
		}
	}
	return $optionList;
}

function getOptionsMonth($value='',$output='',$echo='')
{
	$sqlVal="select initcap(trim(mnth_desc)) mnth_desc,initcap(trim(mnth_desc)) mnth_desc from
			(WITH mnths AS (
							SELECT LEVEL-1 AS ID FROM DUAL
							CONNECT BY LEVEL <= 12
						)
				SELECT (ID+1) as mnth_id,
				TO_CHAR(ADD_MONTHS(TO_DATE(add_months(sysdate,-1), 'DD/MM/RRRR'), ID),'MONTH') as mnth_desc
				FROM mnths
			)";
	if(!empty($echo)) echo $sqlVal.'<hr style="border:2px solid #000000;" />';
	GLOBAL $sql___func___con;
	$sql=oci_parse($sql___func___con,$sqlVal);
	oci_execute($sql,OCI_DEFAULT);
	while($record=oci_fetch_array($sql))
	{
		$output==2?$record[1]=$record[0].'&nbsp;-&nbsp;'.$record[1]:'';
		$record[1]==''?$record[1]=$record[0]:'';
		if(is_array($value))
		{
			foreach($value as $key=>$val)
				$value[$key]=trim(strtolower($val));
			if(in_array(strtolower($record[0]),$value))
				$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
			else
				$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
		}
		else
		{
			if($record[0]==$value)
				$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
			else
				$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
		}
	}
	return $optionList;
}

function getOptionsMulti($sqlVal,$valueArr=array(),$output='',$echo='')
{
	if(!empty($echo)) echo $sqlVal.'<hr style="border:2px solid #000000;" />';
	GLOBAL $sql___func___con;
	$optionList=null;
	$sql=oci_parse($sql___func___con,$sqlVal);
	if(oci_execute($sql,OCI_DEFAULT))
	{
		while($record=oci_fetch_array($sql))
		{
			$output==2?$record[1]=$record[0].'&nbsp;-&nbsp;'.$record[1]:'';
			$record[1]==''?$record[1]=$record[0]:'';
			if( in_array($record[0],check_array($valueArr)) )
				$optionList.='<option value="'.$record[0].'" selected>'.$record[1].'</option>';
			else
				$optionList.='<option value="'.$record[0].'">'.$record[1].'</option>';
		}
	}
	return $optionList;
}

function getOptionsArray($optsArray,$value=array(),$output='')
{
	foreach(check_array($optsArray) as $key=>$val)
	{
		if($output==2) $val=$key.' - '.$val;
		if(array_key_exists($key,check_array($value)))
			$optionList.='<option value="'.$key.'" selected>'.$val.'</option>';
		else
			$optionList.='<option value="'.$key.'">'.$val.'</option>';
	}
	return $optionList;
}

function startQry()
{
	$qry_____result=0;
}

function execQry($paramArr = [])
{
    $sqlVal = "";

    /* ==========================
       BUILD INSERT QUERY
    ========================== */

    if ($paramArr['type'] == 'insert') {

        $strVal = "";

        foreach (check_array($paramArr['data']) as $data) {

            if (stristrarray(
                    ['TO_DATE', 'SYSDATE'],
                    strtoupper((string)($data ?? ''))
                )) {

                $strVal .= "," . $data;

            } else {

                $strVal .= ",'" . $data . "'";
            }
        }

        $strVal = ltrim($strVal, ',');

        $sqlVal =
            "INSERT INTO " . $paramArr['table'] .
            " (" . implode(", ", array_keys($paramArr['data'])) . ")" .
            " VALUES (" . $strVal . ")";
    }

    /* ==========================
       BUILD UPDATE QUERY
    ========================== */

    else if ($paramArr['type'] == 'update') {

        $strVal = "";

        foreach (check_array($paramArr['data']) as $field => $data) {

            if (stristrarray(
                    ['TO_DATE', 'SYSDATE'],
                    strtoupper((string)($data ?? ''))
                )) {

                $strVal .= "," . $field . "=" . $data;

            } else {

                $strVal .= "," . $field . "='" . $data . "'";
            }
        }

        $strVal = ltrim($strVal, ',');

        $strWhere = "";

        foreach (check_array($paramArr['where']) as $field => $data) {

            if (stristrarray(
                    ['TO_DATE', 'SYSDATE'],
                    strtoupper((string)($data ?? ''))
                )) {

                $strWhere .= " AND " . $field . "=" . $data;

            } else {

                $strWhere .= " AND " . $field . "='" . $data . "'";
            }
        }

        $strWhere = preg_replace('/^ AND /', '', $strWhere);

        $sqlVal =
            "UPDATE " . $paramArr['table'] .
            " SET " . $strVal .
            " WHERE " . $strWhere;
    }

    /* ==========================
       RETURNING CLAUSE
    ========================== */

    $returnVal = null;

    if (!empty($paramArr['return'])) {

        $sqlVal .=
            " RETURNING " .
            $paramArr['return'] .
            " INTO :returnVal";

        $returnVal = "returnVal";
    }

    /* ==========================
       DEBUG PRINT
    ========================== */

    $print = $paramArr['print'] ?? 0;

    switch ($print) {

        case 1:
            echo "<p>{$sqlVal}</p>";
            break;

        case 2:
            arr($paramArr);
            echo "<p>{$sqlVal}</p>";
            break;

        case 3:
            arr($paramArr);
            break;
    }

    /* ==========================
       EXECUTE QUERY
    ========================== */

    return executeQry($sqlVal, $returnVal);
}

function executeQry($sqlVal, $returnId = '', $echo = '')
{
    if (!empty($echo) || !empty($_SESSION['echo'])) {
        echo $sqlVal . '<hr style="border:2px solid #000000;" />';
    }

    global $sql___func___con, $qry_____result;

    $qry_____result = 0;

    /* ==========================
       PARSE QUERY
    ========================== */

    $sql = oci_parse($sql___func___con, $sqlVal);

    if (!$sql) {

        $e = oci_error($sql___func___con);

        logOracleError($e, $sqlVal);

        $qry_____result = 1;

        return false;
    }

    /* ==========================
       DEBUG LOG
    ========================== */

    if (!empty($_SESSION['DEBUG']) && $_SESSION['DEBUG'] == 'Y') {
        write_log($sqlVal);
    }

    /* ==========================
       RETURNING CLAUSE
    ========================== */

    if ($returnId != '') {

        $newId = null;

        oci_bind_by_name($sql, ':' . $returnId, $newId, 100);

        if (!oci_execute($sql, OCI_DEFAULT)) {

            $e = oci_error($sql);
            logOracleError($e, $sqlVal);
            $qry_____result = 1;
            oci_free_statement($sql);

            return false;
        }

        oci_free_statement($sql);

        return $newId;
    }

    /* ==========================
       NORMAL EXECUTION
    ========================== */

    if (!oci_execute($sql, OCI_DEFAULT)) {

        $e = oci_error($sql);

        logOracleError($e, $sqlVal);

        $qry_____result = 1;

        oci_free_statement($sql);

        return false;
    }

    oci_free_statement($sql);

    return true;
}

function executeProc($sqlVal,$bindVal=array(),$echo='')
{
	//echo $sqlVal;
	if(!empty($echo)) echo $sqlVal.'<hr style="border:2px solid #000000;" />';
	GLOBAL $sql___func___con,$qry_____result;
	$sql=oci_parse($sql___func___con,$sqlVal);
	if($_SESSION['DEBUG']=='Y')write_log($sqlVal);
	$returnVal=array();
	foreach($bindVal as $ociBindVal)
	{
		//ocibindbyname($sql,':'.$ociBindVal,$returnVal[$ociBindVal],100);
		oci_bind_by_name($sql, ':' . $ociBindVal, $returnVal[$ociBindVal], 100);

	}
	if(!oci_execute($sql,OCI_DEFAULT))
	{
		$e = oci_error($sql);
		showError($e);
		$qry_____result=1;
		if($_SESSION['DEBUG']=='Y')write_log('Error On Above Proc');
	}
	else
	{
		return $returnVal;
	}
}

function forceRollback($message = '')
{
	GLOBAL $qry_____result;
	if (($_SESSION['DEBUG'] ?? '') == 'Y') {
		write_log($message);
	}
	$qry_____result = 1;
}

function forceCommit()
{
	GLOBAL $qry_____result;
	$qry_____result=0;
}

function endQry($message='')
{
	GLOBAL $sql___func___con,$qry_____result;
	if($qry_____result==1)
	{
		oci_rollback($sql___func___con);
		if(empty($_SESSION['status']))
		{
			//~ if(!empty($message) && str_word_count($message)==1)$_SESSION['status']=$message.' Failed';
			//~ else
			if(!empty($message))$_SESSION['status']=$message;
			//~ else $_SESSION['status']='Insert Failed';
		}
	}
	else
	{
		oci_commit($sql___func___con);
		if(empty($_SESSION['status']))
		{
			//~ if(!empty($message) && str_word_count($message)==1)$_SESSION['status']=$message.' Successfully';
			//~ else
			if(!empty($message))$_SESSION['status']=$message;
			//~ else $_SESSION['status']='Insert Successfully';
		}
	}
	//oci_close($con);
}

function showError($sqlVal=null)
{
	echo '<h3><font color="red">Above Error is Generated From Following Query/Procedure </font></h3>'.$sqlVal['sqltext'].'<hr class="mt-20" style="border:5px solid red;margin-top: 10rem;" />';
	$file = 'reports/output/error_log.txt';
	$current = file_get_contents($file);
	$current .='
				{[Code : '.$sqlVal['code'].'
				Date : '.date('d-M-Y').'
				Message : '.$sqlVal['message'].'
				Offset : '.$sqlVal['offset'].'
				sqltext : '.$sqlVal['sqltext'].'
				Page : '.$_SERVER['REQUEST_URI'].'
				Env : '.$_SERVER['HTTP_USER_AGENT'].']}';
	file_put_contents($file, $current);
	echo $sqlVal['message'];
}

function jsArray($sql)
{
	$record=multiRec($sql);
	$jsArray='{ ';
	foreach($record as $key=>$val)
	{
		$jsArray.= '\''.$val[0].'\':\''.$val[1].'\', ';
	}
	$jsArray=substr($jsArray,0,-2);
	$jsArray.=' };';
	return $jsArray;
}

function getOptionsCustom($sqlVal = "", $val = "") {
	$returnArr = multiRec($sqlVal);

	return $returnArr;
}

function get_number_to_text($amount)
{
	$output_string = '';
	$tokens = explode('.', $amount);
	$current_amount = $tokens[0];
	$fraction = '';
	if(count($tokens) > 1)
	{
		$fraction = (double)('0.' . $tokens[1]);
		$fraction = $fraction * 100;
		$fraction = round($fraction, 0);
		$fraction = (int)$fraction;
		if ($fraction > 0)
		{
			$fraction = $this->translate_to_words($fraction) . ' paisa';
			$fraction = ' and ' . $fraction;
		}
		else
		{
			$fraction = '';
		}
	}
	$crore = 0;
	if($current_amount >= pow(10,7))
	{
		$crore = (int)floor($current_amount / pow(10,7));
		$output_string .= $this->translate_to_words($crore) . ' crore ';
		$current_amount = $current_amount - $crore * pow(10,7);
	}
	$lakh = 0;
	if($current_amount >= pow(10,5))
	{
		$lakh = (int)floor($current_amount / pow(10,5));
		$output_string .= $this->translate_to_words($lakh) . ' lakh ';
		$current_amount = $current_amount - $lakh * pow(10,5);
	}
	$current_amount = (int)$current_amount;
	$output_string .= $this->translate_to_words($current_amount);
	$output_string = $output_string;
	$output_string = ucwords($output_string);
	return $output_string;
}

function taskUpdate($status, $remark, $task_id, $taskGrp = null, $notChek = 0)
{
	$task = singRec("select ID from EPT_TASK_MASTER where TASK_GRP='" . $taskGrp . "' ");

	if(empty($task)) {
		$chk = singRec("
		select TASK_ID, STATUS, AUTH_BY,
				to_char(AUTH_ON,'dd-Mon-yyyy') AUTH_ON,
				to_char(AUTH_ON,'hh24:MI') AUTH_TM,
				TRAN_CODE, EMP_CODE_FOR
		from EPT_USER_TASKS
		where id = '".$task_id."' ");
	} else {
		$chk = singRec("
		select TASK_ID, STATUS, AUTH_BY,
				to_char(AUTH_ON,'dd-Mon-yyyy') AUTH_ON,
				to_char(AUTH_ON,'hh24:MI') AUTH_TM,
				TRAN_CODE, EMP_CODE_FOR
		from EPT_USER_TASKS
		where id = '".$task_id."'
			and task_id = nvl('".$task['ID']."', task_id)
		");
	}

	if (empty($taskGrp))
		$task = singRec("select TASK_GRP from EPT_TASK_MASTER where id='" . $chk['TASK_ID'] . "' ");
	if ($chk['STATUS'] == 'C' && $notChek == 0) {
		$_SESSION['status'] = 'Task Already Authorized By ' . $chk['AUTH_BY'] . ' on Dated ' . $chk['AUTH_ON'] . ' and Time ' . $chk['AUTH_TM'];
		//redirect("authorization.php");
		// taskRedirect($task['TASK_GRP']);
		exit;
	} else if ($chk['STATUS'] == 'X' && $notChek == 0) {
		$_SESSION['status'] = 'Task Already Rejected By ' . $chk['AUTH_BY'] . ' on Dated ' . $chk['AUTH_ON'] . ' and Time ' . $chk['AUTH_TM'];
		// taskRedirect($task['TASK_GRP']);
		exit;
	} else if ($task_id) {

		executeQry("update EPT_USER_TASKS set STATUS='" . trim(strtoupper($status)) . "', AUTH_BY='" . $_SESSION['emp_code'] . "', AUTH_ON=SYSDATE, REMARKS='" . str_replace("'", "''", trim(strtoupper($remark))) . "', IP_ADDR='" . $_SERVER['REMOTE_ADDR'] . "' where id='" . $task_id . "' ");
		execQry(
			array(
				'type' => 'insert', 'table' => 'EPT_USER_TASKS_LOG',
				'data' => array(
					'USER_TASKID' => $task_id, 'TASK_ID' => $chk['TASK_ID'], 'TRAN_CODE' => $chk['TRAN_CODE'], 'SITE_CODE' => $_SESSION['ept']['eppSiteCode'], 'STATUS' => $status, 'REMARKS' => str_replace("'", "''", $remark), 'EMP_CODE_FOR' => $chk['EMP_CODE'], 'IP_ADDR' => $_SERVER['REMOTE_ADDR'], 'CHG_ON' => 'SYSDATE', 'CHG_BY' => $_SESSION['emp_code']
				),
				'return' => '',
				'print' => 0
			)
		);
	}
}

?>
