<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/emp_func.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

try {

    // if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    //     exit;
    // }

    /* ===========================================
       SESSION VALIDATION
    =========================================== */
    $empCode = $_SESSION['emp_code'] ?? '';
    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ---------------------------
        READ INPUT
    ---------------------------- */
    $data = json_decode(file_get_contents("php://input"), true);


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

function printDetails($arr) {
    echo '<pre>';
    print_r($arr);
    echo '</pre>';
    exit;
}
?>