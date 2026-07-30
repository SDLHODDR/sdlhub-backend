<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/functions.php";

header("Content-Type: application/json");

try {

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
    | VERIFY EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '".$empCode."'
    ");

    if (empty($employee["ID"])) {
        apiResponse(false, "Employee not found.", null, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | READ INPUT
    |--------------------------------------------------------------------------
    */

    $profile = $_GET['profile'] ?? '';

    if (empty($profile) || !ctype_digit($profile)) {
        apiResponse(false, "Invalid profile.", null, 400);
    }

    $response = [];

    /*
    |--------------------------------------------------------------------------
    | MENUS
    |--------------------------------------------------------------------------
    */

    $menus = multiRec("
        SELECT *
        FROM EPT_MENU
        WHERE STATUS = 'A'
        ORDER BY SEQ
    ");

    $menuData = [];

    foreach ($menus as $menu) {

        $submenus = multiRec("
            SELECT *
            FROM EPT_SUBMENU
            WHERE MENU_ID = ".$menu["ID"]."
            AND STATUS = 'A'
            ORDER BY SEQ
        ");

        $menu["submenus"] = $submenus;

        $menuData[] = $menu;
    }

    $response["menus"] = $menuData;

        /*
    |--------------------------------------------------------------------------
    | MENU ACCESS
    |--------------------------------------------------------------------------
    */

    $menuAccess = multiRec("
        SELECT
            MENU_ID,
            SUB_MENU_ID
        FROM EPT_PROFILE_MENU
        WHERE PROFILE_ID = ".$profile."
    ");

    $menuArr = [];
    $subArr = [];

    foreach ($menuAccess as $menu) {

        if (!empty($menu["MENU_ID"])) {
            $menuArr[$menu["MENU_ID"]] = true;
        }

        if (!empty($menu["SUB_MENU_ID"])) {
            $subArr[$menu["SUB_MENU_ID"]] = true;
        }
    }

    $response["menuAccess"] = $menuArr;
    $response["submenuAccess"] = $subArr;

    /*
    |--------------------------------------------------------------------------
    | TASK MASTER
    |--------------------------------------------------------------------------
    */

    $tasks = multiRec("
        SELECT
            ID,
            TASK_DESC
        FROM EPT_TASK_MASTER
        ORDER BY ID
    ");

    $response["tasks"] = $tasks;

    /*
    |--------------------------------------------------------------------------
    | TASK ACCESS
    |--------------------------------------------------------------------------
    */

    $taskAccess = multiRec("
        SELECT
            TASK_ID
        FROM EPT_PROFILE_TASK
        WHERE PROFILE_ID = ".$profile."
    ");

    $taskArr = [];

    foreach ($taskAccess as $task) {

        if (!empty($task["TASK_ID"])) {
            $taskArr[] = $task["TASK_ID"];
        }
    }

    $response["taskAccess"] = $taskArr;

        /*
    |--------------------------------------------------------------------------
    | DASHBOARDS
    |--------------------------------------------------------------------------
    */

    $dashboards = multiRec("
        SELECT
            ID,
            DASH_DESC
        FROM EPT_DASH_MASTER
        WHERE STATUS = 'A'
        ORDER BY DASH_DESC
    ");

    $response["dashboards"] = $dashboards;

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD ACCESS
    |--------------------------------------------------------------------------
    */

    $dashAccess = multiRec("
        SELECT
            DASH_ID
        FROM EPT_PROFILE_DASH
        WHERE PROFILE_ID = ".$profile."
    ");

    $dashArr = [];

    foreach ($dashAccess as $dashboard) {

        if (!empty($dashboard["DASH_ID"])) {
            $dashArr[] = $dashboard["DASH_ID"];
        }
    }

    $response["dashAccess"] = $dashArr;

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(true, "Profile access fetched successfully.", $response);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    logOracleError($e);

    apiResponse(false, "Unable to fetch profile access.", null, 500);

} finally {

    /*
    |--------------------------------------------------------------------------
    | CLOSE CONNECTION
    |--------------------------------------------------------------------------
    */

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }

}