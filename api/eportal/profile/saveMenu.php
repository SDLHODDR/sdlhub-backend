<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$conn = db_eportal();
$sql___func___con = $conn;

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/emp_func.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       DATABASE CONNECTION
    =========================================== */

    if (!$conn) {
        apiResponse(false, "Database connection failed.", null, 500);
    }

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       READ REQUEST BODY
    =========================================== */

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        apiResponse(false, "Invalid request data.", null, 400);
    }

    $profile = (int)($input['profile'] ?? 0);
    $submenuAccess = $input['submenuAccess'] ?? [];

    if ($profile <= 0) {
        apiResponse(false, "Invalid profile.", null, 400);
    }

    /* ===========================================
       GET SELECTED SUBMENUS
    =========================================== */

    $checkedSubmenus = [];

    foreach ($submenuAccess as $subId => $checked) {
        if ($checked) {
            $checkedSubmenus[] = (int)$subId;
        }
    }

    if (empty($checkedSubmenus)) {
        apiResponse(false, "Please select at least one menu.", null, 400);
    }

    /* ===========================================
       DELETE EXISTING PERMISSIONS
    =========================================== */

    startQry();

    executeQry("
        DELETE FROM EPT_PROFILE_MENU
        WHERE PROFILE_ID = {$profile}
    ");

    /* ===========================================
       FETCH MENU MAPPINGS
    =========================================== */

    $subIds = implode(",", $checkedSubmenus);

    $submenuRows = multiRec("
        SELECT
            ID,
            MENU_ID
        FROM EPT_SUBMENU
        WHERE ID IN ($subIds)
    ");

    if (empty($submenuRows)) {
        apiResponse(false, "No submenu records found.", null, 404);
    }

    /* ===========================================
       BULK INSERT
    =========================================== */

    $insertSql = "INSERT ALL ";

    foreach ($submenuRows as $row) {

        $menuId = (int)$row['MENU_ID'];
        $subId  = (int)$row['ID'];

        $insertSql .= "
            INTO EPT_PROFILE_MENU
            (
                PROFILE_ID,
                MENU_ID,
                SUB_MENU_ID
            )
            VALUES
            (
                {$profile},
                {$menuId},
                {$subId}
            )
        ";
    }

    $insertSql .= " SELECT * FROM dual";
    executeQry($insertSql);
    endQry();

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(true, "Menu permissions saved successfully.");

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveProfileMenuAccess.php"
    );

    apiResponse(false,"Something went wrong while saving menu permissions.",null,500);

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }

}