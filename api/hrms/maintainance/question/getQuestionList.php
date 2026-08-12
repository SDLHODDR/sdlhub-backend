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
       FETCH Questions
    =========================================== */

    $questionMasterData = [];
    $type = array('T' => 'Text Box', 'S' => 'Select Box', 'R' => 'Radio Box', 'C' => 'Check Box');

    $sqltemp = multiRec("
        SELECT * FROM HR_QUESTION_MASTER a
        INNER JOIN hr_question_sgroup b
        ON a.qgrp_id = b.qsgrp_id
        INNER JOIN hr_question_ssgroup c
        ON a.qsgrp_id = c.qssgrp_id 
        WHERE a.status!='D' ORDER BY qgrp_id"
    );

    $cnt = 1;
    foreach ($sqltemp as $temp) {
        $optsall = multiRec("
            SELECT OPTS_TEXT FROM HR_QUESTION_OPTS 
            WHERE QUESTION_ID='" . $temp['ID'] . "'");
        
        $optionsImpld = implode(', ', array_column($optsall, 'OPTS_TEXT'));
        $cnt++;
        
        $questionMasterData[] = [
            "ID"         => (int)$temp["ID"],
            "QSGRP_DESC" => $temp["QSGRP_DESC"],
            "QSSGRP_DESC" => $temp["QSSGRP_DESC"],
            "QSGRP_ID" => $temp["QSGRP_ID"],
            "QSSGRP_ID" => $temp["QSSGRP_ID"],
            "QUESTION"   => $temp["QUESTION"],
            "RATING"     => $type[$temp['RATING_TYPE']],
            "OPTIONS"    => $optionsImpld 
        ];
    }
    apiResponse(true, "Question Master loaded successfully.", $questionMasterData);
} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getQuestionList.php"
    );

    apiResponse(false, "Unable to load question master.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}