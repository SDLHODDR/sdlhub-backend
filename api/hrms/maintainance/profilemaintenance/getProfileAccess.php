<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

ob_start();
define('CURRENT_PORTAL', 'hrms');
/* ==========================================================
   CONFIG
========================================================== */

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");


/* ==========================================================
   SESSION
========================================================== */

if (
    !isset($_SESSION["emp_code"]) ||
    empty($_SESSION["emp_code"])
) {
    apiResponse(
        false,
        "Session expired. Please login again.",
        null,
        401
    );
}


/* ==========================================================
   REQUEST METHOD
========================================================== */

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    apiResponse(
        false,
        "Invalid request method.",
        null,
        405
    );
}


/* ==========================================================
   PROFILE ID
========================================================== */

$profileId = trim($_GET["profileId"] ?? "");

if ($profileId === "") {
    apiResponse(
        false,
        "Profile is required.",
        null,
        400
    );
}


/*
 * PROFILE_ID is expected to be numeric.
 * This prevents arbitrary SQL from being injected.
 */

if (!ctype_digit($profileId)) {
    apiResponse(
        false,
        "Invalid profile.",
        null,
        400
    );
}


/* ==========================================================
   CHECK PROFILE
========================================================== */

$profileSql = "
    SELECT
        PROFILE_ID,
        PROFILE_DESC
    FROM HR_PROFILES
    WHERE PROFILE_ID = :PROFILE_ID
";

$profile = singRec(
    $profileSql,
    [
        ":PROFILE_ID" => $profileId
    ]
);

if (empty($profile)) {
    apiResponse(
        false,
        "Profile not found.",
        null,
        404
    );
}


/* ==========================================================
   MENU ACCESS - AVAILABLE MENUS
========================================================== */

$menuSql = "
    SELECT
        ID,
        LABEL,
        SEQ
    FROM HR_MENU
    WHERE STATUS = 'A'
    ORDER BY SEQ
";

$menus = multiRec($menuSql);


/* ==========================================================
   MENU ACCESS - SUB MENUS
========================================================== */

$subMenuSql = "
    SELECT
        ID,
        MENU_ID,
        LABEL,
        SEQ
    FROM HR_MENU_SUB
    WHERE STATUS = 'A'
    ORDER BY MENU_ID, LABEL
";

$subMenus = multiRec($subMenuSql);


/* ==========================================================
   CURRENT PROFILE MENU ACCESS
========================================================== */

$currentMenuSql = "
    SELECT
        pm.ID,
        pm.SUB_MENU_ID
    FROM HR_PROFILE_MENU pm
    INNER JOIN HR_MENU_SUB ms
        ON pm.SUB_MENU_ID = ms.ID
    WHERE pm.PROFILE_ID = :PROFILE_ID
";

$currentMenus = multiRec(
    $currentMenuSql,
    [
        ":PROFILE_ID" => $profileId
    ]
);


/* ==========================================================
   FORMAT MENU ACCESS
========================================================== */

$selectedMenuIds = [];
$selectedSubMenuIds = [];

foreach ($currentMenus as $row) {

    if (
        isset($row["MENU_ID"]) &&
        $row["MENU_ID"] !== ""
    ) {
        $selectedMenuIds[] = (string)$row["MENU_ID"];
    }

    if (
        isset($row["SUB_MENU_ID"]) &&
        $row["SUB_MENU_ID"] !== ""
    ) {
        $selectedSubMenuIds[] = (string)$row["SUB_MENU_ID"];
    }
}


/*
 * Remove duplicates.
 */

$selectedMenuIds = array_values(
    array_unique($selectedMenuIds)
);

$selectedSubMenuIds = array_values(
    array_unique($selectedSubMenuIds)
);


/* ==========================================================
   BUILD MENU TREE
========================================================== */

$menuAccess = [];

foreach ($menus as $menu) {

    $menuId = (string)$menu["ID"];

    $menuItem = [
        "id" => $menuId,
        "label" => $menu["LABEL"] ?? "",
        "checked" => in_array(
            $menuId,
            $selectedMenuIds,
            true
        ),
        "subMenus" => []
    ];

    foreach ($subMenus as $subMenu) {

        if (
            (string)$subMenu["MENU_ID"] !== $menuId
        ) {
            continue;
        }

        $subMenuId = (string)$subMenu["ID"];

        $menuItem["subMenus"][] = [
            "id" => $subMenuId,
            "label" => $subMenu["LABEL"] ?? "",
            "checked" => in_array(
                $subMenuId,
                $selectedSubMenuIds,
                true
            )
        ];
    }

    $menuAccess[] = $menuItem;
}


/* ==========================================================
   COMPANY ACCESS
========================================================== */

$companySql = "
    SELECT
        COMP_ID,
        COMP_DESC
    FROM HR_COMPANY
    ORDER BY COMP_DESC
";

$companies = multiRec($companySql);


/* ==========================================================
   CURRENT COMPANY ACCESS
========================================================== */

$currentCompanySql = "
    SELECT COMP_ID
    FROM HR_PROFILE_COMPANY
    WHERE PROFILE_ID = :PROFILE_ID
";

$currentCompanies = multiRec(
    $currentCompanySql,
    [
        ":PROFILE_ID" => $profileId
    ]
);

$selectedCompanyIds = [];

foreach ($currentCompanies as $row) {

    $selectedCompanyIds[] =
        (string)($row["COMP_ID"] ?? "");
}


/* ==========================================================
   FORMAT COMPANY
========================================================== */

$companyAccess = [];

foreach ($companies as $row) {

    $id = (string)$row["COMP_ID"];

    $companyAccess[] = [
        "id" => $id,
        "label" => $row["COMP_DESC"] ?? "",
        "checked" => in_array(
            $id,
            $selectedCompanyIds,
            true
        )
    ];
}


/* ==========================================================
   DIVISION ACCESS
========================================================== */

$divisionSql = "
    SELECT
        DIVSN_ID,
        DIVSN_DESC
    FROM HR_DIVISIONS
    ORDER BY DIVSN_DESC
";

$divisions = multiRec($divisionSql);


/* ==========================================================
   CURRENT DIVISION ACCESS
========================================================== */

$currentDivisionSql = "
    SELECT DIVISION_ID
    FROM HR_PROFILE_DIVISIONS
    WHERE PROFILE_ID = :PROFILE_ID
";

$currentDivisions = multiRec(
    $currentDivisionSql,
    [
        ":PROFILE_ID" => $profileId
    ]
);

$selectedDivisionIds = [];

foreach ($currentDivisions as $row) {

    $selectedDivisionIds[] =
        (string)($row["DIVISION_ID"] ?? "");
}


/* ==========================================================
   FORMAT DIVISION
========================================================== */

$divisionAccess = [];

foreach ($divisions as $row) {

    $id = (string)$row["DIVSN_ID"];

    $divisionAccess[] = [
        "id" => $id,
        "label" => $row["DIVSN_DESC"] ?? "",
        "checked" => in_array(
            $id,
            $selectedDivisionIds,
            true
        )
    ];
}


/* ==========================================================
   DEPARTMENT ACCESS
========================================================== */

$departmentSql = "
    SELECT
        DEPT_ID,
        DEPT_DESC
    FROM HR_DEPARTMENT
    ORDER BY DEPT_DESC
";

$departments = multiRec($departmentSql);


/* ==========================================================
   CURRENT DEPARTMENT ACCESS
========================================================== */

$currentDepartmentSql = "
    SELECT DEPT_ID
    FROM HR_PROFILE_DEPARTMENT
    WHERE PROFILE_ID = :PROFILE_ID
";

$currentDepartments = multiRec(
    $currentDepartmentSql,
    [
        ":PROFILE_ID" => $profileId
    ]
);

$selectedDepartmentIds = [];

foreach ($currentDepartments as $row) {

    $selectedDepartmentIds[] =
        (string)($row["DEPT_ID"] ?? "");
}


/* ==========================================================
   FORMAT DEPARTMENT
========================================================== */

$departmentAccess = [];

foreach ($departments as $row) {

    $id = (string)$row["DEPT_ID"];

    $departmentAccess[] = [
        "id" => $id,
        "label" => $row["DEPT_DESC"] ?? "",
        "checked" => in_array(
            $id,
            $selectedDepartmentIds,
            true
        )
    ];
}


/* ==========================================================
   TASK ACCESS
========================================================== */

$taskSql = "
    SELECT
        ID,
        TASK_DESC,
        TASK_TYPE,
        DISP_SEQ
    FROM HR_TASK_MASTER
    ORDER BY TASK_TYPE, DISP_SEQ
";

$tasks = multiRec($taskSql);


/* ==========================================================
   CURRENT TASK ACCESS
========================================================== */

$currentTaskSql = "
    SELECT TASK_ID
    FROM HR_PROFILE_TASK
    WHERE PROFILE_ID = :PROFILE_ID
";

$currentTasks = multiRec(
    $currentTaskSql,
    [
        ":PROFILE_ID" => $profileId
    ]
);

$selectedTaskIds = [];

foreach ($currentTasks as $row) {

    $selectedTaskIds[] =
        (string)($row["TASK_ID"] ?? "");
}


/* ==========================================================
   FORMAT TASK
========================================================== */

$taskAccess = [];

foreach ($tasks as $row) {

    $id = (string)$row["ID"];

    $taskAccess[] = [
        "id" => $id,
        "label" => $row["TASK_DESC"] ?? "",
        "type" => $row["TASK_TYPE"] ?? "",
        "checked" => in_array(
            $id,
            $selectedTaskIds,
            true
        )
    ];
}


/* ==========================================================
   DASHBOARD ACCESS
========================================================== */

$dashboardSql = "
    SELECT
        ID,
        DASH_LABEL,
        DASH_DESC
    FROM HR_DASH_MASTER
    ORDER BY DASH_DESC
";

$dashboards = multiRec($dashboardSql);


/* ==========================================================
   CURRENT DASHBOARD ACCESS
========================================================== */

$currentDashboardSql = "
    SELECT DASH_ID
    FROM HR_PROFILE_DASHBOARD
    WHERE PROFILE_ID = :PROFILE_ID
";

$currentDashboards = multiRec(
    $currentDashboardSql,
    [
        ":PROFILE_ID" => $profileId
    ]
);

$selectedDashboardIds = [];

foreach ($currentDashboards as $row) {

    $selectedDashboardIds[] =
        (string)($row["DASH_ID"] ?? "");
}


/* ==========================================================
   FORMAT DASHBOARD
========================================================== */

$dashboardAccess = [];

foreach ($dashboards as $row) {

    $id = (string)$row["ID"];

    $dashboardAccess[] = [
        "id" => $id,
        "label" => $row["DASH_LABEL"] ??
            ($row["DASH_DESC"] ?? ""),
        "checked" => in_array(
            $id,
            $selectedDashboardIds,
            true
        )
    ];
}


/* ==========================================================
   FINAL RESPONSE
========================================================== */

apiResponse(
    true,
    "Profile access fetched successfully.",
    [
        "profile" => [
            "id" => $profile["PROFILE_ID"],
            "description" => $profile["PROFILE_DESC"]
        ],

        "menuAccess" => $menuAccess,

        "companyAccess" => $companyAccess,

        "divisionAccess" => $divisionAccess,

        "departmentAccess" => $departmentAccess,

        "taskAccess" => $taskAccess,

        "dashboardAccess" => $dashboardAccess
    ],
    200
);

/*response:
{
    "status": true,
    "message": "Profile access fetched successfully.",
    "data": {
        "profile": {
            "id": "1",
            "description": "ADMIN"
        },

        "menuAccess": [
            {
                "id": "1",
                "label": "Master Data",
                "checked": true,
                "subMenus": [
                    {
                        "id": "10",
                        "label": "Employee Data",
                        "checked": true
                    },
                    {
                        "id": "11",
                        "label": "Masters Master",
                        "checked": true
                    }
                ]
            }
        ],

        "companyAccess": [
            {
                "id": "1",
                "label": "Company 1",
                "checked": true
            }
        ],

        "divisionAccess": [
            {
                "id": "1",
                "label": "Division 1",
                "checked": false
            }
        ],

        "departmentAccess": [],

        "taskAccess": [],

        "dashboardAccess": []
    }
}*/