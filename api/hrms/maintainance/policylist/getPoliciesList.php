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
    

    $policies = multiRec("
        select 
            POLI_ID, COMP_NAME, POLICY_NAME, POLICY_DESC, 
            ddmonyyyy(START_DATE)STARTDATE, ddmonyyyy(END_DATE)ENDDATE,
            DOC_PATH, STATUS, DEPT_ID, DIVISION_ID 
        from HR_POLICY order by POLI_ID desc");

    $results = [];
    foreach ($policies as $pl) {
        $results[] = [
            "ID" => (int)$pl['ID'],
            "COMP_NAME" => $pl['COMP_NAME'],
            "DEPT_ID" => $pl['DEPT_ID'],
            "DIVISION_ID" => $pl['DIVISION_ID'],
            "POLI_ID" => $pl['POLI_ID'],
            "POLICY_NAME" => $pl['POLICY_NAME'],
            "STARTDATE" => $pl['STARTDATE'],
            "ENDDATE" => $pl['ENDDATE'],
            "POLICY_DESC" => $pl['POLICY_DESC'],
            "STATUS" => $pl['STATUS']
        ];
    }

    apiResponse(true, "Policies loaded successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getPoliciesList.php"
    );

    apiResponse(false, "Unable to load policies.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
