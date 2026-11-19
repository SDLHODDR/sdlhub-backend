<?php
/* GET ?user_task_id= - Print Promotion Letter (51) / Print Increment Letter (52): employee, letter script, payroll check, reference. */
require_once __DIR__ . '/appraisalLetterCommon.php';

employeeAppraisalRunRead(function () use ($empCode) {
    $task = appraisalLoadFollowUpTask($_GET['user_task_id'] ?? 0, $empCode, [51, 52]);
    $code = (string)$task['TRAN_CODE'];
    $employee = appraisalFollowUpEmployee($code);
    $doc = appraisalLetterDocument($task);
    return ['Letter fetched successfully.', [
        'taskId' => (string)$task['TASK_ID'],
        'empCode' => $code,
        'employee' => [
            'code' => $code, 'name' => (string)$employee['NAME'], 'doj' => (string)$employee['DOJ'],
            'designation' => (string)$employee['DESIGNATION'], 'department' => (string)$employee['DEPARTMENT'],
            'division' => (string)$employee['DIVISION'], 'location' => (string)$employee['LOCATION'],
        ],
        'effectiveFrom' => appraisalDisplayDate($task['APPR']['INCR_FROM']),
        'promotionFrom' => appraisalDisplayDate($task['APPR']['PROMO_FROM']),
        'script' => APPRAISAL_LETTERS[(int)$task['TASK_ID']]['script'],
        'salaryPending' => appraisalLetterSalaryPending($task),
        'reference' => $doc['ref'] !== '' ? ['docRef' => $doc['ref'], 'docDate' => $doc['date']] : null,
    ]];
}, 'getAppraisalLetter.php');
