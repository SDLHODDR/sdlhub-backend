<?php

require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/db.php";

global $login_conn;
$sql___func___con = $login_conn;

require_once __DIR__ . "/config/functions.php";
require_once __DIR__ . "/config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */
if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

try {
    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Not logged in.", null, 401);
    }

    /* ===========================================
       FETCH APPLICATIONS
    =========================================== */

    $empCode = str_replace("'", "''", $empCode);

    $apps = multiRec("
        SELECT
            A.ID,
            A.APP_NAME,
            A.APP_URL,
            A.APP_BTN_ID,
            A.APP_ICON
        FROM SDL_APPS A
        INNER JOIN SDL_APP_ACCESS AA
            ON A.ID = AA.APP_ID
        WHERE AA.EMP_CODE = '{$empCode}'
        ORDER BY A.APP_NAME
    ");

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Applications fetched successfully.",
        [
            "user" => $_SESSION['name'] ?? '',
            "apps" => $apps
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getApps.php"
    );

    apiResponse(false, "Unable to fetch applications.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}

//output:
/*{
  "status": true,
  "message": "Applications fetched successfully.",
  "data": {
    "user": "John Doe",
    "apps": [
      {
        "ID": "1",
        "APP_NAME": "EPortal",
        "APP_URL": "/eportal",
        "APP_BTN_ID": "eportal",
        "APP_ICON": "ti ti-home"
      },
      {
        "ID": "2",
        "APP_NAME": "HRMS",
        "APP_URL": "/hrms",
        "APP_BTN_ID": "hrms",
        "APP_ICON": "ti ti-users"
      }
    ]
  }
}*/