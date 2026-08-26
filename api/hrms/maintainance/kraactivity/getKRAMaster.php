<?php

//require_once "mt_head.php";
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

try {

    /* ===========================================
       FETCH MAIN MENUS
    =========================================== */

    $kraMasterData = [];

    $kra = multiRec("SELECT km.KRA_ID,km.KRA_DESC
        FROM  HR_KRA_MASTER km
        order by 2");

    foreach ($kra as $k) {
        $kraMasterData[] = [
            "KRA_DESC"   => $k["KRA_DESC"],
            "KRA_ID"     => $k["KRA_ID"] 
        ];
    }
    apiResponse(true, "KRA Master loaded successfully.", $kraMasterData);

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getKRAActivityList.php"
    );
    print_r($e);
    exit;
    apiResponse(false, "Unable to load kra.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}