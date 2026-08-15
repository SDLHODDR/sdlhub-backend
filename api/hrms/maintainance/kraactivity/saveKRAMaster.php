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
	if(!empty($data['KRA_DESC'])){
        // Check if the record already exists
        $KIdCHK = singRec("select KRA_ID from HR_KRA_MASTER where KRA_DESC='" . $data['KRA_DESC'] . "' ");
        if ($KIdCHK) {
            endQry("Record Already Exists!");
            apiResponse(false, "Record Already Exists", null, 200);
            exit;
        } else {
            $kraId = executeQry("insert into HR_KRA_MASTER(KRA_ID,KRA_DESC,CHG_BY,CHG_ON)
                                values (
                                '',
                                '" . trim($data['KRA_DESC']) . "',
                                '" . trim($empCode) . "',
                                sysdate ) returning  KRA_ID into :kraId ", 'kraId');
        }
        
        // exit;
        endQry('Saved Successfully');
        if($kraId){
            apiResponse(
                true,
                "KRA Master saved successfully",
                [
                "KRA_ID" => $kraId,
                ]
            );
        } else
        {
            apiResponse(false, "Error occured 222", null, 200);
        }
    } else {
         apiResponse(false, "KRA Description required", null, 200);
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

    apiResponse(false, "Unable to save kra activity.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}