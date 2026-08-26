<?php

ob_start();

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

try {

    /* ==========================================================
       SESSION VALIDATION
    ========================================================== */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse( false, "Session expired. Please login again.", null,401);
    }

    /* ==========================================================
       DATABASE
    ========================================================== */

    if (!$conn) {
		apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    /* ==========================================================
       FETCH MASTER TABLES
    ========================================================== */

    $sql = "
        SELECT DISTINCT
            TAB_NAME,
            TITLE
        FROM HR_MST_TABLES
        ORDER BY TITLE
    ";

    $masterTables = multiRec($sql, $conn);


    /* ==========================================================
       FORMAT RESPONSE
    ========================================================== */

    $data = [];

    foreach ($masterTables as $row) {

        $data[] = [
            "tabName" => $row["TAB_NAME"],
            "title"   => $row["TITLE"]
        ];
    }


    /* ==========================================================
       SUCCESS RESPONSE
    ========================================================== */

    apiResponse(true, "Master tables fetched successfully.", $data, 200);


} catch (Throwable $e) {

    /* ==========================================================
       LOG ERROR
    ========================================================== */

    logOracleError($e);


    /* ==========================================================
       GENERIC ERROR RESPONSE
    ========================================================== */

    apiResponse(false, "Unable to fetch master tables.", null, 500);
}
