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
    //$orgId = $data['ID'] ?? null;
    // $locId = $data['LOC_ID'] ?? null;

    // if (!$locId ) {
    //     apiResponse(false, "Organogram Id is required", null, 500);
    //     exit;
    // }

    $getData = multiRec("SELECT 
      DISTINCT hol.id,
      hol.ID || ' - '|| GET_EMP_NAME(get_org_loc_emp_code(HOL.ID, sysdate)) || ' - '|| GET_SHCOMP_NAME(ho.COMPANY) ||' - '|| GET_DIVISION_NAME(ho.DIVSN_ID)|| ' - ' || GET_DEPT_NAME(ho.DEPT_ID) ||' - '|| GET_DESIGN_NAME(ho.DESI_ID) || '(' || HOL.GEO_DESC || ')' AS DESCR
      FROM HR_ORGANOGRAM ho
      INNER JOIN HR_ORGANOGRAM_LOC hol ON ho.ID=hol.ORG_ID
      INNER JOIN HR_DEPARTMENT d ON ho.DEPT_ID=d.dept_id
      INNER JOIN HR_DESIGNATION dd ON ho.DESI_ID=dd.DESI_ID
      INNER JOIN HR_DIVISIONS div ON ho.DIVSN_ID = div.DIVSN_ID");
    
    if ( empty($getData) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    $results = [];
    foreach ($getData as $repDt) {
        $result[] = [
            "ID" => $repDt['ID'],
            "DESCR" => $repDt['DESCR'],
        ];
    }

    apiResponse(true, "Organogram Appraisal data fetched successfully.", $result);
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