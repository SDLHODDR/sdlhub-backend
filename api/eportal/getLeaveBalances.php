<?php
ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/validateCsrf.php";

header('Content-Type: application/json');

if (!isset($_SESSION['emp_code'])) {
    echo json_encode([
        "status" => false,
        "message" => "Not logged in"
    ]);
    exit;
}

$conn = db_eportal();
$empCode   = $_SESSION['emp_code'];
$startDate = date('Y-m-d'); //'2025-01-01'; 

/* ===========================
   APPROVED LEAVE BALANCES
=========================== */
$sql1 = "
    SELECT TRIM(lve_code) lve_code,
           AVAIL_DAYS,
           BAL_DAYS
    FROM ept_bcs_leave_balance
    WHERE emp_code = :emp_code
    AND TO_DATE(:start_date, 'YYYY-MM-DD')
        BETWEEN EFF_DATE AND UPTO_DATE
";

$stid1 = oci_parse($conn, $sql1);
oci_bind_by_name($stid1, ":emp_code", $empCode);
oci_bind_by_name($stid1, ":start_date", $startDate);

if (!oci_execute($stid1)) {
    $e = oci_error($stid1);
    echo json_encode(["status" => false, "error" => $e['message']]);
    exit;
}

$leave_bal_array = [];

while ($row = oci_fetch_assoc($stid1)) {
    $code = $row['LVE_CODE'];

    $leave_bal_array[$code] = [
        "avail"      => (float)$row['AVAIL_DAYS'],
        "bal"        => (float)$row['BAL_DAYS'],
        "unapproved" => 0
    ];
}

oci_free_statement($stid1); 

/* ===========================
   UNAPPROVED LEAVES
=========================== */
$sql2 = "
    SELECT TRIM(lve_code) lve_code,
           SUM(total_days) td
    FROM ept_bcs_emp_leaves_temp
    WHERE emp_code = :emp_code
    AND status = 'T'
    GROUP BY lve_code
";

$stid2 = oci_parse($conn, $sql2);
oci_bind_by_name($stid2, ":emp_code", $empCode);

if (!oci_execute($stid2)) {
    $e = oci_error($stid2);
    echo json_encode(["status" => false, "error" => $e['message']]);
    exit;
}

while ($row = oci_fetch_assoc($stid2)) {
    $code = $row['LVE_CODE'];

    if (!isset($leave_bal_array[$code])) {
        $leave_bal_array[$code] = [
            "avail"      => 0,
            "bal"        => 0,
            "unapproved" => 0
        ];
    }

    $leave_bal_array[$code]['unapproved']= (float)$row['TD'];
    echo json_encode([
        "status" => true,
        "data"   => $leave_bal_array
    ]);
}

oci_free_statement($stid2);

/* ===========================
   LWP (STATIC)
=========================== */
$leave_bal_array['LWP'] = [
    "avail"      => 0,
    "bal"        => 0,
    "unapproved" => 0
];

/* ===========================
   RESPONSE
=========================== */
echo json_encode([
    "status" => true,
    "data"   => $leave_bal_array
]);

ob_end_flush();
