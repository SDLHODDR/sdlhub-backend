<?php

error_reporting(E_ALL);
ini_set('display_errors',1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../cors.php";

$empCode = $_SESSION['emp_code'] ?? '00575';

if (!isset($_FILES['profile_image'])) {
    echo json_encode([
        "status" => false,
        "message" => "No file uploaded"
    ]);
    exit;
}

$uploadDir = rtrim($_ENV["PUBLIC_PATH"], "/") . "/profiles/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$fileName = $empCode . ".jpg";

$filePath = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $filePath)) {

    echo json_encode([
        "status" => false,
        "message" => "Upload failed"
    ]);
    exit;
}

$imageUrl = rtrim($_ENV["PROFILES_URL"], "/") .
            "/" .
            $fileName .
            "?v=" .
            time();

echo json_encode([
    "status" => true,
    "image" => $imageUrl
]);
