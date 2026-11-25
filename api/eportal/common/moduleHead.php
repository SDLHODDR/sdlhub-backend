<?php
/*
 * Shared start-up for the Outdoor Duty (gatepass), Leave and Ticket Booking
 * APIs, included through gatepass/gp_head.php, leaverequest/lr_head.php and
 * ticketbooking/tb_head.php.
 *
 * Provides: session + CSRF check, DB connection for the sql helpers,
 * $data (JSON body), $empCode (logged-in employee) and the code -> label maps.
 */

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

/* JSON request body */
$data = json_decode(file_get_contents("php://input"), true);

/* Logged-in employee */
if (!isset($_SESSION['emp_code'])) {
    apiResponse(false, "Unauthorized access", null, 401);
}

$empCode = $_SESSION['emp_code'];

/* ---------------------------------------------------------------
   Code -> label maps
   ($decodeOT/$outTypeMap and $decodeStat/$statusMap hold the same
   codes; they differ only in letter case, which pages already show.)
--------------------------------------------------------------- */

$decodeOT = [
    'OD' => 'Out For Full Day',
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
