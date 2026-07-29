<?php

require_once "cors.php";
require_once "config/db.php";
require_once "config/session.php";
require_once "config/env.php";
require_once "config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   HANDLE PREFLIGHT
=========================================== */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

/* ===========================================
   READ INPUT
=========================================== */

$data = json_decode(file_get_contents("php://input"), true);

$username = trim($data["login_code"] ?? "");
$password = trim($data["password"] ?? "");

// Remove these after testing
//$username = "00575";
//$password = "Power90";

if ($username === "" || $password === "") {
    apiResponse(false, "Login & Password required.", null, 400);
}

/* ===========================================
   ENCODE PASSWORD
=========================================== */

$encoded = encodel($password);

try {

    /* ===========================================
       LOGIN DATABASE
    =========================================== */

    $conn = $login_conn;

    if (!$conn) {
        apiResponse(false, "Database connection failed.", null, 500);
    }

    $sql = "
        SELECT
            EMP_CODE,
            NAME
        FROM SDL_USERS
        WHERE EMP_CODE = :username
          AND STATUS = 'A'
          -- AND PASS_WD = :password
    ";

    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {

        $e = oci_error($conn);
        logOracleError($e, $sql);

        apiResponse(false, "Unable to prepare login query.", null, 500);
    }

    oci_bind_by_name($stmt, ":username", $username);

    // Uncomment when password validation is enabled
    // oci_bind_by_name($stmt, ":password", $encoded);

    if (!oci_execute($stmt)) {

        $e = oci_error($stmt);
        logOracleError($e, $sql);

        oci_free_statement($stmt);

        apiResponse(false, "Database error while validating login.", null, 500);
    }

    $user = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    if (!$user) {
        apiResponse(false, "Invalid login.", null, 401);
    }

    /* ===========================================
       CREATE SESSION
    =========================================== */

    session_regenerate_id(true);

    $_SESSION["emp_code"] = $user["EMP_CODE"];
    $_SESSION["name"] = $user["NAME"];

    /* ===========================================
       PROFILE IMAGE
    =========================================== */

    $profileImage = null;

    $filePath =
        rtrim($_ENV["PUBLIC_PATH"], "/") .
        "/profiles/" .
        $user["EMP_CODE"] .
        ".jpg";

    $imageUrl =
        rtrim($_ENV["PROFILES_URL"], "/") .
        "/" .
        $user["EMP_CODE"] .
        ".jpg";

    if (file_exists($filePath)) {
        $profileImage = $imageUrl . "?v=" . filemtime($filePath);
    }

    $user["profile_image"] = $profileImage;
    $_SESSION["profile_image"] = $profileImage;

    /* ===========================================
       CSRF TOKEN
    =========================================== */

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    session_write_close();

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Login successful.",
        [
            "user" => $user,
            "csrf_token" => $_SESSION["csrf_token"],
            "redirect" => "/eportal/dashboard"
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine()
        ],
        "login.php"
    );

    apiResponse(false, "Something went wrong while processing your request.",null, 500);
}