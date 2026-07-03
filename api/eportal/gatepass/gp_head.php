<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php"; 
require_once __DIR__ . "/../../config/db.php"; 
require_once __DIR__ . "/../../config/validateCsrf.php"; 

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php"; 
require_once __DIR__ . "/../../config/emp_func.php"; 
require_once __DIR__ . "/../../config/utils.php"; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

//session_start();
/* ---------------------------
   READ INPUT
---------------------------- */
$data = json_decode(file_get_contents("php://input"), true);

/* -------------------------------------------------
   1. SESSION 
------------------------------------------------- */
if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$conn = db_eportal();
$empCode   = $_SESSION['emp_code'];

/* -------------------------------------------------
   2. READ JSON INPUT
------------------------------------------------- */

$decodeOT = [
    'OD' => 'Out For Full Day' ,
    'OI' => 'In/Out Same Day', 
    'FO' => 'First Half Out',
    'SO' => 'Second Half Out',
    'FW' => 'Field Work',
    'TO' => 'Tour'
];

$decodeStat = [
    'A' => 'Approved',
    'R' => 'Rejected',
    'T' => 'In Process',
    'N' => 'Not Sent for Auth',
    'X' => 'Cancelled'
];

// ============================
// MAPPINGS
// ============================
$outTypeMap = [
    'OI' => 'In/Out same day',
    'OD' => 'Out for full day',
    'FO' => 'First Half Out',
    'SO' => 'Second Half Out',
    'FW' => 'Field Work',
    'TO' => 'Tour'
];

$statusMap = [
    'N' => 'Not Sent for Auth',
    'A' => 'Approved',
    'R' => 'Rejected',
    'T' => 'In Process',
    'X' => 'Cancelled'
];

$statusColorMap = [
    'N' => 'warning',
    'A' => 'success',
    'R' => 'danger',
    'T' => 'info',
    'X' => 'danger'
];


function printDetails($arr) {
    echo '<pre>';
    print_r($arr);
    echo '</pre>';
    exit;
}
