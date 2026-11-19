<?php
/*
 * POST { user_task_id, action: 'reference'|'close' } - Print Promotion Letter (51) / Print Increment Letter (52).
 *   reference -> outward register entry and the letter's HR_EMP_DOCS row (legacy generateRef), once payroll has
 *                confirmed the salary. Format MMYY/SITE/DEPT/LTTR/SEQ from EPPLIVE.TRAN_OR_<site>_<prefix>_SEQ.
 *   close     -> closes the task (legacy ClosePrintTaskAppointment); the reference must exist.
 */
require_once __DIR__ . '/appraisalLetterCommon.php';

$input = appraisalInput();

employeeAppraisalRunAction(function () use ($input, $empCode) {
    $task = appraisalLoadFollowUpTask($input['user_task_id'] ?? 0, $empCode, [51, 52]);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $doc = appraisalLetterDocument($task);
    $letter = APPRAISAL_LETTERS[(int)$task['TASK_ID']];

    if ($action === 'close') {
        if ($doc['ref'] === '') {
            throw new AppraisalRequestException('Please generate the reference code before printing the letter.', 422);
        }
        taskUpdateHR('C', '', $task['ID'], null, null);
        return ['Letter task closed.', null];
    }
    if ($action !== 'reference') {
        throw new AppraisalRequestException('Invalid action.', 422);
    }
    if ($doc['ref'] !== '') {
        return ['The reference already exists.', ['docRef' => $doc['ref'], 'docDate' => $doc['date']]];
    }
    if (appraisalLetterSalaryPending($task)) {
        throw new AppraisalRequestException('The revised salary has not been confirmed by payroll yet. Please get it confirmed before issuing the letter.', 422);
    }

    $site = appraisalRow("SELECT WORK_SITE, DEPT_CODE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :code", [':code' => $empCode]);
    $workSite = trim((string)($site['WORK_SITE'] ?? ''));
    $userDept = trim((string)($site['DEPT_CODE'] ?? ''));
    if ($workSite === '' || $userDept === '') {
        throw new AppraisalRequestException('Your employee account lacks a valid Work Site or Department code in EPP.', 422);
    }
    $dept = appraisalRow("SELECT SHORT_CODE FROM HR_DEPARTMENT WHERE DEPT_CODE = :dept", [':dept' => $userDept]);
    $period = appraisalRow("SELECT DOC_PREFIX FROM HR_BCS_ACCT_PERIOD
        WHERE CODE = (SELECT ACCT_PRD FROM HR_BCS_PERIOD WHERE TRUNC(SYSDATE) BETWEEN FR_DATE AND TO_DATE)");
    $prefix = trim((string)($period['DOC_PREFIX'] ?? ''));
    if ($prefix === '' || !preg_match('/^[A-Za-z0-9_]+$/', $workSite . $prefix)) {
        throw new AppraisalRequestException('No active financial accounting period / prefix found.', 422);
    }
    $sequenceName = "TRAN_OR_{$workSite}_{$prefix}_SEQ";
    $exists = appraisalRow("SELECT COUNT(*) AS N FROM ALL_SEQUENCES WHERE SEQUENCE_OWNER = 'EPPLIVE' AND SEQUENCE_NAME = :name",
        [':name' => strtoupper($sequenceName)]);
    if ((int)($exists['N'] ?? 0) === 0) {
        throw new AppraisalRequestException("Outward reference numbering is not set up in EPP for your work site {$workSite} in the current period ({$prefix}). Please ask the EPP team to create sequence EPPLIVE.{$sequenceName}.", 422);
    }
    $sequence = appraisalRow("SELECT EPPLIVE.{$sequenceName}.NEXTVAL AS SEQNO FROM DUAL");
    $refNo = date('my') . '/' . $workSite . '/' . ($dept['SHORT_CODE'] ?? '') . '/LTTR/' . $sequence['SEQNO'];

    $code = (string)$task['TRAN_CODE'];
    $employee = appraisalRow("SELECT CITY, INITCAP(TRIM(FNAME || ' ' || LNAME)) AS NAME FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code", [':code' => $code]);
    appraisalExecute("INSERT INTO HR_BCS_OUTWARD_REF (ID, OUTWARD_DATE, OUTWARD_TYPE, DOC_REF, DEPT_CODE, OUTWARD_FROM, OUTWARD_TO, PLACE,
            REMARKS, CHG_ON, CHG_BY, SITE_CODE)
        VALUES (NULL, TRUNC(SYSDATE), 'LTTR', :ref, :dept, :from_emp, :to_name, :place, :remarks, SYSDATE, :chg_by, :site)", [
        ':ref' => $refNo, ':dept' => $userDept, ':from_emp' => $empCode, ':to_name' => (string)($employee['NAME'] ?? $code),
        ':place' => (string)($employee['CITY'] ?? ''), ':remarks' => $letter['title'] . ' - ' . strtoupper((string)($employee['NAME'] ?? $code)),
        ':chg_by' => strtoupper($empCode), ':site' => strtoupper($workSite),
    ], 'Could not record the outward reference.');
    appraisalExecute("INSERT INTO HR_EMP_DOCS (ID, EMP_ID, EMP_CODE, DOC_ID, DOC_PATH, DOC_REF, CC_TO, CHG_ON, CHG_BY)
        VALUES (0, :emp_id, :code, :doc_id, :path, :ref, '', SYSDATE, :chg_by)", [
        ':emp_id' => $doc['empId'], ':code' => $code, ':doc_id' => (string)$doc['docId'], ':path' => $doc['path'],
        ':ref' => $refNo, ':chg_by' => $empCode,
    ], 'Could not save the letter document.');
    return ['Reference Code generated successfully.', ['docRef' => $refNo, 'docDate' => date('d-M-Y')]];
}, 'processAppraisalLetter.php');
