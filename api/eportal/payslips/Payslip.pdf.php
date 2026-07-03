<?php

ob_clean();
header_remove();

if (empty($_GET['url'])) {
    http_response_code(400);
    exit("Bad Request");
}

$remote = urldecode($_GET['url']);

$parts = parse_url($remote);

if ($parts === false || !isset($parts['host'])) {
    http_response_code(400);
    exit("Invalid URL");
}

// allow only trusted host
$allowed_hosts = ['epp.sdlindia.com'];

if (!in_array(strtolower($parts['host']), $allowed_hosts, true)) {
    http_response_code(403);
    exit("Forbidden");
}

$ch = curl_init($remote);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// forward user agent
curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] ?? 'PHP-Proxy');

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    exit("Remote fetch failed");
}

$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($httpCode !== 200) {
    http_response_code($httpCode);
    exit("Remote server error");
}

$filename = "Payslip.pdf";

if (!empty($_GET['filename'])) {
    // sanitize filename
    $filename = basename($_GET['filename']);
}

// force PDF inline
header("Content-Type: application/pdf");
header('Content-Disposition: inline; filename="' . $filename . '"');
header("Cache-Control: private, max-age=60");

echo $response;
exit;
