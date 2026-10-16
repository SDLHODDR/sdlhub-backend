<?php
require_once "logger.php";

/* ---------------------------
   PASSWORD ENCODER
---------------------------- */
function encodel($str)
{
    for ($i = 0; $i < 5; $i++) {
        $str = strrev(base64_encode($str));
    }
    return $str;
}

function check_array($array)
{
    if (!is_array($array)) {
        $array = array();
    }
    return $array;
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

/*
function apiResponse($status = true, $message = "", $data = null, $httpCode = 200, $errors = [])
{
    http_response_code($httpCode);

    header("Content-Type: application/json; charset=UTF-8");

    $response = [
        "status" => $status,
        "message" => $message,
        "data" => $data
    ];

    if (!empty($errors)) {
        $response["errors"] = $errors;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}*/

function apiResponse($status = true, $message = "", $data = null, $httpCode = 200, $errors = [])
{
    // Log only server errors
    if (!$status && $httpCode >= 500) {

        $logMessage =
        "API: " . basename($_SERVER['PHP_SELF']) .
        " | User: " . ($_SESSION['emp_code'] ?? 'SYSTEM') .
        " | Message: " . $message .
        " | URL: " . ($_SERVER['REQUEST_URI'] ?? '');

        if (!empty($errors)) {
            $logMessage .= " | Errors : " . json_encode($errors);
        }
        writeErrorLog($logMessage);
    }

    http_response_code($httpCode);

    header("Content-Type: application/json; charset=UTF-8");

    $response = [
        "status" => $status,
        "message" => $message,
        "data" => $data
    ];

    if (!empty($errors)) {
        $response["errors"] = $errors;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}


function getClientIp(): string {
    $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";
    if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        $ipList = explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"]);
        $ip = trim($ipList[0]);
    } elseif (!empty($_SERVER["HTTP_CLIENT_IP"])) {
        $ip = $_SERVER["HTTP_CLIENT_IP"];
    }
    return substr($ip, 0, 20);
}

/*function getClientIp()
{
    $ipKeys = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];

    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ipList = explode(',', $_SERVER[$key]);
            return trim($ipList[0]);
        }
    }

    return 'UNKNOWN';
}*/

function getBrowserName($userAgent)
{
    if (preg_match('/Edg/i', $userAgent)) {
        return 'Microsoft Edge';
    }

    if (preg_match('/Chrome/i', $userAgent) &&
        !preg_match('/Edg/i', $userAgent))
    {
        return 'Google Chrome';
    }

    if (preg_match('/Firefox/i', $userAgent))
    {
        return 'Mozilla Firefox';
    }

    if (preg_match('/Safari/i', $userAgent) &&
        !preg_match('/Chrome/i', $userAgent))
    {
        return 'Safari';
    }

    if (preg_match('/Opera|OPR/i', $userAgent))
    {
        return 'Opera';
    }

    return 'Unknown';
}

function responseError(string $message): void
{
    apiResponse(false, $message);
    exit;
}

function responseSuccess(string $message, array $data = []): void
{
    apiResponse(true, $message, $data);
    exit;
}

function ensureDirectoryExists($dir)
{
    if (!is_dir($dir)) {

        if (!mkdir($dir,0775,true)) {

            responseError("Unable to create directory.");
        }
    }
}

function readJsonInput()
{
    $raw=file_get_contents("php://input");

    $input=json_decode($raw,true);

    if(json_last_error()==JSON_ERROR_NONE){
        return $input;
    }

    return [];
}

function getEmpofficeHR($empId)
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

function get_ctc_structure_hr($empCode,$ctc,$refId,$org_id) {
   
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
                    $mbasic_sal = ceilingHR($mbasic_sal, 50);
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
                        $monthly_amt = number_format(ceilingHR($monthly_amt, $resctc['RND_TO']), 2, '.', '');
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
    
        if (isset($mspl['AD_CODE']) && $mspl['AD_CODE'] != '') {
            $mspecial = $mctc - $net_ctc;
            $mspecial = number_format(ceilingHR($mspecial, 10), 2, '.', '');
            $ctc_arr['MSPL'] = $mspecial;
        } else {
            $diff = $mctc - $net_ctc;
            $ctc_arr['MHRA'] = number_format(ceilingHR(($ctc_arr['MHRA'] + $diff), 10), 2, '.', '');
        }
		
    } else {
        $diff = $net_ctc - $mctc;
        $ctc_arr['MHRA'] = number_format(ceilingHR(($ctc_arr['MHRA'] - $diff), 10), 2, '.', '');
    } 
    
    return 
        $ctc_arr;
    
}

function ceilingHR($number, $significance) {
    if ($significance != null) {
        return (is_numeric($number) && is_numeric($significance) ) ? (ceil($number / $significance) * $significance) : $number;
    } else {
        return $number;
    }

}

function getEmpInfoByCodeHRUtils($id)
{
    $EmpName = singRec("SELECT FNAME,LNAME 
                        FROM HR_EMPLOYEE_INFO
                        WHERE EMP_CODE = '" . $id . "'");
    $name = $EmpName['FNAME'] . ' ' . $EmpName['LNAME'];
    return ucwords(strtolower($name));
}

/* ===========================================
   AGE CALCULATION FUNCTION
=========================================== */

function calculateAgeFromDob(DateTime $dob): int
{
    $timezone = new DateTimeZone(
        'Asia/Kolkata'
    );

    $today = new DateTime(
        'today',
        $timezone
    );

    $dob = clone $dob;
    $dob->setTime(0, 0, 0);

    /*
     * Calculate completed years.
     */
    $age = $dob->diff($today)->y;

    /*
     * Safety check.
     */
    if ($age < 0) {
        return 0;
    }

    return $age;
}

?>