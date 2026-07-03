<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {    
    apiResponse(false,"Unauthorized access",null,401);
}

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$name = $data['profile_name'];
$desc = $data['description'];


executeQuery("INSERT INTO EPT_PROFILES
              (PROFILE_DESC, DESCRIPTION, STATUS)
              VALUES
              ('$name','$desc','A')");

echo json_encode(["status"=>"success"]);
?>
