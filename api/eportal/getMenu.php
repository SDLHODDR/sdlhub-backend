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
    /* ===========================================
       DAILY TIMESHEET REPORT
       Shown to reporting managers only, whatever their profile: someone who
       is an active employee's REPORT_TO or was picked as reporting manager
       on a timesheet task (same rule as timesheet/tsCommon.php tsIsManager).
    =========================================== */
    $tsReportRoute = 'eportal/timesheet-report';

    $isManager = !empty(multiRec(
        "SELECT 1 AS Y FROM DUAL
          WHERE EXISTS (SELECT 1 FROM EPT_BCS_EMPLOYEE WHERE REPORT_TO = :EMP_CODE AND STATUS = 'A')
             OR EXISTS (SELECT 1 FROM EPT_TIMESHEET_TASK WHERE REPORT_TO = :EMP_CODE)",
        [':EMP_CODE' => $empCode]
    ));

    // Remove it wherever a profile added it. A main menu whose only item was
    // the report goes too (main menus without items, like Dashboard, stay).
    foreach ($menuData as $i => $menuItem) {
        $kept = array_values(array_filter(
            $menuItem["children"],
            fn($child) => $child["route"] !== $tsReportRoute
        ));
        if ($menuItem["children"] && !$kept) {
            unset($menuData[$i]);
            continue;
        }
        $menuData[$i]["children"] = $kept;
    }
    $menuData = array_values($menuData);

    // ...and add it back, under its own main menu, for managers.
    if ($isManager) {
        $reportSub = multiRec(
            "SELECT S.ID, S.LABEL, S.PROG_URL, S.MENU_ID, M.LABEL AS MENU_LABEL, M.MENU_ICON
               FROM EPT_SUBMENU S
               JOIN EPT_MENU M ON M.ID = S.MENU_ID
              WHERE S.PROG_URL = :ROUTE AND S.STATUS = 'A' AND M.STATUS = 'A'",
            [':ROUTE' => $tsReportRoute],
            ["encodeHtml" => false]
        );

        if ($reportSub) {
            $sub = $reportSub[0];
            $child = ["id" => (int)$sub["ID"], "label" => $sub["LABEL"], "route" => $sub["PROG_URL"]];
            $parentIndex = array_search((int)$sub["MENU_ID"], array_column($menuData, "id"), true);

            if ($parentIndex !== false) {
                $menuData[$parentIndex]["children"][] = $child;
            } else {
                // The profile has nothing under this main menu: add the main menu at the end.
                $menuData[] = [
                    "id"       => (int)$sub["MENU_ID"],
                    "label"    => $sub["MENU_LABEL"],
                    "icon"     => $sub["MENU_ICON"],
                    "children" => [$child],
                ];
            }
        }
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