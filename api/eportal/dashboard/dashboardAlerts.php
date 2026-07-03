<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ ."/../../config/utils.php";

header('Content-Type: application/json');
session_start();

$empCode = $_SESSION['emp_code'] ?? null;
if (!$empCode) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = $_SESSION['emp_code'];

/* RELEASE LOCK */
session_write_close();

/* ---------------------------
   GET EMP ID
---------------------------- */
$empId = singRec("
    SELECT ID 
    FROM EPT_BCS_EMPLOYEE 
    WHERE emp_code = '".$empCode."'
")['ID'] ?? null;

if (!$empId) {
    echo json_encode(['status' => false, 'message' => 'Profile Not Found']);
    exit;
}

/* ---------------------------
   FINANCIAL YEAR
---------------------------- */
$year = date('Y');
$month = date('m');

$fy = ($month >= 4)
    ? $year . '-' . substr($year + 1, -2)
    : ($year - 1) . '-' . substr($year, -2);

/* ---------------------------
   IT DECLARATION CHECK
---------------------------- */
$isDeclared = singRec("
    SELECT emp_id 
    FROM ept_bcs_itax_emp_regime 
    WHERE emp_id = '$empId' 
    AND fy = '$fy'
");

/* ---------------------------
   ALERT BUILD
---------------------------- */
$alerts = [];

// IT not declared
if (empty($isDeclared)) {
    $alerts[] = [
        "type" => "danger",
        "message" => "You have not completed IT Declaration for current financial year.",
        "actionText" => "Click here to proceed",
        "actionUrl" => "/it-declaration"
    ];
}

/* ---------------------------
   CONFERENCE ALERT BUILD
---------------------------- */

$empCodeEscaped = addslashes($empCode);

$query = "
    SELECT 
        r.room_label,
        TO_CHAR(c.start_time, 'HH24:MI') AS start_time,
        TO_CHAR(c.end_time, 'HH24:MI') AS end_time
    FROM ept_conf_room_tran c
    LEFT JOIN ept_conf_rooms r ON r.id = c.room_id
    WHERE (c.chg_by = '$empCodeEscaped' OR c.book_by_emp = '$empCodeEscaped')
    AND c.status IN ('A','N','T')
    AND TRUNC(c.start_time) = TRUNC(SYSDATE)
    AND c.start_time >= SYSDATE
    AND c.start_time <= SYSDATE + (1/24)
    ORDER BY c.start_time ASC
";

$meetings = multiRec($query);

  $meetings = [
    [
      "room_label" => "CR-1",
      "start_time" => "10:30",
      "end_time" => "11:00"
    ],
    [
      "room_label" => "Board Room",
      "start_time" => "11:15",
      "end_time" => "12:00"
    ],
    [
      "room_label" => "Meeting Room A",
      "start_time" => "14:00",
      "end_time" => "14:45"
    ]
  ];

if (!empty($meetings)) {
    $alerts[] = [
        "type" => "info",
        "message" => count($meetings) . " upcoming meeting(s) in next 1 hour",
        "actionText" => "View Schedule",
        "actionUrl" => "/eportal/conference-room"
    ];
}


// Month-end reminder
if ((int)date('d') >= 25) {
    $alerts[] = [
        "type" => "warning",
        "message" => "Kindly check the status of your leave or OD request to ensure approval.",
        "actionText" => null,
        "actionUrl" => null
    ];
}

/* ---------------------------
   RESPONSE
---------------------------- */
echo json_encode([
    "status" => true,
    "data" => [
        "alerts" => $alerts,
        "meetings" => $meetings
    ]
]);
