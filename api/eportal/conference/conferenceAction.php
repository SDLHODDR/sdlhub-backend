<?php

ini_set('display_errors',1);
error_reporting(E_ALL);

//'A'=>'Confirmed','X'=>'Canceled','N'=>'Planned','T'=>'Confirmation Pending'
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

$input = json_decode(file_get_contents("php://input"),true);

$bookingId = $input['bookingId'] ?? '';
$action    = $input['action'] ?? '';

if(!$action){
    apiResponse(false,"Missing action parameter");
}

try{

startQry();

/* ======================================================
   ADD BOOKING
====================================================== */

if($action == "add" || $action == "add_and_send"){

$date      = $input['date'] ?? '';
$fromTime  = $input['fromTime'] ?? '';
$hours     = $input['hours'] ?? 0;
$minutes   = $input['minutes'] ?? 0;
$division  = $input['division'] ?? '';
$reason    = $input['reason'] ?? '';
$attendees = $input['attendees'] ?? 0;
$book_by_emp = $input['bookingBy'] ?? '';

$room_facl1 = !empty($input['tea']) ? '1' : '0';
$room_facl2 = !empty($input['breakfast']) ? '1' : '0';
$room_facl3 = !empty($input['lunch']) ? '1' : '0';

if(!$date || !$fromTime){
    forceRollback("Missing required fields");
}

$totalMinutes = ($hours * 60) + $minutes;

/* ================= GET NEW ID ================= */
//$idRow = singRec("SELECT ept_conf_room_tran_seq.NEXTVAL AS ID FROM dual");
//$bookingId = $idRow['ID'];

/* ================= DEFAULT STATUS ================= */
$status = 'N';

/* ================= IF DIRECT SEND ================= */
if($action == "add_and_send"){
    $workflow = new workflowEngine("conferenceWorkflow");

    if(!$workflow->can('N','send_confirmation')){
        forceRollback("Invalid workflow transition");
    }

    $status = $workflow->nextStatus('send_confirmation');
}

/* ================= INSERT ================= */
// executeQry("
// INSERT INTO ept_conf_room_tran
// (
//     id,
//     ason_date,
//     start_time,
//     end_time,
//     book_time,
//     divsn_id,
//     remarks,
//     noof_attd,
//     room_facl1,
//     room_facl2,
//     room_facl3,
//     book_by_emp,
//     status,
//     chg_on,
//     chg_by
// )
// VALUES
// (
//     '$bookingId',
//     TO_DATE('$date','YYYY-MM-DD'),
//     TO_DATE('$date $fromTime','YYYY-MM-DD HH24:MI'),
//     TO_DATE('$date $fromTime','YYYY-MM-DD HH24:MI') + INTERVAL '$totalMinutes' MINUTE,
//     '$totalMinutes',
//     '$division',
//     '$reason',
//     '$attendees',
//     '$room_facl1',
//     '$room_facl2',
//     '$room_facl3',
//     '$book_by_emp',
//     '$status',
//     SYSDATE,
//     '$empCode'
// )
// ");

$bookingId = executeQry("insert into ept_conf_room_tran  
            (
                id, 
                ason_date, 
                start_time, 
                end_time, 
                book_time, 
                divsn_id, 
                remarks, 
                noof_attd, 
                room_facl1, 
                room_facl2,
                room_facl3, 
                book_by_emp, 
                status, 
                chg_on, 
                chg_by) values 
                            
            ( 
                null, 
                TO_DATE('$date','YYYY-MM-DD'), 
                TO_DATE('$date $fromTime','YYYY-MM-DD HH24:MI'), 
                TO_DATE('$date $fromTime','YYYY-MM-DD HH24:MI') + INTERVAL '$totalMinutes' MINUTE,
                '$totalMinutes',
                '$division',
                '$reason',
                '$attendees', 
                '$room_facl1', 
                '$room_facl2', 
                '$room_facl3', 
                '$book_by_emp', 
                '$status', 
                SYSDATE, 
                '$empCode' 
            ) returning ID into :bookingId", 'bookingId');   

/* ================= TASK CREATION ================= */
if($action == "add_and_send"){

    $task = singRec("
    SELECT t.*, TRUNC(SYSDATE)+(t.EXPIRY_DAYS+0) EXPDT
    FROM EPT_TASK_MASTER t
    WHERE TASK_GRP='conf_room'
    ");

    $tran_desc = "DATE : ".$date.
    ", TIME : ".$fromTime.
    " (".$totalMinutes.") MIN, BOOKED BY : ".
    getEmpInfoByCode($book_by_emp);

    execQry([
    'type'  => 'insert',
    'table' => 'EPT_USER_TASKS',
    'data'  => [
    'TASK_ID'    => $task['ID'],
    'CREATED_ON' => date('d-M-Y'),
    'CREATED_BY' => $empCode,
    'EXPIRE_ON'  => $task['EXPDT'],
    'STATUS'     => 'O',
    'REMARKS'    => '<span class="text-info">SENT FOR CONFIRMATION</span>',
    'TRAN_CODE'  => $bookingId,
    'TASK_TYPE'  => 'A',
    'TRAN_DESC'  => $tran_desc,
    'SITE_CODE'  => 'SDLHO'
    ]
    ]);

}

endQry();

/* ================= RESPONSE ================= */
if($action == "add_and_send"){
    apiResponse(true,"Booking created and sent for confirmation");
}else{
    apiResponse(true,"Booking created successfully");
}

}

/* ======================================================
   OTHER ACTIONS REQUIRE BOOKING ID
====================================================== */

if(!$bookingId){
    forceRollback("Booking ID required");
}

/* ================= FETCH BOOKING ================= */

$booking = singRec("
SELECT
    id,
    status,
    TO_CHAR(start_time,'dd-Mon-yyyy') MON,
    TO_CHAR(start_time,'hh24:mi') TM,
    book_time,
    BOOK_BY_EMP
FROM ept_conf_room_tran
WHERE id='".$bookingId."'
");

if(!$booking){
    forceRollback("Booking not found");
}

/* ================= WORKFLOW ================= */

$workflow = new workflowEngine("conferenceWorkflow");

if(!$workflow->can($booking['STATUS'],$action)){
    forceRollback("Invalid action for current status");
}

$nextStatus = $workflow->nextStatus($action);

/* ================= DELETE ================= */

if($action == "delete"){

executeQry("update ept_conf_room_tran set status='D' where id='" . $bookingId . "' ");
//executeQry("DELETE FROM ept_conf_room_tran WHERE id='".$bookingId."'");

endQry();
apiResponse(true,"Booking deleted successfully");

}

/* ================= UPDATE STATUS ================= */

executeQry("
UPDATE ept_conf_room_tran
SET status='".$nextStatus."'
WHERE id='".$bookingId."'
");

/* ================= EDIT ================= */

if($action == "edit"){

$date      = $input['date'];
$fromTime  = $input['fromTime'];
$hours     = $input['hours'];
$minutes   = $input['minutes'];
$division  = $input['division'];
$reason    = $input['reason'];
$attendees = $input['attendees'];
$book_by_emp = $input['bookingBy'];

$room_facl1 = !empty($input['tea']) ? '1' : '0';
$room_facl2 = !empty($input['breakfast']) ? '1' : '0';
$room_facl3 = !empty($input['lunch']) ? '1' : '0';

$totalMinutes = ($hours*60) + $minutes;

executeQry("
UPDATE ept_conf_room_tran
SET
    ason_date   = TO_DATE('$date','YYYY-MM-DD'),
    start_time  = TO_DATE('$date $fromTime','YYYY-MM-DD HH24:MI'),
    end_time    = TO_DATE('$date $fromTime','YYYY-MM-DD HH24:MI') + INTERVAL '$totalMinutes' MINUTE,
    book_time   = '$totalMinutes',
    divsn_id    = '$division',
    remarks     = '$reason',
    noof_attd   = '$attendees',
    room_facl1  = '$room_facl1',
    room_facl2  = '$room_facl2',
    room_facl3  = '$room_facl3',
    book_by_emp = '$book_by_emp',
    chg_on      = SYSDATE,
    chg_by      = '".$empCode."'
WHERE id = '$bookingId'
");

}

/* ================= TASK MASTER ================= */

$task = singRec("
SELECT
t.*,
TRUNC(SYSDATE)+(t.EXPIRY_DAYS+0) EXPDT
FROM EPT_TASK_MASTER t
WHERE TASK_GRP='conf_room'
");

/* ================= DESCRIPTION ================= */

$tran_desc = "DATE : ".$booking['MON'].
", TIME : ".$booking['TM'].
" (".$booking['BOOK_TIME'].") MIN, BOOKED BY : ".
getEmpInfoByCode($booking['BOOK_BY_EMP']);

/* ================= TASK REMARK ================= */

$remarks = strtoupper($action);

if($action == "cancel"){
$remarks = '<span class="text-danger">CANCELED</span>';
}

if($action == "send_confirmation"){
$remarks = '<span class="text-info">SENT FOR CONFIRMATION</span>';
}

/* ================= INSERT TASK ================= */

if($action == "cancel" || $action == "send_confirmation"){

execQry([
'type'  => 'insert',
'table' => 'EPT_USER_TASKS',
'data'  => [
'TASK_ID'    => $task['ID'],
'CREATED_ON' => date('d-M-Y'),
'CREATED_BY' => $empCode,
'EXPIRE_ON'  => $task['EXPDT'],
'STATUS'     => 'O',
'REMARKS'    => $remarks,
'TRAN_CODE'  => $bookingId,
'TASK_TYPE'  => 'A',
'TRAN_DESC'  => $tran_desc,
'SITE_CODE'  => 'SDLHO'
]
]);

}

endQry();
apiResponse(true,"Action completed successfully");

}
catch(Exception $e){

oci_rollback($sql___func___con);

apiResponse(false,"Transaction failed",null,500,$e->getMessage());

}
