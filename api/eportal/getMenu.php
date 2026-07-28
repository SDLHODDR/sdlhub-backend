<?php

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/validateCsrf.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

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
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       FETCH MAIN MENUS
    =========================================== */

    $menuData = [];

    $menus = multiRec(
        "
        SELECT *
        FROM EPT_MENU
        WHERE ID IN
        (
            SELECT DISTINCT MENU_ID
            FROM EPT_PROFILE_MENU
            WHERE PROFILE_ID IN
            (
                SELECT PROFILE_ID
                FROM EPT_EMP_PROFILE
                WHERE EMP_CODE = '{$empCode}'
            )
        )
        AND STATUS = 'A'
        ORDER BY SEQ
        ",
        [
            "encodeHtml" => false
        ]
    );

    foreach ($menus as $menu) {

        /* ===========================================
           FETCH SUB MENUS
        =========================================== */

        $subMenus = multiRec(
            "
            SELECT *
            FROM EPT_SUBMENU SUB
            WHERE ID IN
            (
                SELECT SUB_MENU_ID
                FROM EPT_PROFILE_MENU
                WHERE PROFILE_ID IN
                (
                    SELECT PROFILE_ID
                    FROM EPT_EMP_PROFILE
                    WHERE EMP_CODE = '{$empCode}'
                )
            )
            AND SUB.MENU_ID = {$menu['ID']}
            AND SUB.STATUS = 'A'
            ORDER BY SEQ
            ",
            [
                "encodeHtml" => false
            ]
        );

        $children = [];

        foreach ($subMenus as $sub) {

            $children[] = [
                "id"    => (int)$sub["ID"],
                "label" => $sub["LABEL"],
                "route" => $sub["PROG_URL"]
            ];
        }

        $menuData[] = [
            "id"       => (int)$menu["ID"],
            "label"    => $menu["LABEL"],
            "icon"     => $menu["MENU_ICON"],
            "children" => $children
        ];
    }
    apiResponse(true, "Menu loaded successfully.", $menuData);

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getMenu.php"
    );

    apiResponse(false, "Unable to load menu.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}