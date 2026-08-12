<?php

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
    

    $divisions = multiRec("select DIVSN_ID, DIVSN_DESC from HR_DIVISIONS order by 2");

    $results = [];
    foreach ($divisions as $dv) {
        $results[] = [
            "DIVSN_ID" => (int)$dv['DIVSN_ID'],
            "DIVSN_DESC" => $dv['DIVSN_DESC'],
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
