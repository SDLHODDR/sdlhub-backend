<?php
ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

$empCode = $_SESSION['emp_code'] ?? '';

if (!$empCode) {
    echo json_encode([
        "status" => false,
        "message" => "Session expired"
    ]);
    exit;
}

$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

$strt_date = "01-Jan-$year";
$end_date  = "31-Dec-$year";

$sqlRaw = multiRec("
    SELECT 
        BH.ID,
        BH.DESCR,
        TO_CHAR(BH.HOL_DATE, 'DAY') AS FULL_DAY,
        TO_CHAR(BH.HOL_DATE,'yyyy-mm-dd') AS HOLDATE,
        BH.HOL_TYPE
    FROM EPT_BCS_HOLIDAYS BH
    WHERE HOL_GRP = (
        SELECT HOL_TBLNO 
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    )
    AND HOL_TYPE IN ('H','O')
    AND HOL_DATE BETWEEN '$strt_date' AND '$end_date'
    ORDER BY HOLDATE
");

/* ========================
   Clean Response Structure
   ======================== */

$holidays = [];

if (!empty($sqlRaw)) {
    foreach ($sqlRaw as $row) {
        $holidays[] = [
            "id"    => $row['ID'],
            "title" => trim($row['DESCR']),
            "date"  => $row['HOLDATE'],        // yyyy-mm-dd
            "day"   => trim($row['FULL_DAY']),
            "type"  => $row['HOL_TYPE']        // H / O
        ];
    }
}

echo json_encode([
    "status" => true,
    "year"   => $year,
    "count"  => count($holidays),
    "data"   => $holidays
]);

exit;
