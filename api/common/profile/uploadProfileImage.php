<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       FILE VALIDATION
    =========================================== */

    if (
        !isset($_FILES['profile_image']) ||
        $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK
    ) {
        apiResponse(false, "No file uploaded.", null, 400);
    }

    /* ===========================================
       CREATE DIRECTORY IF NOT EXISTS
    =========================================== */

    $uploadDir = rtrim($_ENV["PUBLIC_PATH"], "/") . "/profiles/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    /* ===========================================
       SAVE IMAGE
    =========================================== */

    $fileName = $empCode . ".jpg";
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $filePath)) {
        apiResponse(false, "Failed to upload profile image.", null, 500);
    }

    /* ===========================================
       BUILD IMAGE URL
    =========================================== */

    $imageUrl = rtrim($_ENV["PROFILES_URL"], "/")
        . "/"
        . $fileName
        . "?v="
        . filemtime($filePath);

    apiResponse(
        true,
        "Profile image uploaded successfully.",
        [
            "image" => $imageUrl
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "uploadProfileImage.php"
    );

    apiResponse(false, "Unable to upload profile image.", null, 500);
}