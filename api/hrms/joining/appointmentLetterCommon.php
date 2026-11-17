<?php
require_once __DIR__ . '/../../config/path.php';
/*
 * Print Appointment Letter task (44 PRT_APPT_LTR, legacy print_appointment_letter.php).
 * The letter templates are reports/pdfReport/appointment_letter_{ho,field,contract,trainee}.php.
 */
require_once __DIR__ . '/joiningCommon.php';

const JOINING_TASK_APPOINTMENT_LETTER = 44;
const APPOINTMENT_LETTER_DOC_ID = 2;
const APPOINTMENT_LETTER_TEMPLATES = [
    'H' => ['label' => 'HO', 'script' => 'appointment_letter_ho.php'],
    'F' => ['label' => 'Field', 'script' => 'appointment_letter_field.php'],
    'C' => ['label' => 'Contract', 'script' => 'appointment_letter_contract.php'],
    'T' => ['label' => 'Trainee', 'script' => 'appointment_letter_trainee.php'],
];

/** The open task 44 the user may act on, with its employee. */
function appointmentLoadTask($userTaskId, $sessionEmp)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new JoiningRequestException('A valid task is required.', 422);
    }
    loadHrmsAccess($sessionEmp);
    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'",
        [':id' => (string)$userTaskId, ':task_id' => (string)JOINING_TASK_APPOINTMENT_LETTER]);
    if (empty($task['ID'])) {
        throw new JoiningRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new JoiningRequestException('You are not authorized to act on this task.', 403);
    }
    $task['EMPLOYEE'] = joiningLoadEmployee($task['TRAN_CODE']);
    return $task;
}

/** The employee's latest appointment letter document (reference), if issued. */
function appointmentLetterDocument($employeeCode)
{
    return joiningRecord("SELECT * FROM (SELECT ID, DOC_REF, DOC_PATH, TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS DOC_DATE
            FROM HR_EMP_DOCS WHERE EMP_CODE = :emp_code AND DOC_ID = :doc_id ORDER BY ID DESC)
        WHERE ROWNUM = 1", [':emp_code' => (string)$employeeCode, ':doc_id' => (string)APPOINTMENT_LETTER_DOC_ID]);
}

/** Legacy blocks the letter while payroll rows are not yet authorized (CONFIRMED N / T). */
function appointmentSalaryPending($employeeCode)
{
    $row = joiningRecord("SELECT COUNT(*) AS N FROM HR_BCS_EMPLOYEE_AD
        WHERE EMP_CODE = :emp_code AND CONFIRMED IN ('N', 'T')", [':emp_code' => (string)$employeeCode]);
    return (int)($row['N'] ?? 0) > 0;
}
