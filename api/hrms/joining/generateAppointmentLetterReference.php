<?php
/*
 * POST { user_task_id } -> issue the appointment letter reference number
 * (legacy appointment_letter_*.php generateRef): outward register entry and an
 * HR_EMP_DOCS row (DOC_ID 2) at input/emp_docs/Appointment_Letter_<employee id>.pdf.
 * Reference format: MMYY/SITE/DEPT/LTTR/SEQ (as the tenure letters).
 */
require_once __DIR__ . '/appointmentLetterCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = appointmentLoadTask($input['user_task_id'] ?? 0, $empCode);
    $employee = $task['EMPLOYEE'];
    $code = $employee['EMP_CODE'];

    if (appointmentSalaryPending($code)) {
        throw new JoiningRequestException('Please authorize the salary for this employee before issuing the appointment letter.', 422);
    }
    $existing = appointmentLetterDocument($code);
    if (!empty($existing['DOC_REF'])) {
        return ['The appointment letter reference already exists.', ['docRef' => $existing['DOC_REF'], 'docDate' => $existing['DOC_DATE']]];
    }

    $site = joiningRecord("SELECT WORK_SITE, DEPT_CODE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :emp_code", [':emp_code' => $empCode]);
    $workSite = trim((string)($site['WORK_SITE'] ?? ''));
    $userDept = trim((string)($site['DEPT_CODE'] ?? ''));
    if ($workSite === '' || $userDept === '') {
        throw new JoiningRequestException('Your employee account lacks a valid Work Site or Department code in EPP.', 422);
    }
    $dept = joiningRecord("SELECT SHORT_CODE FROM HR_DEPARTMENT WHERE DEPT_CODE = :dept_code", [':dept_code' => $userDept]);
    $period = joiningRecord("SELECT DOC_PREFIX FROM HR_BCS_ACCT_PERIOD
        WHERE CODE = (SELECT ACCT_PRD FROM HR_BCS_PERIOD WHERE TRUNC(SYSDATE) BETWEEN FR_DATE AND TO_DATE)");
    $prefix = trim((string)($period['DOC_PREFIX'] ?? ''));
    if ($prefix === '') {
        throw new JoiningRequestException('No active financial accounting period / prefix found.', 422);
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $workSite) || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        throw new RuntimeException('Invalid reference sequence parameter.');
    }
    // EPP keeps one outward reference sequence per work site and accounting period.
    $sequenceName = "TRAN_OR_{$workSite}_{$prefix}_SEQ";
    $exists = joiningRecord("SELECT COUNT(*) AS N FROM ALL_SEQUENCES WHERE SEQUENCE_OWNER = 'EPPLIVE' AND SEQUENCE_NAME = :name",
        [':name' => strtoupper($sequenceName)]);
    if ((int)($exists['N'] ?? 0) === 0) {
        throw new JoiningRequestException("Outward reference numbering is not set up in EPP for your work site {$workSite} in the current period ({$prefix}). Please ask the EPP team to create sequence EPPLIVE.{$sequenceName}.", 422);
    }
    $sequence = singRec("SELECT EPPLIVE.{$sequenceName}.NEXTVAL AS SEQNO FROM DUAL");
    if (empty($sequence['SEQNO'])) {
        throw new RuntimeException('Could not read the outward reference sequence.');
    }
    $refNo = date('my') . '/' . $workSite . '/' . ($dept['SHORT_CODE'] ?? '') . '/LTTR/' . $sequence['SEQNO'];
    $name = joiningEmployeeName($employee);
    $city = joiningRecord("SELECT CITY FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $code]);

    joiningExecute("INSERT INTO HR_BCS_OUTWARD_REF (ID, OUTWARD_DATE, OUTWARD_TYPE, DOC_REF, DEPT_CODE,
            OUTWARD_FROM, OUTWARD_TO, PLACE, REMARKS, CHG_ON, CHG_BY, SITE_CODE)
        VALUES (NULL, TRUNC(SYSDATE), 'LTTR', :doc_ref, :dept_code, :outward_from, :outward_to, :place,
            :remarks, SYSDATE, :chg_by, :site_code)", [
        ':doc_ref' => $refNo, ':dept_code' => $userDept, ':outward_from' => $empCode, ':outward_to' => $name,
        ':place' => (string)($city['CITY'] ?? ''), ':remarks' => 'APPOINTMENT LETTER - ' . strtoupper($name),
        ':chg_by' => strtoupper($empCode), ':site_code' => strtoupper($workSite),
    ], 'Could not record the outward reference.');

    joiningExecute("INSERT INTO HR_EMP_DOCS (ID, EMP_ID, EMP_CODE, DOC_ID, DOC_PATH, DOC_REF, CC_TO, CHG_ON, CHG_BY)
        VALUES (0, :emp_id, :emp_code, :doc_id, :doc_path, :doc_ref, '', SYSDATE, :chg_by)", [
        ':emp_id' => (string)$employee['ID'], ':emp_code' => $code, ':doc_id' => (string)APPOINTMENT_LETTER_DOC_ID,
        ':doc_path' => EMP_DOCS_DIR . '/Appointment_Letter_' . $employee['ID'] . '.pdf', ':doc_ref' => $refNo, ':chg_by' => $empCode,
    ], 'Could not save the appointment letter document.');

    return ['Reference Code generated successfully.', ['docRef' => $refNo, 'docDate' => date('d-M-Y')]];
}, 'generateAppointmentLetterReference.php');
