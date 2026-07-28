<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    apiResponse(false, "Invalid request method", null, 405);
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

$empCode = $_SESSION["emp_code"] ?? "";

if (empty($empCode)) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

/*
|--------------------------------------------------------------------------
| RELEASE SESSION LOCK
|--------------------------------------------------------------------------
*/

session_write_close();

try {

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD ACCESS
    |--------------------------------------------------------------------------
    */

    $result = multiRec("
        SELECT DISTINCT edm.DASH_GRP
        FROM EPT_EMP_PROFILE eep
        INNER JOIN EPT_PROFILE_DASH epd
            ON eep.PROFILE_ID = epd.PROFILE_ID
        INNER JOIN EPT_DASH_MASTER edm
            ON epd.DASH_ID = edm.ID
        WHERE eep.EMP_CODE = '".$empCode."'
        AND edm.STATUS = 'A'
        ORDER BY edm.DASH_GRP
    ");

    $dashAccess = [];

    foreach ($result as $row) {
        $dashAccess[] = $row["DASH_GRP"];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(true, "Dashboard access fetched successfully", [
        "dashAccess" => $dashAccess
    ]);

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch dashboard access.", null, 500);
}