<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {    
    apiResponse(false,"Unauthorized access",null,401);
}

header('Content-Type: application/json');

$profile = $_GET['profile'];

$response = [];

/* -------- MENUS -------- */

$menus = multiRec("SELECT * FROM EPT_MENU 
                   WHERE STATUS='A'
                   ORDER BY SEQ");

$menuData = [];

foreach ($menus as $menu) {

    $submenus = multiRec("SELECT * FROM EPT_SUBMENU
                          WHERE MENU_ID=".$menu['ID']."
                          AND STATUS='A'
                          ORDER BY SEQ");

    $menu['submenus'] = $submenus;

    $menuData[] = $menu;
}

$response['menus'] = $menuData;


/* -------- MENU ACCESS -------- */

$menuAccess = multiRec("SELECT MENU_ID, SUB_MENU_ID
                        FROM EPT_PROFILE_MENU
                        WHERE PROFILE_ID=".$profile);

$menuArr = [];
$subArr = [];

foreach($menuAccess as $m){
    $menuArr[$m['MENU_ID']] = true;
    $subArr[$m['SUB_MENU_ID']] = true;
}

$response['menuAccess'] = $menuArr;
$response['submenuAccess'] = $subArr;


/* -------- TASKS -------- */

$tasks = multiRec("SELECT ID, TASK_DESC
                   FROM EPT_TASK_MASTER
                   ORDER BY ID");

$response['tasks'] = $tasks;


$taskAccess = multiRec("SELECT TASK_ID
                        FROM EPT_PROFILE_TASK
                        WHERE PROFILE_ID=".$profile);

$taskArr = [];

foreach($taskAccess as $t){
    $taskArr[] = $t['TASK_ID'];
}

$response['taskAccess'] = $taskArr;


/* -------- DASHBOARDS -------- */

$dash = multiRec("SELECT ID, DASH_DESC
                  FROM EPT_DASH_MASTER
                  WHERE STATUS = 'A'
                  ORDER BY DASH_DESC");

$response['dashboards'] = $dash;

$dashAccess = multiRec("SELECT DASH_ID
                        FROM EPT_PROFILE_DASH
                        WHERE PROFILE_ID = '$profile'");

$dashArr = [];

foreach($dashAccess as $d){
    $dashArr[] = $d['DASH_ID'];
}

$response['dashAccess'] = $dashArr;


echo json_encode($response);
