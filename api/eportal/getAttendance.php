<?php
// Enable errors for debugging
/*ini_set('display_errors', 1);
error_reporting(E_ALL);

// CORS headers (allow your frontend origin or * for dev)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-TOKEN");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection
require_once __DIR__ . "/../config/db.php";
//require_once "C:/xampp/projects/sdlhub/backend/api/config/db.php";
$conn = db_eportal();

if (!$conn) {
    echo json_encode([
        "status" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

// Fetch employees

$query = 'SELECT emp_code, emp_fname, emp_lname FROM ept_bcs_employee';
/*$query = 'SELECT d.descr dept, to_char(to_date(a.prd_code,'yyyymm'),'Mon-yyyy')mon, 
    b.proc_group,a.*,
    trim(to_char(trunc(nvl(a.REG_SEC,0)/60/60/24),'09'))||':'||trim(to_char(trunc(MOD(nvl(a.REG_SEC,0)/60/60,24)),'09')) || ':'||trim(to_char(trunc(MOD(nvl(a.REG_SEC,0),3600)/60),'09')) SEC, 
    nvl(a.REG_CNT,0)REG_CNT , 
    b.emp_fname||' '||b.emp_lname as emp_name
    FROM ept_bcs_attendance_mon a, ept_bcs_employee b,EPT_BCS_PERIOD C,ept_bcs_department d
    WHERE a.prd_code BETWEEN '01-Jan-2026' AND '31-Dec-2026'
    AND a.emp_code = b.emp_code 
    AND b.dept_code = d.dept_code
    AND c.code = a.prd_code
    AND b.emp_code = '02617'
    AND b.date_join <= c.to_date
    ORDER BY b.proc_group, d.descr, b.emp_code, a.prd_code;';
*
    
$stid = oci_parse($conn, $query);
if (!$stid) {
    $e = oci_error($conn);
    echo json_encode([
        "status" => false,
        "message" => "Query failed: " . $e['message']
    ]);
    exit;
}

oci_execute($stid);

$employees = [];
while ($row = oci_fetch_assoc($stid)) {
    $employees[] = array_change_key_case($row, CASE_LOWER);
}

echo json_encode([
    "status" => true,
    "data" => $employees
]);
exit;
*/

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ ."/../config/utils.php";

header('Content-Type: application/json');
session_start();

/* ================= AUTH ================= */
if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}
/* ================= HELPERS ================= */

function timeToSeconds($time) {
    if (!$time) return 0;
    list($h, $m) = explode(':', $time);
    return ($h * 3600) + ($m * 60);
}

function secondsToTime($seconds) {
    if ($seconds <= 0) return "00:00";
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' .
           str_pad($m, 2, '0', STR_PAD_LEFT);
}

function normalizeKeys($arr) {
    return $arr ? array_change_key_case($arr, CASE_UPPER) : [];
}

/* ================= INPUT ================= */

$empCode = $_SESSION['emp_code'];
/*$dateInput = '2025-02-28'; //$_GET['date'] ?? date('Y-m-d');

$currentDate = new DateTime($dateInput);
$year  = $currentDate->format('Y');
$month = $currentDate->format('m');

$startDate = date('Y-M-01', strtotime("$year-$month-01"));
$endDate   = date('Y-M-t', strtotime($startDate));
$daysInMonth = date('t', strtotime($startDate)); */

// Accept month from UI (YYYY-MM)
$monthParam = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m'); // fallback safety
}

$year  = date('Y', strtotime($monthParam));
$month = date('m', strtotime($monthParam));

$startDateYmd = "$year-$month-01";
$endDateYmd   = date('Y-m-t', strtotime($startDateYmd));

$startDate = date('d-M-Y', strtotime($startDateYmd));
$endDate   = date('d-M-Y', strtotime($endDateYmd));

$daysInMonth = date('t', strtotime($startDateYmd));

/* ================= EMP ================= */

$sqlEmp = singRec("
    SELECT emp_code, WORK_SHIFT 
    FROM EPT_bcs_employee 
    WHERE emp_code = '$empCode' AND status='A'
");

$shift = singRec("
    SELECT SHFT_LABEL,START_TIME,END_TIME,GRACE_HRS,FDAY_HRS 
    FROM EPT_BCS_ATTD_SHIFT 
    WHERE SHFT_CODE='".$sqlEmp['WORK_SHIFT']."'
");

/* ================= SUMMARY INIT ================= */

$summary = [
    "totalDays" => (int)$daysInMonth,
    "present" => 0,
    "absent" => 0,
    "leave" => 0,
    "weekoff" => 0,
    "holidays" => 0,
    "lateDays" => 0
];

$data = [];

/* ================= LOOP ================= */

for ($i = 1; $i <= $daysInMonth; $i++) {

    $day = str_pad($i, 2, '0', STR_PAD_LEFT);
   
    $dateYmd = "$year-$month-$day";                  // 2026-03-01
    $dateDb  = date('d-M-Y', strtotime($dateYmd));   // 01-Mar-2026

    /* ===== Attendance ===== */
    $att = normalizeKeys(singRec("
        SELECT 
            to_char(in_time,'HH24:MI') IN_TIME,
            to_char(out_time,'HH24:MI') OUT_TIME,
            to_char(in_time,'DD-Mon-YYYY HH24:MI:SS') IN_DT,
            to_char(out_time,'DD-Mon-YYYY HH24:MI:SS') OUT_DT,
            WORK_HOUR
        FROM EPT_bcs_attd_reg 
        WHERE attd_date = TO_DATE('$dateDb','DD-MON-YYYY') 
        AND emp_code='$empCode'
    "));

    /* ===== Holiday ===== */
    $hol = normalizeKeys(singRec("
        SELECT hol_type, descr 
        FROM EPT_BCS_HOLIDAYS hol 
        JOIN EPT_bcs_employee emp ON hol.hol_grp=emp.hol_tblno
        WHERE emp.emp_code='$empCode' 
        AND hol.hol_date = TO_DATE('$dateDb','DD-MON-YYYY')
        AND hol.status='A'
    "));


    /* ===== Leave ===== */
    $leave = singRec("
        SELECT status, lve_code 
        FROM EPT_bcs_emp_leaves 
        WHERE emp_code='$empCode'
        AND TO_DATE('$dateDb','DD-MON-YYYY') 
            BETWEEN TRUNC(lve_date_fr) AND TRUNC(lve_date_to)
    ");
    $leave = normalizeKeys($leave ?: []);


    /* ===== Tour ===== */
   $tourReg = singRec("
        SELECT id, status 
        FROM EPT_BCS_ATTD_REGULARIZE_TOUR 
        WHERE emp_code='$empCode' 
        AND TO_DATE('$dateDb','DD-MON-YYYY') 
            BETWEEN TRUNC(from_date) AND TRUNC(to_date)
    ");

    $tourReg = normalizeKeys($tourReg ?: []);

    /* ===== Work Hr (always reliable from IN/OUT) ===== */
    $workHr = '00:00';

    if (!empty($att['IN_DT']) && !empty($att['OUT_DT'])) {

        $in  = DateTime::createFromFormat('d-M-Y H:i:s', $att['IN_DT']);
        $out = DateTime::createFromFormat('d-M-Y H:i:s', $att['OUT_DT']);

        if ($in && $out) {
            $diff = $in->diff($out);

            $hours = $diff->h + ($diff->days * 24);
            $minutes = $diff->i;

            $workHr = str_pad($hours, 2, '0', STR_PAD_LEFT) . ':' .
                    str_pad($minutes, 2, '0', STR_PAD_LEFT);
        }
    }

    /* ===== Late ===== */
    $late = null;
    if (!empty($att['IN_TIME']) && !empty($shift['START_TIME'])) {

        $shiftStart = strtotime($dateYmd . ' ' . $shift['START_TIME']);
        $inTime     = strtotime($dateYmd . ' ' . $att['IN_TIME']);

        list($gH, $gM) = explode(":", $shift['GRACE_HRS']);
        $graceEnd = strtotime("+{$gH} hours +{$gM} minutes", $shiftStart);

        if ($inTime > $graceEnd) {
            $late = secondsToTime($inTime - $shiftStart);
            $summary['lateDays']++;
        }
    }

    /* ===== Early ===== */
    $early = null;
    if (!empty($att['OUT_TIME'])) {

        $outTime = strtotime($dateYmd . ' ' . $att['OUT_TIME']);
        $shiftEnd = strtotime($dateYmd . ' ' . $shift['END_TIME']);

        if ($outTime < $shiftEnd) {
            $early = secondsToTime($shiftEnd - $outTime);
        }
    }

    /* ===== Extra ===== */
    $extra = null;
    $workSec = timeToSeconds($workHr);
    $fullSec = timeToSeconds($shift['FDAY_HRS']);

    if ($workSec > $fullSec) {
        $extra = secondsToTime($workSec - $fullSec);
    }

    /* ===== Status ===== */
    $status = 'A';
    $desc   = 'Absent';

    if (!empty($hol['HOL_TYPE'])) {
        $status = 'H';
        $desc = $hol['DESCR'];
        $summary['holidays']++;
    }
    elseif (!empty($leave['STATUS']) && $leave['STATUS'] == 'A') {
        $status = 'L';
        $desc = $leave['LVE_CODE'];
        $summary['leave']++;
    }
    elseif (date('D', strtotime($dateYmd)) == 'Sun') {
        $status = 'W';
        $desc = 'Sunday';
        $summary['weekoff']++;
    }
    elseif (!empty($att['IN_TIME'])) {
        $status = 'P';
        $desc = 'Present';
        $summary['present']++;
    } else {
        $summary['absent']++;
    }

    /* ===== Tour ===== */
    $tour = '';
    if (!empty($tourReg['ID'])) {
        $tour = ($tourReg['STATUS'] == 'A') ? 'T' : 'R';
    }

    /* ===== Desk ===== */
    $onDesk = normalizeKeys(singRec("
        SELECT to_char(to_date(sum(time_diff_sec),'sssss'),'hh24:mi') tm 
        FROM EPT_bcs_attd_daily 
        WHERE machine_no in (5,6,11) 
        AND ason_date = TO_DATE('$dateDb','DD-MON-YYYY')
        AND emp_code='$empCode'
    "));

    $offDesk = normalizeKeys(singRec("
        SELECT to_char(to_date(sum(time_diff_sec),'sssss'),'hh24:mi') tm 
        FROM EPT_bcs_attd_daily 
        WHERE machine_no in (4,7,8,12) 
        AND ason_date = TO_DATE('$dateDb','DD-MON-YYYY')
        AND emp_code='$empCode'
    "));

    $terrace = normalizeKeys(singRec("
        SELECT to_char(to_date(sum(time_diff_sec),'sssss'),'hh24:mi') tm 
        FROM EPT_bcs_attd_daily 
        WHERE machine_no in (13) 
        AND ason_date = TO_DATE('$dateDb','DD-MON-YYYY')
        AND emp_code='$empCode'
    "));

    /* ===== Row ===== */
    $data[] = [
        "date" => $i,
        "in" => $att['IN_TIME'] ?? '-',
        "out" => $att['OUT_TIME'] ?? '-',
        "late" => $late,
        "early" => $early,
        "status" => $status,
        "tour" => $tour,
        "workHr" => $workHr,
        "onDesk" => $onDesk['TM'] ?? '00:00',
        "offDesk" => $offDesk['TM'] ?? '00:00',
        "terrace" => $terrace['TM'] ?? '00:00',
        "extra" => $extra,
        "description" => $desc
    ];
}

/* ================= RESPONSE ================= */

echo json_encode([
    "status" => true,
    "meta" => [
        "startDate" => $startDate,
        "endDate" => $endDate,
        "shift" => $shift,
        "summary" => $summary  
    ],
    "data" => $data
]);
