<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/* ================= LOAD CORE ================= */

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/emp_func.php";

/* WORKFLOW ENGINE */
require_once __DIR__ . "/workflowEngine.php";

/* ================= SESSION VALIDATION ================= */

$empCode = $_SESSION['emp_code'] ?? '';

if (!$empCode) {
    apiResponse(false, "Unauthorized access", null, 401);
}

/* ================= INPUT ================= */

$input = json_decode(file_get_contents("php://input"), true);
$bookingId = $input['bookingId'] ?? '';

if (!$bookingId) {
    apiResponse(false, "Booking ID missing", null, 400);
}

try {

    startQry();

    /* ================= FETCH BOOKING ================= */

    $booking = singRec("
        SELECT
            TO_CHAR(start_time,'dd-Mon-yyyy') MON,
            TO_CHAR(start_time,'hh24:mi') TM,
            book_time,
            BOOK_BY_EMP,
            STATUS
        FROM ept_conf_room_tran
        WHERE id='".$bookingId."'
    ");

    if (!$booking) {
        forceRollback("Booking not found");
    }

    /* ================= WORKFLOW VALIDATION ================= */

    $action = "cancel";

    $workflow = new workflowEngine("conferenceWorkflow");

    if (!$workflow->can($booking['STATUS'], $action)) {
        forceRollback("Booking cannot be cancelled in current state");
    }
    $nextStatus = $workflow->nextStatus($action);

    /* ================= UPDATE BOOKING ================= */

    executeQry("
        UPDATE ept_conf_room_tran
        SET status='".$nextStatus."'
        WHERE id='".$bookingId."'
    ");

    /* ================= VERIFY ================= */

    $verify = singRec("
        SELECT status
        FROM ept_conf_room_tran
        WHERE id='".$bookingId."'
    ");

     if ($verify['STATUS'] != $nextStatus) {
        forceRollback("Cancellation failed");
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

    /* ================= INSERT TASK ================= */

    execQry([
        'type'  => 'insert',
        'table' => 'EPT_USER_TASKS',
        'data'  => [
            'TASK_ID'    => $task['ID'],
            'CREATED_ON' => date('d-M-Y'),
            'CREATED_BY' => $empCode,
            'EXPIRE_ON'  => $task['EXPDT'],
            'STATUS'     => 'O',
            'REMARKS'    => '<span class="text-danger">CANCELED</span>',
            'TRAN_CODE'  => $bookingId,
            'TASK_TYPE'  => 'A',
            'TRAN_DESC'  => $tran_desc,
            'SITE_CODE'  => 'SDLHO'
        ]
    ]);

    /* ================= COMMIT ================= */

    endQry();

    apiResponse(true, "Conference booking cancelled successfully");

} catch (Exception $e) {

    oci_rollback($sql___func___con);

    apiResponse(false, "Transaction failed", null, 500, $e->getMessage());
}
