<?php

ob_start();

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {
    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    if (!$conn) {
        apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    $sql = "SELECT DESI_ID, DESI_DESC FROM HR_DESIGNATION ORDER BY DESI_DESC";

    $rows = multiRec($sql, $conn);
    $designations = [];

    foreach ($rows as $row) {
        $designations[] = [
            'ID' => $row['DESI_ID'],
            'DESIG_ID' => $row['DESI_ID'],
            'DESIG_NAME' => $row['DESI_DESC'],
            'designation' => $row['DESI_DESC'],
        ];
    }

    apiResponse(true, "Designations fetched successfully.", ['data' => $designations], 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch designations.", null, 500);
}
