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

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}

try {
    $orgCompany = multiRec("select COMP_ID, COMP_ID || ' - ' ||  COMP_DESC as COMP_DESC from HR_COMPANY order by 1");

    if ( empty($orgCompany) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    
    $results = [];
    foreach ($orgCompany as $orgCMP) {
        $results[] = [
            "COMP_ID" => $orgCMP['COMP_ID'],
            "COMP_DESC" => $orgCMP['COMP_DESC'],
        ];
    }
    
    apiResponse(true, "Organograms COMPANY fetched successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getOrganogramData.php"
    );

    apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
