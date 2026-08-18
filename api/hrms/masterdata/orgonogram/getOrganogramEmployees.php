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

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}

try {
    print_r($data); 
    exit;
    $deptId = $data['DEPARTMENT_ID'] ?? null;
    $desigId = $data['DESIGNATION_ID'] ?? null;

    if (!$deptId && !$desigId) {
        apiResponse(false, "Department Id and Designation Id is required", null, 500);
        exit;
    }

    $organogramJDLbl = multiRec("select ID,ID || ' - ' || SH_DESC as LABEL from HR_JD where dept_id = '" . $deptId . "' and DESIG_ID = '" . $desigId . "' order by SH_DESC");
    
    if ( empty($organogramJDLbl) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    $results = [];
    foreach ($organogramJDLbl as $org) {
        $results[] = [
            "ID" => (int)$org['ID'],
            "LABEL" => $org['LABEL']
        ];
    }
    
    apiResponse(true, "Organogram data fetched successfully.", $results);
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