<?php
define('CURRENT_PORTAL', 'hrms');

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    require_once __DIR__ . "/../../config/utils.php";
    apiResponse(false, "Invalid request method", null, 405);
}

/*
|--------------------------------------------------------------------------
| SESSION & CACHE LOOKUP
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$empCode = $_SESSION["emp_code"] ?? $_SESSION["EmpCode"] ?? null;

if (!$empCode) {
    require_once __DIR__ . "/../../config/utils.php";
    apiResponse(false, "Unauthorized Access", null, 401);
}

// 1. FAST PATH: Return cached menu if available in session
if (!empty($_SESSION["USER_HRMS_MENU"])) {
    $cachedMenu = $_SESSION["USER_HRMS_MENU"];
    session_write_close();
    
    // Add client-side browser cache headers (5 minutes)
    header("Cache-Control: private, max-age=300");
    require_once __DIR__ . "/../../config/utils.php";
    apiResponse(true, "HRMS menu fetched from cache", $cachedMenu);
    exit;
}

session_write_close();

/*
|--------------------------------------------------------------------------
| DATABASE EXECUTION (ONLY RUNS ONCE PER SESSION / LOGIN)
|--------------------------------------------------------------------------
*/
$sql___func___con = db_hrms();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

try {
    /*
      OPTIMIZATION DETAILS:
      - Filter directly on HR_EMP_PROFILE.EMP_CODE.
      - Use EXISTS for HR_EMPLOYEE_INFO status to prevent duplicate row multiplication before DISTINCT.
      - Index suggestions:
          HR_EMP_PROFILE (EMP_CODE, EFFEC_FROM, EFFEC_TO, PROFILE_ID)
          HR_PROFILE_MENU (PROFILE_ID, SUB_MENU_ID)
          HR_MENU_SUB (ID, MENU_ID, STATUS, SEQ)
    */
    $sql = "
        SELECT DISTINCT
            M.ID AS MENU_ID,
            M.LABEL AS MENU_LABEL,
            M.ICON AS MENU_ICON,
            M.SEQ AS MENU_SEQ,
            MS.ID AS SUB_MENU_ID,
            MS.LABEL AS SUB_MENU_LABEL,
            MS.PROG_URL,
            MS.SEQ AS SUB_MENU_SEQ
        FROM HR_EMP_PROFILE EP
        INNER JOIN HR_PROFILE_MENU PM
            ON PM.PROFILE_ID = EP.PROFILE_ID
        INNER JOIN HR_MENU_SUB MS
            ON MS.ID = PM.SUB_MENU_ID AND MS.STATUS = 'A'
        INNER JOIN HR_MENU M
            ON M.ID = MS.MENU_ID
        WHERE EP.EMP_CODE = :emp_code
          AND TRUNC(SYSDATE) BETWEEN EP.EFFEC_FROM AND NVL(EP.EFFEC_TO, DATE '3000-03-01')
          AND EXISTS (
              SELECT 1 
              FROM HR_EMPLOYEE_INFO EI 
              WHERE EI.EMP_CODE = EP.EMP_CODE 
                AND EI.STATUS = 'A'
          )
        ORDER BY M.SEQ, MS.SEQ
    ";

    $stmt = oci_parse($sql___func___con, $sql);
    if (!$stmt) {
        $error = oci_error($sql___func___con);
        throw new Exception($error["message"]);
    }

    // Prefetch all menu items in a single network roundtrip (typically ~100 rows)
    oci_set_prefetch($stmt, 150);

    oci_bind_by_name($stmt, ":emp_code", $empCode);

    if (!oci_execute($stmt)) {
        $error = oci_error($stmt);
        throw new Exception($error["message"]);
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD STRUCTURED MENU TREE
    |--------------------------------------------------------------------------
    */
    $menus = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $menuId = $row["MENU_ID"];

        if (!isset($menus[$menuId])) {
            $menus[$menuId] = [
                "id"       => $menuId,
                "label"    => $row["MENU_LABEL"],
                "icon"     => trim($row["MENU_ICON"] ?? ""),
                "children" => []
            ];
        }

        $menus[$menuId]["children"][] = [
            "id"    => $row["SUB_MENU_ID"],
            "label" => $row["SUB_MENU_LABEL"],
            "url"   => $row["PROG_URL"]
        ];
    }

    oci_free_statement($stmt);

    $formattedMenus = array_values($menus);

    /*
    |--------------------------------------------------------------------------
    | STORE IN SESSION FOR FUTURE INSTANT RETRIEVAL
    |--------------------------------------------------------------------------
    */
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION["USER_HRMS_MENU"] = $formattedMenus;
    session_write_close();

    header("Cache-Control: private, max-age=300");

    apiResponse(true, "HRMS menu fetched successfully", $formattedMenus);

} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch HRMS menu.", null, 500);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}