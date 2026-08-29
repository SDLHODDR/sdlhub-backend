<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

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
    startQry();
	if (!empty($data['ACT_ID'] || $data['ID'])) {
        $kIDD = ($data['ACT_ID']) ? $data['ACT_ID'] : $data['ID'];
        $kraR = executeQry("update HR_KRA_ACTIVITY set
                            KRA_ID='" . trim($data['KRA_ID']) . "',
							ACTT_DESC= '" . trim($data['ACTT_DESC']) . "',
							CHG_BY= '" . trim($empCode) . "',
							CHG_ON =sysdate
					where ID='" . $kIDD . "'");
        endQry();
        if($kraR){
            apiResponse(
                true,
                "KRA Activity updated successfully",
                [
                    "ACT_ID" => $kIDD,
                ]
            );
        } else
        {
            apiResponse(false, "Error occured 111", null, 200);
        }
	} else {
		// Check if the record already exists
		// $KId = singRec("select KRA_ID from HR_KRA_ACTIVITY where KRA_ID='" . $data['KRA_ID'] . "' ");
		// if ($KId) {
		// 	endQry("Record Already Exists!");
        //     apiResponse(false, "Record Already Exists", null, 200);
        //     exit;
		// } else {
			$kra = executeQry("insert into HR_KRA_ACTIVITY(ID,KRA_ID,ACTT_DESC,CHG_BY,CHG_ON,status)
								values (
								'',
                                '" . trim($data['KRA_ID']) . "',
								'" . trim($data['ACTT_DESC']) . "',
								'" . trim($empCode) . "',
								sysdate , 'A') returning  ID into :kra ", 'kra');
		//}
	}
	// exit;
	endQry('Saved Successfully');
    if($kra){
        apiResponse(
            true,
            "KRA Activity saved successfully",
            [
            "ACT_ID" => $kra,
            ]
        );
    } else
    {
        apiResponse(false, "Error occured 222", null, 200);
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