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
    $organograms = multiRec(
        "select ID, GET_SHCOMP_NAME(COMPANY)|| ' - ' || GET_DIVISION_NAME(DIVSN_ID)|| ' - ' ||GET_DEPT_NAME(DEPT_ID)|| ' - ' || GET_DESIGN_NAME(DESI_ID) as ORGNGM_OPTIONS, CAPA_DESC
         from HR_CAPABILITIES
         order by CAPA_CODE"
    );

    if ( empty($organograms) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    $results = [];
    foreach ($organograms as $cap) {
        $results[] = [
            "CAPA_ID" => (int)$cap['CAPA_ID'],
            "CAPA_CODE" => $cap['CAPA_CODE'],
            "CAPA_DESC" => $cap['CAPA_DESC'],
        ];
    }
    
    apiResponse(true, "Organograms fetched successfully.", $results);
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
