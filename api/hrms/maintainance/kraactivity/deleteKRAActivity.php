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
    startQry();
	if (!empty($data['ACT_ID'] || $data['ID'])) {
        $kIDD = ($data['ACT_ID']) ? $data['ACT_ID'] : $data['ID'];
        $kraR = executeQry("update HR_KRA_ACTIVITY set
                            STATUS='D'
                           WHERE ID='" . $kIDD . "'");
        endQry();
        if($kraR){
            apiResponse(
                true,
                "KRA Activity deleted successfully",
                [],
            );
        } else
        {
            apiResponse(false, "Error occured", null, 200);
        }
	} 
} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "saveKRAActivity.php"
    );

    apiResponse(false, "Unable to process kra activity.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}