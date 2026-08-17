<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

// $data = json_decode(file_get_contents("php://input"), true);
// if (empty($data)) {
//     $data = $_POST;
// }
// GET /api/hrms/maintainance/policylist/getPolicyAssociations.php?id=123
// Returns DEPT_ID / DIVISION_ID arrays for one policy — used only when
// opening a record in edit mode, so the main list query doesn't need to
// carry LISTAGG joins on every row.


$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}


$policyId = trim($data['ID'] ?? '');

if ($policyId === '') {
    apiResponse(false, "Policy id is required.", null, 422);
}

try {
    $deptStmt = multiRec("SELECT DEPT_ID FROM HR_POLICY_DEPT WHERE POLICY_ID = " . $policyId . " ORDER BY DEPT_ID");

    $deptIds = [];
    foreach ($deptStmt as $row) {
        $deptIds[] = (string) $row['DEPT_ID'];
    }

    $divStmt = multiRec("SELECT DIVSN_ID FROM HR_POLICY_DIVSN WHERE POLICY_ID = " . $policyId . " ORDER BY DIVSN_ID");

    $divisionIds = [];
    foreach ($divStmt as $row) {
        $divisionIds[] = (string) $row['DIVSN_ID'];
    }

    apiResponse(true, "OK", [
        "DEPT_ID" => $deptIds,
        "DIVISION_ID" => $divisionIds,
    ]);
} catch (Throwable $e) {
    logOracleError(
        ["message" => $e->getMessage(), "file" => $e->getFile(), "line" => $e->getLine()],
        "getPolicyAssociations.php"
    );
    apiResponse(false, "Unable to fetch policy associations.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}