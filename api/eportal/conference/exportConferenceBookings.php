<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/emp_func.php";
require_once __DIR__ . "/../../config/utils.php";

require '../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['emp_code'])) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

/* ============================================================
   Read IDs sent from React
============================================================ */

$input = json_decode(file_get_contents("php://input"), true);

$ids = $input['ids'] ?? [];

if (!is_array($ids)) {
    $ids = [];
}

$ids = array_map('intval', $ids);

/* ============================================================
   SQL
============================================================ */

$sql = "
SELECT
    c.id,
    r.room_label,
    TO_CHAR(c.start_time,'DD-Mon-YYYY') DT,
    TO_CHAR(c.start_time,'HH24:MI') STARTTIME,
    TO_CHAR(c.end_time,'HH24:MI') ENDTIME,
    c.book_by_emp,
    c.noof_attd,
    c.room_facl1,
    c.room_facl2,
    c.room_facl3,
    c.divsn_id,
    c.remarks,
    hd.divsn_desc
FROM ept_conf_room_tran c
LEFT JOIN ept_conf_rooms r
    ON r.id = c.room_id
LEFT JOIN ept_hr_divisions hd
    ON hd.divsn_id = c.divsn_id
WHERE c.status = 'A'
AND c.start_time >= SYSDATE - 365
";

/* ============================================================
   Apply filter only when IDs are received
============================================================ */

if (!empty($ids)) {

    $placeholders = [];

    foreach ($ids as $i => $id) {
        $placeholders[] = ":id$i";
    }

    $sql .= " AND c.id IN (" . implode(",", $placeholders) . ")";
}

$sql .= " ORDER BY c.start_time DESC";

/* ============================================================
   Execute Query
============================================================ */

$stid = oci_parse($sql___func___con, $sql);

if (!empty($ids)) {

    foreach ($ids as $i => $id) {
        oci_bind_by_name($stid, ":id$i", $ids[$i]);
    }
}

oci_execute($stid);

/* ============================================================
   Excel
============================================================ */

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$headers = [
    "Room",
    "Date",
    "Start Time",
    "End Time",
    "Booked By",
    "Attendees",
    "Facilities",
    "Division",
    "Remarks"
];

$sheet->fromArray([$headers], null, "A1");

$row = 2;

while ($r = oci_fetch_assoc($stid)) {

    $bookByName = ucwords(strtolower(getEmpInfoByCode($r['BOOK_BY_EMP'])));

    $facilities = [];

    if ($r['ROOM_FACL1'] == 'Y') {
        $facilities[] = "Tea / Coffee";
    }

    if ($r['ROOM_FACL2'] == 'Y') {
        $facilities[] = "Breakfast";
    }

    if ($r['ROOM_FACL3'] == 'Y') {
        $facilities[] = "Lunch";
    }

    $facilityText = implode(", ", $facilities);

    $sheet->setCellValue("A{$row}", $r['ROOM_LABEL']);
    $sheet->setCellValue("B{$row}", $r['DT']);
    $sheet->setCellValue("C{$row}", $r['STARTTIME']);
    $sheet->setCellValue("D{$row}", $r['ENDTIME']);
    $sheet->setCellValue("E{$row}", $bookByName);
    $sheet->setCellValue("F{$row}", $r['NOOF_ATTD']);
    $sheet->setCellValue("G{$row}", $facilityText);
    $sheet->setCellValue("H{$row}", $r['DIVSN_DESC']);
    $sheet->setCellValue("I{$row}", $r['REMARKS']);

    $row++;
}

/* ============================================================
   Auto Width
============================================================ */

foreach (range('A', 'I') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

/* ============================================================
   Download
============================================================ */

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Conference_Bookings.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;
