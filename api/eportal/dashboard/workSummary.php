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

/* RELEASE LOCK */
session_write_close();

/* -------------------------------
   PERIOD RANGE (Jan → Current)
--------------------------------*/
$fromPrd = '202501'; // date('Y') . "01";
$toPrd   = '202504'; // date('Ym');

/* -------------------------------
   MAIN QUERY
--------------------------------*/
$sql = "
SELECT 
    NVL(SUM(a.WORK_DAYS),0)      AS WORK_DAYS,
    NVL(SUM(a.WOFF_DAYS),0)      AS WEEKOFF_DAYS,
    NVL(SUM(a.HOLI_DAYS),0)      AS HOLIDAY_DAYS,
    NVL(SUM(a.LVES_TAKEN),0)     AS LEAVE_DAYS,
    NVL(SUM(
        (TO_NUMBER(TO_CHAR(LAST_DAY(TO_DATE(a.prd_code,'YYYYMM')), 'DD')))
        - a.PAID_DAYS
    ),0) AS LWP_DAYS
FROM ept_bcs_attendance_mon a
JOIN ept_bcs_employee b ON a.emp_code = b.emp_code
JOIN ept_bcs_period c   ON c.code = a.prd_code
WHERE 
    a.emp_code = '$empCode'
    AND a.prd_code BETWEEN '$fromPrd' AND '$toPrd'
    AND b.date_join <= c.to_date
";

$row = singRec($sql);

/* -------------------------------
   RESPONSE FORMAT (amCharts ready)
--------------------------------*/
$data = [
    ["category" => "Work",      "value" => $row['WORK_DAYS']],
    ["category" => "Week Off",  "value" => $row['WEEKOFF_DAYS']],
    ["category" => "Holidays",  "value" => $row['HOLIDAY_DAYS']],
    ["category" => "Leave",     "value" => $row['LEAVE_DAYS']],
];

if ((int)$row['LWP_DAYS'] > 0) {
    $data[] = ["category" => "LWP", "value" => $row['LWP_DAYS']];
}

echo json_encode([
    "status" => true,
    "data"   => $data
]);
