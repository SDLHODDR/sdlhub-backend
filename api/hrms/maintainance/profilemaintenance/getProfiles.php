<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

ob_start();

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
   SESSION VALIDATION
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
   GET PROFILES
========================================================== */

$sql = "
    SELECT
        PROFILE_ID,
        PROFILE_DESC
    FROM HR_PROFILES
    ORDER BY PROFILE_DESC
";

$records = multiRec($sql);


/* ==========================================================
   FORMAT DATA
========================================================== */

$data = [];

foreach ($records as $row) {

    $data[] = [
        "id" => $row["PROFILE_ID"] ?? "",
        "description" => $row["PROFILE_DESC"] ?? ""
    ];
}


/* ==========================================================
   RESPONSE
========================================================== */

apiResponse(
    true,
    "Profiles fetched successfully.",
    $data,
    200
);