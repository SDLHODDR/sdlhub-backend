<?php

//require_once "../mt_head.php";

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

    $kraActivityData = [];

    $kra = multiRec("SELECT ka.ID,km.KRA_DESC,ka.ACTT_DESC,ka.KRA_ID 
        FROM HR_KRA_ACTIVITY ka 
        INNER JOIN HR_KRA_MASTER km 
        ON km.KRA_ID = ka.KRA_ID
        WHERE ka.STATUS != 'D'
        order by 2");

    foreach ($kra as $k) {
        

        $kraActivityData[] = [
            "ID"         => (int)$k["ID"],
            "KRA_DESC"   => $k["KRA_DESC"],
            "ACTT_DESC"  => $k["ACTT_DESC"],
            "KRA_ID"     => $k["KRA_ID"] 
        ];
    }
    apiResponse(true, "KRA Activity loaded successfully.", $kraActivityData);

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getKRAActivityList.php"
    );

    apiResponse(false, "Unable to load kra.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}