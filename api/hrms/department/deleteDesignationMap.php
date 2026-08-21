<?php

ob_start();

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
    $designationIds = $input['designation_ids'] ?? $input['designationIds'] ?? $input['desig_id'] ?? $input['DESIG_ID'] ?? $input['desig_ids'] ?? [];
    $mappingId = trim((string)($input['id'] ?? $input['ID'] ?? ''));

    if ($deptId === '' && empty($designationIds) && $mappingId === '') {
        apiResponse(false, "Department or designation identifier is required.", null, 400);
    }

    startQry();

    if ($mappingId !== '') {
        executeQry("DELETE FROM HR_DES_DEPT_MAP WHERE ID='" . addslashes($mappingId) . "'");
        endQry('Deleted');
        apiResponse(true, "Designation mapping deleted successfully.", null, 200);
    }

    if ($deptId !== '' && empty($designationIds)) {
        executeQry("DELETE FROM HR_DES_DEPT_MAP WHERE DEPT_ID='" . addslashes($deptId) . "'");
        endQry('Deleted');
        apiResponse(true, "Designation mappings deleted for the department.", ['dept_id' => $deptId], 200);
    }

    if (!is_array($designationIds)) {
        $designationIds = [$designationIds];
    }

    $conditions = [];
    foreach ($designationIds as $designationId) {
        $designationId = trim((string)$designationId);
        if ($designationId !== '') {
            $conditions[] = "DESIG_ID='" . addslashes($designationId) . "'";
        }
    }

    if (count($conditions) === 0) {
        endQry('Deleted');
        apiResponse(true, "No designations provided for deletion.", null, 200);
    }

    $conditionString = implode(' OR ', $conditions);
    $sql = "DELETE FROM HR_DES_DEPT_MAP WHERE ({$conditionString})";

    if ($deptId !== '') {
        $sql .= " AND DEPT='" . addslashes($deptId) . "'";
    }

    executeQry($sql);
    endQry('Deleted');
    apiResponse(true, "Designation mapping deleted successfully.", null, 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to delete designation mapping.", null, 500);
}
