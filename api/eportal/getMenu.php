<?php
ob_start();  

require_once __DIR__ . "/../config/session.php"; 
require_once __DIR__ . "/../cors.php"; 
require_once __DIR__ . "/../config/db.php"; 
require_once __DIR__ . "/../config/validateCsrf.php"; 

$sql___func___con = db_eportal();
require_once __DIR__ . "/../config/functions.php"; 

header('Content-Type: application/json');

if (!isset($_SESSION['emp_code'])) {
    echo json_encode([
        "status" => false,
        "message" => "Not logged in"
    ]);
    exit;
}

$empCode = $_SESSION['emp_code'];

$menuData = [];

/* =========================
   FETCH MAIN MENUS
========================= */

$menus = multirec("
    SELECT *
    FROM EPT_MENU
    WHERE ID IN (
        SELECT DISTINCT MENU_ID
        FROM EPT_PROFILE_MENU
        WHERE PROFILE_ID IN (
            SELECT PROFILE_ID
            FROM EPT_EMP_PROFILE
            WHERE EMP_CODE = '$empCode'
        )
    ) AND STATUS = 'A' 
    ORDER BY SEQ
",['encodeHtml' => false]);

foreach ($menus as $menu) {

    /* =========================
       FETCH SUB MENUS
    ========================= */

    $subMenus = multiRec("
        SELECT *
        FROM EPT_SUBMENU sub
        WHERE ID IN (
            SELECT SUB_MENU_ID
            FROM EPT_PROFILE_MENU
            WHERE PROFILE_ID IN (
                SELECT PROFILE_ID
                FROM EPT_EMP_PROFILE
                WHERE EMP_CODE = '$empCode'
            )
            AND sub.MENU_ID = {$menu['ID']} 
            AND sub.STATUS = 'A'
        )
        ORDER BY SEQ
    ",['encodeHtml' => false]);

    $children = [];

    foreach ($subMenus as $sub) {
        $children[] = [
            "id"    => (int)$sub['ID'],
            "label" => $sub['LABEL'],
            "route" => $sub['PROG_URL']
        ];
    }

    $menuData[] = [
        "id"       => (int)$menu['ID'],
        "label"    => $menu['LABEL'],
        "icon"     => $menu['MENU_ICON'],
        "children" => $children
    ];
}

echo json_encode([
    "status" => true,
    "menu"   => $menuData
]);
