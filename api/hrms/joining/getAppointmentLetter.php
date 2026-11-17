<?php
/*
 * GET ?user_task_id= -> Print Appointment Letter task (44) details: employee,
 * letter templates, salary status and the issued reference (if any).
 */
require_once __DIR__ . '/appointmentLetterCommon.php';

try {
    $task = appointmentLoadTask($_GET['user_task_id'] ?? 0, $empCode);
    $code = $task['TRAN_CODE'];
    $summary = joiningRecord("SELECT * FROM (SELECT GET_DEPT_NAME(A.DEPT_ID) AS DEPARTMENT,
                GET_DESIGN_NAME(A.DESI_ID) AS DESIGNATION, GET_DIVISION_NAME(A.DIVSN_ID) AS DIVISION,
                GET_ORG_LOC_NAME(A.ORG_LOC_ID) AS LOCATION, GET_COMP_NAME(E.COMP_ID) AS COMPANY,
                TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ, E.COM_EMAIL AS EMAIL, E.CELL AS MOBILE
            FROM HR_EMPLOYEE_INFO E
            LEFT JOIN HR_EMP_OFFICE_DET A ON A.EMP_CODE = E.EMP_CODE
            WHERE E.EMP_CODE = :emp_code
            ORDER BY CASE WHEN SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01') THEN 0 ELSE 1 END,
                A.EFFEC_FROM DESC NULLS LAST)
        WHERE ROWNUM = 1", [':emp_code' => $code]);
    $payroll = joiningRecord("SELECT COUNT(*) AS N FROM HR_BCS_EMPLOYEE_AD WHERE EMP_CODE = :emp_code", [':emp_code' => $code]);
    $doc = appointmentLetterDocument($code);
    // CTC Details task (37) still open: no salary has been entered yet.
    $ctcTask = joiningRecord("SELECT COUNT(*) AS N FROM HR_USER_TASKS
        WHERE TASK_ID = 37 AND TRAN_CODE = :emp_code AND STATUS = 'O'", [':emp_code' => $code]);

    $templates = [];
    foreach (APPOINTMENT_LETTER_TEMPLATES as $value => $template) {
        $templates[] = ['value' => $value, 'label' => $template['label'], 'script' => $template['script']];
    }

    apiResponse(true, 'Appointment letter details fetched successfully.', [
        'task' => ['id' => $task['ID'], 'empCode' => $code],
        'employee' => array_merge($summary ?: [], [
            'EMP_CODE' => $code,
            'NAME' => joiningEmployeeName($task['EMPLOYEE']),
        ]),
        'templates' => $templates,
        'salaryPending' => appointmentSalaryPending($code),
        'hasPayroll' => (int)($payroll['N'] ?? 0) > 0,
        'ctcTaskPending' => (int)($ctcTask['N'] ?? 0) > 0,
        'reference' => !empty($doc['DOC_REF']) ? [
            'docRef' => $doc['DOC_REF'],
            'docDate' => $doc['DOC_DATE'],
            'docPath' => $doc['DOC_PATH'],
        ] : null,
    ], 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getAppointmentLetter.php');
    apiResponse(false, 'Unable to load the appointment letter details.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
