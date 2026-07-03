<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        "status" => false,
        "message" => "Invalid request method"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["emp_code"])) {
    apiResponse(false, "Unauthorized Access", null, 401);
    exit;
}

$empCode = $_SESSION["emp_code"] ?? "";

/*
|--------------------------------------------------------------------------
| READ INPUT
|--------------------------------------------------------------------------
*/

$data = json_decode(file_get_contents("php://input"), true);

$exemptionId = $data["exemption_id"] ?? "";

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (empty($exemptionId)) {
    echo json_encode([
        "status" => false,
        "message" => "Exemption ID is required"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| GET EMPLOYEE ID
|--------------------------------------------------------------------------
*/

$empId = singRec("
    SELECT ID
    FROM EPT_BCS_EMPLOYEE
    WHERE EMP_CODE = '".$empCode."'
")["ID"] ?? null;

if (!$empId) {
    echo json_encode([
        "status" => false,
        "message" => "Employee not found"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFY RECORD BELONGS TO EMPLOYEE
|--------------------------------------------------------------------------
*/

$existingRecord = singRec("
    SELECT ID
    FROM EPT_BCS_ITAX_EXEMPTION
    WHERE ID = '".$exemptionId."'
    AND EMP_ID = '".$empId."'
");

if (!$existingRecord) {
    echo json_encode([
        "status" => false,
        "message" => "Exemption record not found"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE RECORD
|--------------------------------------------------------------------------
*/

$deleteQuery = "
    DELETE FROM EPT_BCS_ITAX_EXEMPTION
    WHERE ID = '".$exemptionId."'
    AND EMP_ID = '".$empId."'
";
startQry();
$result = executeQry($deleteQuery);
endQry('Exemption Deleted');

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

if ($result) {
    echo json_encode([
        "status" => true,
        "message" => "Exemption record deleted successfully"
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "Failed to delete exemption record"
    ]);
}