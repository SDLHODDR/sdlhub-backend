<?php

/*
 * Joining (J) / Exit (E) activity counts per department, for the JD
 * Department Reference tab. Only departments with activities can be
 * referenced, because joining/exit activity tasks are created per
 * referenced department.
 */

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

try {
    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }
    if (!$conn) {
        apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    $rows = multiRec(
        "SELECT DEPT_ID,
                SUM(CASE WHEN ACT_TYPE = 'J' THEN 1 ELSE 0 END) AS JOINING_COUNT,
                SUM(CASE WHEN ACT_TYPE = 'E' THEN 1 ELSE 0 END) AS EXIT_COUNT
         FROM HR_DEPT_JOEX_ACTIVITY
         GROUP BY DEPT_ID"
    );

    apiResponse(true, "Department activity counts fetched successfully.", ['counts' => $rows ?: []], 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch department activity counts.", null, 500);
}
