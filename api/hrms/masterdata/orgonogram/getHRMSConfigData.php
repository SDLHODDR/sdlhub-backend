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
    $CompDet = multiRec("SELECT PC.COMP_ID FROM HR_PROFILE_COMPANY PC 
            INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = PC.PROFILE_ID
            WHERE EP.EMP_CODE = '" . $empCode . "' AND PC.STATUS = 'A' and sysdate between EP.effec_from and nvl(Ep.effec_to,'01-Mar-3000')");
    $divDet = multiRec("SELECT PD.DIVISION_ID FROM HR_PROFILE_DIVISIONS PD 
            INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = PD.PROFILE_ID
            WHERE EP.EMP_CODE = '" . $empCode . "' AND PD.STATUS = 'A' AND sysdate between EP.effec_from and nvl(Ep.effec_to,'01-Mar-3000')");
    $deptDet = multiRec("SELECT DE.DEPT_ID FROM HR_PROFILE_DEPARTMENT DE 
            INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = DE.PROFILE_ID
            WHERE EP.EMP_CODE = '" . $empCode . "' AND DE.STATUS = 'A' AND sysdate between EP.effec_from and nvl(Ep.effec_to,'01-Mar-3000')");
    
    if ( empty($CompDet) || empty($divDet) || empty($deptDet)) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    } else {
        $_SESSION['compId'] = array();
        $_SESSION['divId'] = array();
        $_SESSION['deptId'] = array();
        foreach ($CompDet as $row) {
            $_SESSION['compId'][] = $row['COMP_ID'];
        }
        foreach ($divDet as $row) {
            $_SESSION['divId'][] = $row['DIVISION_ID'];
        }
        foreach ($deptDet as $code) {
            $_SESSION['deptId'][] = $code['DEPT_ID'];
        }

        $sanitizedCompIds = array_map('intval', $_SESSION['compId']);
        $sanitizedDivIds = array_map('intval', $_SESSION['divId']);
        $sanitizedDeptCodes = array_map('intval', $_SESSION['deptId']);
        $compIdsString = "'" . implode("','", $sanitizedCompIds) . "'";
        $divIdsString = "'" . implode("','", $sanitizedDivIds) . "'";
        $deptCodesString = "'" . implode("','", $sanitizedDeptCodes) . "'";
    }

    $results = [];
    $results = [
        "SESSION_COMP_ID" => $_SESSION['compId'],
        "SESSION_DIVISION_ID" => $_SESSION['divId'],
        "SESSION_DEPT_ID" => $_SESSION['deptId'],
        "COMP_ID_STR" => $compIdsString,
        "DIVISION_ID_STR" => $divIdsString,
        "DEPT_ID_STR" => $deptCodesString,
    ];
    

    apiResponse(true, "User HRMS config fetched successfully.", $results);
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
