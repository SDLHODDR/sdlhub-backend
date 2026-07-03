<?php
ob_start();

require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

/* ========================
   Session Validation
   ======================== */

$empCode = $_SESSION['emp_code'] ?? '';

if (!$empCode) {
    echo json_encode([
        "status" => false,
        "message" => "Session expired"
    ]);
    exit;
}

/* ========================
   Get Year Parameter
   ======================== */

$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$year = '2025';
/* ========================
   Fetch Holiday Rules
   ======================== */

$sqlRaw = multiRec("
    SELECT 
        ID,
        DESCR
    FROM EPT_BCS_HOLIDAYS_INSTR
    WHERE HOL_GRP = (
        SELECT HOL_TBLNO 
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    )
    AND HOL_YEAR = '$year'
    ORDER BY ID ASC
");

/* ========================
   Clean Response Structure
   ======================== */

$rules = [];

if (!empty($sqlRaw)) {
    foreach ($sqlRaw as $row) {

        // Remove <html> wrapper if exists (same as old PHP)
        $cleanHtml = preg_replace('/<\/?html>/i', '', $row['DESCR']);

        $rules[] = [
            "id"    => $row['ID'],
            "descr" => $cleanHtml
        ];
    }
}

echo json_encode([
    "status" => true,
    "year"   => $year,
    "count"  => count($rules),
    "data"   => $rules
]);

exit;
