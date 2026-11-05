<?php
/*
 * POST { user_task_id }
 * Relieving & Experience Letter (24): issues the outward reference for each
 * letter not yet issued for this separation (legacy ?generateRef=true in
 * relieving_letter.php / experience_letter.php).
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    [, $separation] = exitLoadTask(EXIT_TASK_LETTER, $input['user_task_id'] ?? 0, $empCode);
    $employeeCode = $separation['EMP_CODE'];
    $existing = exitLetterDocs($employeeCode, $separation['ENTER_ON_ISO']);

    $employee = singRec("SELECT ID, CITY FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    $office = exitEmployeeOffice($employeeCode, exitSeparationDate($separation));
    $employeeName = strtoupper(getEmpInfoByCodeHR($employeeCode));
    $issued = [];

    foreach (EXIT_LETTERS as $docId => $letter) {
        if ($existing[$docId]) continue;
        $next = exitNextLetterReference($empCode, $office['DEPT_ID'] ?? '');

        exitExecute("INSERT INTO HR_BCS_OUTWARD_REF
                (ID, OUTWARD_DATE, OUTWARD_TYPE, DOC_REF, DEPT_CODE, OUTWARD_FROM, OUTWARD_TO, PLACE, REMARKS, CHG_ON, CHG_BY, SITE_CODE)
            VALUES (NULL, TRUNC(SYSDATE), 'LTTR', :doc_ref, :dept_code, :outward_from, :outward_to, :place, :remarks, SYSDATE, :chg_by, :site_code)", [
            ':doc_ref' => $next['ref'],
            ':dept_code' => $next['userDeptCode'],
            ':outward_from' => $empCode,
            ':outward_to' => $employeeName,
            ':place' => $employee['CITY'] ?? '',
            ':remarks' => $letter['remark'] . ' - ' . $employeeName,
            ':chg_by' => strtoupper($empCode),
            ':site_code' => strtoupper($next['workSite']),
        ], "Could not register the {$letter['name']} reference.");

        exitExecute("INSERT INTO HR_EMP_DOCS (ID, EMP_ID, EMP_CODE, DOC_ID, DOC_PATH, DOC_REF, CC_TO, CHG_ON, CHG_BY)
            VALUES (NULL, :emp_id, :emp_code, :doc_id, :doc_path, :doc_ref, '', SYSDATE, :chg_by)", [
            ':emp_id' => $employee['ID'] ?? '',
            ':emp_code' => $employeeCode,
            ':doc_id' => (string)$docId,
            ':doc_path' => 'input/emp_docs/' . $letter['file'] . '_' . ($employee['ID'] ?? '') . '.pdf',
            ':doc_ref' => $next['ref'],
            ':chg_by' => $empCode,
        ], "Could not save the {$letter['name']} reference.");

        $issued[$docId] = $next['ref'];
    }

    return [
        $issued ? 'Reference codes generated successfully.' : 'Reference codes were already generated.',
        ['references' => $issued],
    ];
}, 'generateExitLetterReference.php');
