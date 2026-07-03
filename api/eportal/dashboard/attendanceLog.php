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

/* RELEASE LOCK */
session_write_close();

if (!$empCode) {   
    apiResponse(false,"Unauthorized Access",null,401);
}

$date = '02-APR-2025';$_GET['date'] ?? date('d-M-Y');

/* -----------------------------
   GET WORKSITE
------------------------------*/
$worksite = singRec("
    SELECT WORK_SITE 
    FROM EPT_BCS_EMPLOYEE 
    WHERE EMP_CODE = '$empCode'
")['WORK_SITE'] ?? null;

/* -----------------------------
   QUERY (FIXED LABELS)
------------------------------*/
if ($worksite === 'SDLPN') {

    $sql = "
        SELECT 
            TO_CHAR(punchdatetime,'HH24:MI:SS') AS time,
            CASE 
                WHEN machineno BETWEEN 21 AND 25 THEN 'Panvel'
                ELSE 'Office'
            END AS location
        FROM ept_bcs_machine_attn_data
        WHERE CARDNO LIKE '%$empCode%'
        AND TRUNC(punchdatetime) = TO_DATE('$date','DD-MON-YYYY')
        ORDER BY punchdatetime
    ";

} else {

    $sql = "SELECT 
                TO_CHAR(A.EVENTDATETIME, 'HH24:MI:SS') AS TIME,
                NVL(M.MACHINE_NAME, 'Office') AS LOCATION

            FROM EPT_BCS_MATRIX_ATTD A

            LEFT JOIN EPT_MACHINE_MASTER M
                ON A.MASTERCONTROLLERID = M.ID

            WHERE A.USERID = '$empCode'
            AND TRUNC(A.EVENTDATETIME) = TO_DATE('$date','DD-MON-YYYY')

            ORDER BY A.EVENTDATETIME";

    /*$sql = "
        SELECT 
            TO_CHAR(eventdatetime,'HH24:MI:SS') AS time,
            CASE mastercontrollerid
                WHEN 1 THEN 'Khetwadi In'
                WHEN 2 THEN 'Khetwadi Out'
                WHEN 3 THEN '5th Floor Recp'
                WHEN 4 THEN '5th Floor Main In'
                WHEN 5 THEN '5th Floor Main Out'
                WHEN 6 THEN '6th Floor Main Out'
                WHEN 7 THEN '6th Floor Main In'
                WHEN 8 THEN 'Tr Room-01 In'
                WHEN 9 THEN 'Tr Room-01 Out'
                WHEN 10 THEN 'Tr Room-02 In'
                WHEN 11 THEN 'Tr Room-02 Out'
                WHEN 12 THEN 'Terrace In'
                WHEN 13 THEN 'Terrace Out'
                WHEN 21 THEN 'Panvel'
                WHEN 22 THEN 'Panvel'
                WHEN 23 THEN 'Panvel'
                WHEN 24 THEN 'Panvel'
                WHEN 25 THEN 'Panvel'
                ELSE 'Office'
            END AS location
        FROM EPT_BCS_MATRIX_ATTD
        WHERE USERID = '$empCode'
        AND TRUNC(eventdatetime) = TO_DATE('$date','DD-MON-YYYY')
        ORDER BY eventdatetime
    ";*/
}

$rows = multiRec($sql);

/* -----------------------------
   HELPERS
------------------------------*/
function formatDuration($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf("%02dh %02dm", $h, $m);
}

/* -----------------------------
   PROCESS DATA
------------------------------*/
$result = [];
$locationSummary = [];

$prevTime = null;
$prevLocation = null;

$firstIn = null;
$lastOut = null;

foreach ($rows as $index => $r) {

    $currentTime = strtotime($r['TIME']);
    $currentLocation = $r['LOCATION'];

    // First IN
    if ($index === 0) {
        $firstIn = $currentTime;
    }

    // Duration calculation (pair-based)
    if ($prevTime !== null) {
        $diff = $currentTime - $prevTime;

        // GROUP LOCATION (remove In/Out)
        $baseLocation = preg_replace('/\s(In|Out)$/', '', $prevLocation);

        if (!isset($locationSummary[$baseLocation])) {
            $locationSummary[$baseLocation] = 0;
        }

        $locationSummary[$baseLocation] += $diff;
    }

    // Timeline
    $result[] = [
        'type' => ($index % 2 === 0) ? 'IN' : 'OUT',
        'time' => $r['TIME'],
        'location' => $currentLocation
    ];

    $prevTime = $currentTime;
    $prevLocation = $currentLocation;

    // Last OUT
    if ($index === count($rows) - 1) {
        $lastOut = $currentTime;
    }
}

/* -----------------------------
   SUMMARY BUILD
------------------------------*/
$locationData = [];

foreach ($locationSummary as $loc => $sec) {
    $locationData[] = [
        'location' => $loc,
        'seconds' => $sec,
        'formatted' => formatDuration($sec)
    ];
}

$totalOfficeTime = ($firstIn && $lastOut)
    ? formatDuration($lastOut - $firstIn)
    : "00h 00m";

/* -----------------------------
   RESPONSE
------------------------------*/
echo json_encode([
    'status' => true,
    'data' => [
        'date' => $date,
        'records' => $result,
        'summary' => [
            'byLocation' => $locationData,
            'totalOfficeTime' => $totalOfficeTime
        ]
    ]
]);
