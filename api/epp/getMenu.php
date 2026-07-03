<?php

ob_start();  

require_once __DIR__ . "/../config/session.php"; 
require_once __DIR__ . "/../cors.php"; 
require_once __DIR__ . "/../config/db.php"; 
require_once __DIR__ . "/../config/validateCsrf.php"; 

$con = db_epplive();
require_once __DIR__ . "/../config/functions.php"; 

header('Content-Type: application/json');

if (!isset($_SESSION['emp_code'])) {
    echo json_encode([
        "status" => false,
        "message" => "Not logged in"
    ]);
    exit;
}

$empCode = trim($_SESSION['emp_code']);

$sql = "SELECT
    d.id           module_id,
    d.mod_name,
    d.proc         module_proc,
    d.mod_color,
    d.seq          module_seq,

    e.id           menu_id,
    e.mnu_name,
    e.mnu_type,
    e.proc         menu_proc,
    e.seq          menu_seq,

    f.id           submenu_id,
    f.smnu_name,
    f.proc         submenu_proc,
    NVL(f.rept_grp,'') rept_grp,
    f.seq          submenu_seq

FROM bcs_user_rights b
JOIN bcs_profile_menu c
    ON b.profile_id = c.profile_id

JOIN bcs_modules d
    ON c.module_id = d.id

JOIN bcs_menu e
    ON e.id = c.menu_id
   AND e.mod_id = d.id

JOIN bcs_submenu f
    ON f.id = c.sub_menu_id
   AND f.mnu_id = e.id

WHERE TRIM(b.user_code) = :empCode
AND b.status = 'A'
AND c.status = 'A'
AND d.status = 'A'
AND e.status = 'A'
AND f.status = 'A'

ORDER BY
    d.seq,
    e.seq,
    NVL(f.rept_grp,'ZZZZ'),
    f.seq";

//echo "sql: ".$sql."---". $empCode; exit;

$stmt = oci_parse($con, $sql);
oci_bind_by_name($stmt, ":empCode", $empCode);
oci_execute($stmt);

$modules = [];

while ($row = oci_fetch_assoc($stmt))
{  
    $moduleId = $row['MODULE_ID'];
    $menuId   = $row['MENU_ID'];

    /*
    |--------------------------------------------------------------------------
    | Module
    |--------------------------------------------------------------------------
    */

    if (!isset($modules[$moduleId]))
    {
        $modules[$moduleId] = [
            'id' => $moduleId,
            'name' => $row['MOD_NAME'],
            'proc' => $row['MODULE_PROC'],
            'color' => $row['MOD_COLOR'],
            'menus' => []
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    */
    if (!isset($modules[$moduleId]['menus'][$menuId]))
    {
        $modules[$moduleId]['menus'][$menuId] = [
            'id' => $menuId,
            'name' => $row['MNU_NAME'],
            'type' => $row['MNU_TYPE'],
            'proc' => $row['MENU_PROC'],
            'submenus' => []
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Type F
    |--------------------------------------------------------------------------
    */
    if ($row['MNU_TYPE'] == 'F')
    {
        $modules[$moduleId]['menus'][$menuId]['submenus'][] = [
            'id' => $row['SUBMENU_ID'],
            'name' => $row['SMNU_NAME'],
            'proc' => $row['SUBMENU_PROC']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Type R
    |--------------------------------------------------------------------------
    */
    else
    {
        $group = trim($row['REPT_GRP']);

        if ($group == '')
        {
            $group = 'Other';
        }

        if (!isset($modules[$moduleId]['menus'][$menuId]['submenus'][$group]))
        {
            $modules[$moduleId]['menus'][$menuId]['submenus'][$group] = [
                'group' => $group,
                'items' => []
            ];
        }

        $modules[$moduleId]['menus'][$menuId]['submenus'][$group]['items'][] = [
            'id' => $row['SUBMENU_ID'],
            'name' => $row['SMNU_NAME'],
            'proc' => $row['SUBMENU_PROC']
        ];
    }
}

echo json_encode([
    'status' => true,
    'data' => array_values($modules)
]);

?>