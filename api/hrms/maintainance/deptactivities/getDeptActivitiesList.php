<?php
define('CURRENT_PORTAL', 'hrms');
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
       FETCH Questions
    =========================================== */

    $deptActivitiesListData = [];
    $type = [ 'J' => 'Join', 'E' => 'Exit' ];

    $sqltemp = multiRec("
        SELECT 
            A.ID, B.DEPT_DESC, B.DEPT_ID, A.ACT_TYPE, A.DISP_SEQ, A.ACT_DESC 
        FROM HR_DEPT_JOEX_ACTIVITY A
        INNER JOIN HR_DEPARTMENT B ON A.DEPT_ID = B.DEPT_ID 
        ORDER BY B.DEPT_DESC DESC, A.ACT_TYPE DESC, A.DISP_SEQ DESC"
    );

    $cnt = 1;
    foreach ($sqltemp as $temp) {
        
        $cnt++;
        
        $deptActivitiesListData[] = [
            "ID"            => (int)$temp["ID"],
            "DEPT_ID"       => $temp["DEPT_ID"],
            "DEPT_DESC"     => $temp["DEPT_DESC"],
            "DISP_SEQ"      => $temp["DISP_SEQ"],
            "ACT_TYPE"      => $temp['ACT_TYPE'],    
            "ACT_TYPE_TEXT" => $type[$temp['ACT_TYPE']],
            "ACT_DESC"      => $temp["ACT_DESC"],
        ];
    }
    apiResponse(true, "Department List loaded successfully.", $deptActivitiesListData);
} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getDeptActivitiesList.php"
    );

    apiResponse(false, "Unable to load department activities.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}