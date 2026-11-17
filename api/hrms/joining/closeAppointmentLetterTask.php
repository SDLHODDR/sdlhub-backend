<?php
/*
 * POST { user_task_id } -> close the Print Appointment Letter task (44)
 * (legacy recruitment_sv.php ClosePrintTaskAppointment). The letter must be issued first.
 */
require_once __DIR__ . '/appointmentLetterCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = appointmentLoadTask($input['user_task_id'] ?? 0, $empCode);
    $doc = appointmentLetterDocument($task['TRAN_CODE']);
    if (empty($doc['DOC_REF'])) {
        throw new JoiningRequestException('Please generate the reference code before printing the appointment letter.', 422);
    }
    taskUpdateHR('C', '', $task['ID'], null, null);
    return ['Appointment letter task closed.', null];
}, 'closeAppointmentLetterTask.php');
