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

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}

try {
    $deptId = $data['DEPARTMENT_ID'] ?? null;

    if (!$deptId) {
        apiResponse(false, "Department Id is required", null, 500);
        exit;
    }

    $organogramDesign = multiRec("SELECT hd.desi_id AS ID, hd.desi_id || ' - ' || hd.desi_desc AS LABEL
            FROM HR_DES_DEPT_MAP hddm
            INNER JOIN HR_DESIGNATION hd ON hddm.desig_id = hd.desi_id
            WHERE hddm.dept_id = '" . $deptId . "'
            ORDER BY hd.desi_desc");
    
    if ( empty($organogramDesign) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    print_r($organogramDesign);
    exit;
    
    $results = [];
    foreach ($organogramDesign as $org) {
        $results[] = [
            "ID" => (int)$org['ID'],
            "FINENT" => $org['FINENT'],
            "COMPANY" => $org["COMPANY"],
            "LABEL" => $org['LABEL'],
            "DEPT_ID" => $org["DEPT_ID"],
            "DESI_ID" => $org["DESI_ID"],
            "DIVSN_ID" => $org["DIVSN_ID"],
            "OLVL_ID" => $org['OLVL_ID'],
            "POSI_COUNT" => $org['POSI_COUNT'],
            "FILL_COUNT" => $org['FILL_COUNT'],
            "PARENT_ORGID" => $org['PARENT_ORGID'],
            "JD_ID" => $org['JD_ID'],
            "EMP_LEVEL" => $org['EMP_LEVEL'],
            "STATUS" => $org['STATUS'],
            "STATUSTXT" => $org['STATUS'],
            "CHG_ON" => $org['CHG_ON'],
            "CHG_BY" => $org['CHG_BY'],
            "NOTICE_DAYS" => $org['NOTICE_DAYS']
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
