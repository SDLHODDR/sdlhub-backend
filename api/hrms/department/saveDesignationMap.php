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

    $input = readJsonInput();
    if (!is_array($input)) {
        $input = [];
    }

    $input = array_merge($input, $_POST);

    $deptId = trim((string)($input['dept_id'] ?? $input['DEPT_ID'] ?? $input['deptId'] ?? $input['DEPT_CODE'] ?? ''));
    $designations = $input['designations'] ?? $input['designation_ids'] ?? $input['designationIds'] ?? [];

    if ($deptId === '') {
        apiResponse(false, "Department ID is required.", null, 400);
    }

    $deptId = addslashes($deptId);
    $designations = is_array($designations) ? $designations : [$designations];

    startQry();

    $deleteSql = "DELETE FROM HR_DES_DEPT_MAP WHERE DEPT_ID='" . $deptId . "'";
    executeQry($deleteSql);

    foreach ($designations as $designationId) {
        $designationId = trim((string)$designationId);
        if ($designationId === '') {
            continue;
        }

        $designationId = addslashes($designationId);
        executeQry("INSERT INTO HR_DES_DEPT_MAP (DESIG_ID, DEPT_ID, CHG_ON, CHG_BY)
                    VALUES ('{$designationId}', '{$deptId}', SYSDATE, '" . addslashes($_SESSION['loginId'] ?? $_SESSION['emp_code']) . "')");
    }

    endQry('Updated');
    apiResponse(true, "Designation mapping saved successfully.", ['dept_id' => $deptId], 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to save designation mapping.", null, 500);
}
