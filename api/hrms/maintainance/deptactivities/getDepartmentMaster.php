<?php

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";
//require_once __DIR__ . "/../../../config/hr_func.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

/* ===========================================
    SESSION VALIDATION
=========================================== */
$empCode = $_SESSION['emp_code'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

/* ---------------------------
        READ INPUT
---------------------------- */
$data = json_decode(file_get_contents("php://input"), true);

try {
    /* ===========================================
       FETCH Questions Group Master
    =========================================== */
     $departmentMasterData = [];

    $sqltemp = multiRec("SELECT DEPT_ID, DEPT_DESC FROM HR_DEPARTMENT ORDER BY DEPT_DESC");
    foreach ($sqltemp as $temp) {
    
        $departmentMasterData[] = [
            "DEPT_ID"         => (int)$temp["DEPT_ID"],
            "DEPT_DESC" => $temp["DEPT_DESC"]
        ];
    }
    apiResponse(true, "Department Master loaded successfully.", $departmentMasterData);
} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getDepartmentMaster.php"
    );

    apiResponse(false, "Unable to load department master.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}