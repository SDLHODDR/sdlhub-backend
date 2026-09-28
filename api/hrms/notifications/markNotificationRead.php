<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/validateCsrf.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

if (!$conn) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

if ($data['ID'] === false || $data['ID'] === null) {
    apiResponse(false, "A valid notification ID is required.", null, 400);
}

try {
    startQry();
    $notifyId = $data['ID'] ?? null;

    if (!empty($notifyId) || $notifyId != "") {
        $notiFy = executeQry("UPDATE hr_notification
            SET VIEWED_ON = NVL(VIEWED_ON, SYSDATE)
            WHERE ID = '" . $notifyId . "' AND EMP_CODE = '" . $empCode. "'");
    }
    
    if($notiFy){
        endQry();
        apiResponse(true,"Notification marked as read.",['ID' => $notifyId], 200);
    } else
    {
        apiResponse(false, "Error occured", null, 200);
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "markNotificationRead.php"
    );

    apiResponse(false, "Unable to mark notification as read.", null, 500);
} finally {
    if ($stmt) {
        oci_free_statement($stmt);
    }
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}