<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {    
    apiResponse(false,"Unauthorized access",null,401);
}

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$name = $data['profileName'];
$desc = $data['description'];

startQry();
$newId=executeQry("INSERT INTO EPT_PROFILES(PROFILE_ID,PROFILE_DESC,PROFILE_DETAIL,STATUS,CHG_ON,CHG_BY)
                    VALUES(
                            null,
                            '".$name."',
                            '".$desc."',
                            'A',
                            SYSDATE,
                            '".$empCode."'
                        )returning  PROFILE_ID into :newId ",'newId');
endQry("Updated Successfully");

echo json_encode(["status"=>"success"]);
?>
