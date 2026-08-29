<?php
define('CURRENT_PORTAL', 'hrms');
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

try {
    

    $department = multiRec("SELECT DEPT_ID, DEPT_DESC FROM HR_DEPARTMENT");

    $results = [];
    foreach ($department as $dt) {
        $results[] = [
            "DEPT_ID" => (int)$dt['DEPT_ID'],
            "DEPT_DESC" => $dt['DEPT_DESC'],
        ];
    }

    apiResponse(true, "Department loaded successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getCompanyMaster.php"
    );

    apiResponse(false, "Unable to load Policy Company data.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
