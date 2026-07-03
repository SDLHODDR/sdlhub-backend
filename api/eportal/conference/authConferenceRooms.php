<?php

// ini_set('display_errors',1);
// error_reporting(E_ALL);

/* ================= LOAD CORE ================= */

require_once __DIR__."/../../config/session.php";
require_once __DIR__."/../../cors.php";
require_once __DIR__."/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__."/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";
require_once __DIR__."/../../config/emp_func.php";
require_once __DIR__."/workflowEngine.php";

/* ================= SESSION ================= */

$empCode = $_SESSION['emp_code'] ?? '';

if(!$empCode){
    apiResponse(false,"Unauthorized access",null,401);
}

/* ================= INPUT ================= */

$data = json_decode(file_get_contents("php://input"),true);

if($data['authForm']==true)
{
    startQry();
    // =========================
    // AUTH REMARKS
    // =========================
    $authRem = isset($data['AUTH_REMARKS']) ? $data['AUTH_REMARKS'] : "";
    
    taskUpdate('C', $authRem, $data['TASK_ID']);

    // =========================
    // COMMON VALUES
    // =========================
    $roomId = isset($data['room_id']) ? $data['room_id'] : "";
    $id = isset($data['ID']) ? $data['ID'] : "";
    
    // =========================
    // REJECT
    // =========================
    if($data['flag'] == 'R')
    {
        executeQry("
            update ept_conf_room_tran
            set ROOM_ID = '$roomId',
                status = 'R'
            where id = '$id'
        ");

        endQry('Task Rejected');
        
        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "message" => "Record Rejected successfully"
        ]);
    }
    // =========================
    // AUTHORIZE
    // =========================
    else if($data['flag'] == 'A')
    {
        executeQry("
            update ept_conf_room_tran
            set ROOM_ID = '$roomId',
                status = 'A'
            where id = '$id'
        ");

        endQry('Confirm');

        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "message" => "Record Authroized successfully"
        ]);
    }

    
}