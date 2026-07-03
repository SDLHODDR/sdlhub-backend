<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ ."/../../config/utils.php";

header('Content-Type: application/json');
session_start();

$empCode = $_SESSION['emp_code'] ?? null;
if (!$empCode) {   
    apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = '00575'; // $_SESSION['emp_code']

/* RELEASE LOCK */
session_write_close();

try {

    /* ---------------------------
       DASH ACCESS
    ---------------------------- */

    $result = 
        multiRec("
            SELECT DISTINCT edm.DASH_GRP
            FROM EPT_EMP_PROFILE eep
            INNER JOIN EPT_PROFILE_DASH epd
                ON eep.PROFILE_ID = epd.PROFILE_ID
            INNER JOIN EPT_DASH_MASTER edm
                ON epd.DASH_ID = edm.ID
            WHERE eep.EMP_CODE = '$empCode'
            AND edm.STATUS = 'A'
        ");
    
    $dashAccess = [];
    if (!empty($result)) {
        foreach ($result as $row) {
            $dashAccess[] = $row['DASH_GRP'];
        }
    }

    /* ---------------------------
       RESPONSE
    ---------------------------- */

    echo json_encode([
        "status" => true,
        "data" => [
            "dashAccess" => $dashAccess ?: []
        ]
    ]);

} catch (Throwable $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
