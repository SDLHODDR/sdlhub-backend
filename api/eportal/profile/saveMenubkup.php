<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__."/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";
require_once __DIR__."/../../config/emp_func.php";

header('Content-Type: application/json');

try {

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        throw new Exception("Unauthorized");
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if(!$data){
        throw new Exception("Invalid request data");
    }

    $profile = intval($data['profile'] ?? 0);
    $menuAccess = $data['menuAccess'] ?? [];
    $submenuAccess = $data['submenuAccess'] ?? [];

    if($profile == 0){
        throw new Exception("Invalid profile");
    }

    /* delete old permissions */
    executeQry("DELETE FROM EPT_PROFILE_MENU WHERE PROFILE_ID=".$profile);

    /* insert new permissions */

    foreach($submenuAccess as $subId => $checked){

        $subId = intval($subId);

        if($checked){

            $menu = singRec("SELECT MENU_ID FROM EPT_SUBMENU WHERE ID=".$subId);

            if(!$menu){
                continue;
            }

            $menuId = intval($menu['MENU_ID']);

          /*  echo  "INSERT INTO EPT_PROFILE_MENU
                (PROFILE_ID, MENU_ID, SUB_MENU_ID)
                VALUES
                ($profile, $menuId, $subId)"; */

            executeQry("
                INSERT INTO EPT_PROFILE_MENU
                (PROFILE_ID, MENU_ID, SUB_MENU_ID)
                VALUES
                ($profile, $menuId, $subId)
            ");

        }
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
