<?php

ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

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

    $deptId = $_GET['id'] ?? null;
    $bindWhere = '';

    if (!empty($deptId)) {
        $deptId = addslashes($deptId);
        $bindWhere = "WHERE d.DEPT_CODE = '{$deptId}'";
    }

    $sql = "SELECT d.DEPT_CODE,
                   d.DEPT_DESC,
                   des.DESI_ID,
                   des.DESI_DESC
            FROM HR_DEPARTMENT d
            LEFT JOIN HR_DES_DEPT_MAP m ON m.DEPT_ID = d.DEPT_CODE
            LEFT JOIN HR_DESIGNATION des ON des.DESI_ID = m.DESIG_ID
            {$bindWhere}
            ORDER BY d.DEPT_DESC, des.DESI_DESC";

    $rows = multiRec($sql);

    $departments = [];


    foreach ($rows as $row) {
        $deptCode = $row['DEPT_CODE'];
        if (!isset($departments[$deptCode])) {
            $departments[$deptCode] = [
                'DEPT_ID' => $deptCode,
                'DEPT_CODE' => $deptCode,
                'DEPT_NAME' => $row['DEPT_DESC'],
                'department' => $row['DEPT_DESC'],
                'DESIGNATIONS' => [],
            ];
        }

        if (!empty($row['DESI_ID'])) {
            $departments[$deptCode]['DESIGNATIONS'][] = [
                'ID' => $row['DESI_ID'],
                'DESIG_ID' => $row['DESI_ID'],
                'DESIG_NAME' => $row['DESI_DESC'],
                'designation' => $row['DESI_DESC'],
            ];
        }
    }

    $response = array_values($departments);
    apiResponse(true, "Designation map fetched successfully.", ['data' => $response], 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch designation map.", null, 500);
}
