<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/emp_func.php";

header('Content-Type: application/json');

try {

    $empCode = $_SESSION['emp_code'] ?? '';
    
    if(!$empCode){
		apiResponse(false,"Unauthorized access",null,401);
	}

    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        throw new Exception("Invalid request data");
    }

    $profile = intval($data['profile'] ?? 0);
    $submenuAccess = $data['submenuAccess'] ?? [];

    if ($profile == 0) {
        throw new Exception("Invalid profile");
    }

    /* ---------------------------
       GET ONLY CHECKED SUBMENUS
    ---------------------------- */

    $checkedSubmenus = [];

    foreach ($submenuAccess as $subId => $checked) {
        if ($checked) {
            $checkedSubmenus[] = intval($subId);
        }
    }

    if (empty($checkedSubmenus)) {
        throw new Exception("Please select at least one menu.");
    }

    /* ---------------------------
       DELETE OLD PERMISSIONS
    ---------------------------- */

    executeQry("
        DELETE FROM EPT_PROFILE_MENU
        WHERE PROFILE_ID = $profile
    ");
    executeQry("COMMIT");
    /* ---------------------------
       FETCH ALL MENU MAPPINGS AT ONCE
    ---------------------------- */

    $subIds = implode(",", $checkedSubmenus);

    $submenuRows = multiRec("
        SELECT ID, MENU_ID
        FROM EPT_SUBMENU
        WHERE ID IN ($subIds)
    ");

    if (!$submenuRows) {
        throw new Exception("No submenu records found");
    }

    /* ---------------------------
       PREPARE BULK INSERT
    ---------------------------- */

    $insertValues = [];

    foreach ($submenuRows as $row) {
        $subId = intval($row['ID']);
        $menuId = intval($row['MENU_ID']);

        $insertValues[] = "($profile, $menuId, $subId)";
    }

    if (!empty($insertValues)) {

        $insertSql = "INSERT ALL ";

        foreach ($submenuRows as $row) {
            $subId = intval($row['ID']);
            $menuId = intval($row['MENU_ID']);

            $insertSql .= "
                INTO EPT_PROFILE_MENU
                (PROFILE_ID, MENU_ID, SUB_MENU_ID)
                VALUES
                ($profile, $menuId, $subId)
            ";
        }

        $insertSql .= " SELECT * FROM dual";
        executeQry($insertSql);

        // IMPORTANT FOR ORACLE
        executeQry("COMMIT");
    }

    echo json_encode([
        "status" => true,
        "message" => "Menu permissions saved successfully."
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
