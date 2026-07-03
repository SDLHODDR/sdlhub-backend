<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__."/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";
require_once __DIR__."/../../config/emp_func.php";

header('Content-Type: application/json');

try {

    /* -------- SESSION VALIDATION -------- */

    $empCode = $_SESSION['emp_code'] ?? '';
  
    if(!$empCode){
		apiResponse(false,"Unauthorized access",null,401);
	}

    /* -------- READ INPUT -------- */

    $data = json_decode(file_get_contents("php://input"), true);

    if(!$data){
        throw new Exception("Invalid request data");
    }

    $profile = intval($data['profile'] ?? 0);
    $tasks = $data['tasks'] ?? [];

    if($profile == 0){
        throw new Exception("Invalid profile");
    }

    /* -------- DELETE OLD TASK ACCESS -------- */

    executeQry("DELETE FROM EPT_PROFILE_TASK WHERE PROFILE_ID=".$profile);
    executeQry("COMMIT");

    /* -------- INSERT NEW TASK ACCESS -------- */

    if(!empty($tasks)){

        foreach($tasks as $task){

            $taskId = intval($task);

            executeQry("
                INSERT INTO EPT_PROFILE_TASK
                (PROFILE_ID, TASK_ID)
                VALUES
                ($profile, $taskId)
            ");
            executeQry("COMMIT");   
        }

    }

    /* -------- SUCCESS RESPONSE -------- */

    echo json_encode([
        "status" => true,
        "message" => "Task access saved successfully"
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);

}
