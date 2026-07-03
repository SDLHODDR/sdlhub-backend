<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['emp_code'])) {
    echo json_encode([
        "status" => false,
        "message" => "Not logged in"
    ]);
    exit;
}

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/validateCsrf.php";

$conn = db_eportal();
$empCode = $_SESSION['emp_code'];

$first_date = date('d-M-y', strtotime('first day of this month'));
$last_date  = date('d-M-y', strtotime('last day of this month'));

$sql = "
SELECT *
FROM EPT_BCS_EMP_LEAVES_TEMP
WHERE EMP_CODE = :emp_code
AND LVE_DATE_FR >= TO_DATE(:from_date,'dd-Mon-yy') 
AND STATUS IN ('T','R')
ORDER BY LVE_DATE_FR DESC, ID DESC
";
//AND LVE_DATE_TO <= TO_DATE(:to_date,'dd-Mon-yy')
$stid = oci_parse($conn, $sql);

oci_bind_by_name($stid, ":emp_code", $empCode);
oci_bind_by_name($stid, ":from_date", $first_date);
oci_bind_by_name($stid, ":to_date", $last_date);

oci_execute($stid);

$data = [];

while (($row = oci_fetch_assoc($stid)) !== false) {
    $data[] = $row;
}

oci_free_statement($stid);

echo json_encode([
    "status" => true,
    "data" => $data
]);
