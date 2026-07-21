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

function getClientIp()
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
}

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

?>
