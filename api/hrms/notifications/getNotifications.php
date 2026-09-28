<?php

ob_start();

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

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

//$data = json_decode(file_get_contents("php://input"), true);

$notifications = [];
$stmt = null;

try {
    $raw_notifications= multiRec("SELECT ID, EMP_CODE, ASON_DATE, ATTN_TYPE, DESCR, VIEWED_ON
            FROM HR_NOTIFICATION
            WHERE EMP_CODE = '" . $empCode. "'
            ORDER BY ASON_DATE DESC");

    $notifications = [];
    
    foreach ($raw_notifications as $rowNoti) {
        $notifications[] = [
            'ID' => $rowNoti['ID'],
            'EMP_CODE' => $rowNoti['EMP_CODE'],
            'ASON_DATE' => $rowNoti['ASON_DATE'],
            'ATTN_TYPE' => $rowNoti['ATTN_TYPE'],
            'DESCR' => $rowNoti['DESCR'],
            'VIEWED_ON' => $rowNoti['VIEWED_ON'],
        ];
    }

    apiResponse(true, "Notifications fetched successfully.", $notifications, 200);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getNofications.php"
    );

    apiResponse(false, "Unable to load notifications.", null, 500);
} finally {
    if ($stmt) {
        oci_free_statement($stmt);
    }
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
