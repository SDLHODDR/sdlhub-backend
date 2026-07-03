<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {
     apiResponse(false,"Unauthorized access",null,401);
}

header('Content-Type: application/json');

try {
    $sql = "SELECT PROFILE_ID, PROFILE_DESC 
        FROM EPT_PROFILES
        WHERE STATUS = 'A'
        ORDER BY PROFILE_DESC";

$result = multiRec($sql);

echo json_encode($result);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => "Database error",
        "error" => $e->getMessage()
    ]);
}
