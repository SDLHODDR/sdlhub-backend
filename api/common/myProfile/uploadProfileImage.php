<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";

$empCode = $_SESSION['emp_code'] ?? '00575';

if (!isset($_FILES['profile_image'])) {
    echo json_encode([
        "status" => false,
        "message" => "No file uploaded"
    ]);
    exit;
}

$rootPath = realpath(__DIR__ . "/../../../..");

$uploadDir = $rootPath . "/public/assets/img/profiles/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$filePath = $uploadDir . $empCode . ".jpg";

if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $filePath)) {

    echo json_encode([
        "status" => false,
        "message" => "Upload failed",
        "tmp" => $_FILES['profile_image']['tmp_name'],
        "dest" => $filePath,
        "error" => $_FILES['profile_image']['error']
    ]);

    exit;
}

$baseUrl = "http://localhost/sdlhub_new/public/assets/img/profiles/";

echo json_encode([
    "status" => true,
    "image" => $baseUrl . $empCode . ".jpg?v=" . time()
]);