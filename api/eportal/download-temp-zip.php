<?php

$file = $_GET['file'] ?? '';
$file = basename($file);

$path = sys_get_temp_dir() . "/itr_zip/" . $file;

if (!file_exists($path)) {
    http_response_code(404);
    echo "File not found";
    echo "path: ".$path; 
    exit;
}

header('Content-Type: application/zip');
header( 'Content-Disposition: attachment; filename="' . $file . '"' );
header('Content-Length: ' . filesize($path));
readfile($path);

exit;