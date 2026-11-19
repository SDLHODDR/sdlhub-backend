<?php
/*
 * Print Promotion Letter (51) / Print Increment Letter (52) tasks: the letter's HR_EMP_DOCS row
 * (promotion DOC_ID 101, increment 15, as legacy) at input/emp_docs/<Type>_Letter_<employee id>_<appraisal row>.pdf,
 * and the payroll check (the new salary must be confirmed by payroll before the letter is issued).
 */
require_once __DIR__ . '/appraisalTaskCommon.php';
require_once __DIR__ . '/../../config/path.php';

const APPRAISAL_LETTERS = [
    51 => ['docId' => 101, 'file' => 'Promotion_Letter', 'script' => 'promotion_letter.php', 'title' => 'PROMOTION LETTER'],
    52 => ['docId' => 15, 'file' => 'Increment_Letter', 'script' => 'increment_letter.php', 'title' => 'INCREMENT LETTER'],
];

/** The letter document of the task ([docId, path, ref, date]). */
function appraisalLetterDocument(array $task)
{
    $letter = APPRAISAL_LETTERS[(int)$task['TASK_ID']];
    $employee = appraisalRow("SELECT ID FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code", [':code' => (string)$task['TRAN_CODE']]);
    $path = EMP_DOCS_DIR . '/' . $letter['file'] . '_' . $employee['ID'] . '_' . $task['APPR']['ID'] . '.pdf';
    $doc = appraisalRow("SELECT DOC_REF, TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS DOC_DATE FROM HR_EMP_DOCS
        WHERE EMP_CODE = :code AND DOC_ID = :doc AND DOC_PATH = :path ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':code' => (string)$task['TRAN_CODE'], ':doc' => (string)$letter['docId'], ':path' => $path]);
    return ['empId' => (string)$employee['ID'], 'docId' => $letter['docId'], 'path' => $path,
        'ref' => (string)($doc['DOC_REF'] ?? ''), 'date' => (string)($doc['DOC_DATE'] ?? '')];
}

/** True while payroll has not confirmed the salary from the increment date (rows N / T, or none). */
function appraisalLetterSalaryPending(array $task)
{
    $row = appraisalRow("SELECT COUNT(*) AS N, SUM(CASE WHEN CONFIRMED IN ('N', 'T') THEN 1 ELSE 0 END) AS PENDING
        FROM HR_BCS_EMPLOYEE_AD WHERE EMP_CODE = :code AND EFF_DATE = TO_DATE(:dt, 'YYYY-MM-DD')",
        [':code' => (string)$task['TRAN_CODE'], ':dt' => (string)$task['APPR']['INCR_FROM']]);
    return (int)($row['N'] ?? 0) === 0 || (int)($row['PENDING'] ?? 0) > 0;
}
