<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__."/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";
require_once __DIR__."/../../config/emp_func.php";

header('Content-Type: application/json');

try {

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false,"Unauthorized access",null,401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
   
    if(!$data){
        throw new Exception("Invalid request data");
    }

 
    if(!is_array($data)){
        throw new Exception("Invalid payload format");
    }

    foreach($data as $row){

        $emp = trim($row['empCode'] ?? '');
        $profiles = $row['profiles'] ?? [];

        if($emp == ''){
            continue;
        }

        startQry();
        /* DELETE EXISTING PROFILES */

        executeQry("
            DELETE FROM EPT_EMP_PROFILE 
            WHERE EMP_CODE = '".$emp."'
        "); 

        /* INSERT NEW PROFILES */

        if(!empty($profiles)){

            foreach($profiles as $pid){
                $pid = intval($pid);
                if($pid == 0){
                    continue;
                }
                executeQry("
                    INSERT INTO EPT_EMP_PROFILE
                    (EMP_CODE, PROFILE_ID)
                    VALUES
                    ('".$emp."', ".$pid.")
                ");
            }
        }
    }
    endQry();
    echo json_encode([
        "status" => true,
        "message" => "Employee profiles saved successfully."
    ]);
    exit;

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
    exit;

}
