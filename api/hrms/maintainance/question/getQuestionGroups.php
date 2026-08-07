<?php

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
       FETCH Questions Group Master
    =========================================== */
    $questionGrpMasterData = [];

    $sqltemp = multiRec("SELECT QSGRP_ID, QSGRP_DESC from HR_QUESTION_SGROUP");
    foreach ($sqltemp as $temp) {
    
        $questionGrpMasterData[] = [
            "QSGRP_ID"         => (int)$temp["QSGRP_ID"],
            "QSGRP_DESC" => $temp["QSGRP_DESC"]
        ];
    }
    apiResponse(true, "Question gROUP Master loaded successfully.", $questionGrpMasterData);
} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getQuestionGroups.php"
    );

    apiResponse(false, "Unable to load question group master.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}