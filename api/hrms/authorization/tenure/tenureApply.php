<?php
/*
|--------------------------------------------------------------------------
| TENURE CHANGE - APPLY
|--------------------------------------------------------------------------
| Writes an approved tenure change to the employee's records. Used by both
| approval paths: assessment submitted with an unchanged CTC
| (closeConfirmation.php) and final CTC approval (tenureCtcChange.php).
| Mirrors legacy authTenureCTC in tenure_change_sv.php.
|
| These functions run inside the caller's transaction on the HRMS connection,
| and throw on failure so the caller rolls everything back.
*/

/**
 * Closes the current tenure period the day before the effective date, opens
 * the new period, and sets the EPP employee type and confirmation date
 * (and CONFIRMED = 'Y' for Permanent). Refuses when the employee is missing
 * from the EPP employee master.
 */
function tenureApplyChange($tenureChangeId, $employeeCode, $changedBy)
{
    $employeeCode = trim((string)$employeeCode);
    $changedBy = trim((string)$changedBy);

    $change = singRec("SELECT ETYPE_ID, ETYPE_PERIOD,
            TO_CHAR(NVL(CTC_EFF_FROM, EFF_FROM), 'YYYY-MM-DD') AS EFF_FROM_ISO
        FROM HR_EMP_TENURE_CHANGE
        WHERE ID = :tenure_id AND EMP_CODE = :emp_code", [
        ':tenure_id' => (string)$tenureChangeId,
        ':emp_code' => $employeeCode,
    ]);
    $newType = trim((string)($change['ETYPE_ID'] ?? ''));
    $period = (int)trim((string)($change['ETYPE_PERIOD'] ?? ''));
    $effectiveDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($change['EFF_FROM_ISO'] ?? ''));
    if ($newType === '' || !$effectiveDate) {
        throw new RuntimeException('The tenure change has no employee type or effective date.');
    }

    // The EPP employee master (HR_BCS_EMPLOYEE = EPPLIVE.BCS_EMPLOYEE) must hold the
    // employee, otherwise its type / confirmation update would silently change nothing.
    $eppEmployee = singRec("SELECT EMP_CODE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :emp_code",
        [':emp_code' => $employeeCode]);
    if (empty($eppEmployee['EMP_CODE'])) {
        throw new RuntimeException("Employee {$employeeCode} was not found in the EPP employee master, so the tenure change cannot be approved. Please contact IT.");
    }

    $current = singRec("SELECT * FROM (
            SELECT ID, EMP_ID FROM HR_EMP_TENURE WHERE EMP_CODE = :emp_code ORDER BY ID DESC
        ) WHERE ROWNUM = 1", [':emp_code' => $employeeCode]);
    if (empty($current['ID'])) {
        throw new RuntimeException('The employee\'s current tenure period was not found.');
    }

    $oracleDate = static fn(DateTimeImmutable $date) =>
        "TO_DATE('" . $date->format('Y-m-d') . "', 'YYYY-MM-DD')";
    $effectiveFrom = $oracleDate($effectiveDate);

    $closed = execQry([
        'type' => 'update', 'table' => 'HR_EMP_TENURE',
        'data' => [
            'EFF_TO' => $oracleDate($effectiveDate->modify('-1 day')),
            'CHG_ON' => 'SYSDATE',
            'CHG_BY' => $changedBy,
        ],
        'where' => ['ID' => trim((string)$current['ID'])],
        'print' => 0,
    ]);
    if ($closed === false) {
        throw new RuntimeException('Could not close the employee\'s current tenure period.');
    }

    $employeeData = [
        'EMP_TYPE' => $newType,
        'DATE_CONF' => $effectiveFrom,
        'CHG_ON' => 'SYSDATE',
        'CHG_BY' => $changedBy,
    ];
    // Moving to Permanent (1) confirms the employee; other types keep their flag.
    if ($newType === '1') {
        $employeeData['CONFIRMED'] = 'Y';
    }
    $updatedEmployee = execQry([
        'type' => 'update', 'table' => 'HR_BCS_EMPLOYEE',
        'data' => $employeeData,
        'where' => ['EMP_CODE' => $employeeCode],
        'print' => 0,
    ]);
    if ($updatedEmployee === false) {
        throw new RuntimeException('Could not update the employee type and confirmation date.');
    }

    // A type with no period (Permanent) has no end date.
    $inserted = execQry([
        'type' => 'insert', 'table' => 'HR_EMP_TENURE',
        'data' => [
            'ID' => '',
            'EMP_ID' => trim((string)$current['EMP_ID']),
            'EMP_CODE' => $employeeCode,
            'ETYPE_ID' => $newType,
            'ETYPE_PERIOD' => $period > 0 ? (string)$period : '',
            'EFF_FROM' => $effectiveFrom,
            'EFF_TO' => $period > 0 ? $oracleDate($effectiveDate->modify("+{$period} months")) : '',
            'CHG_ON' => 'SYSDATE',
            'CHG_BY' => $changedBy,
        ],
        'print' => 0,
    ]);
    if ($inserted === false) {
        throw new RuntimeException('Could not create the employee\'s new tenure period.');
    }
}

/**
 * Creates the EPP leave-credit task (373) when the new type is Permanent (1)
 * or Contractual (2). The task's site is the acting user's EPP work site.
 */
function tenureCreateLeaveCreditTask($newType, $employeeCode, $employeeName, $createdBy)
{
    if (!in_array(trim((string)$newType), ['1', '2'], true)) {
        return;
    }

    $user = singRec(
        "SELECT WORK_SITE FROM HR_BCS_EMPLOYEE WHERE EMP_CODE = :emp_code",
        [':emp_code' => trim((string)$createdBy)]
    );
    $workSite = trim((string)($user['WORK_SITE'] ?? ''));
    if ($workSite === '') {
        throw new RuntimeException('Your employee account lacks a valid Work Site in EPP.');
    }

    $taskId = execQry([
        'type' => 'insert', 'table' => 'HR_BCS_USER_TASKS',
        'data' => [
            'TASK_ID' => '373',
            'CREATED_ON' => 'SYSDATE',
            'EXPIRE_ON' => 'SYSDATE + 2',
            'STATUS' => 'O',
            'TRAN_CODE' => trim((string)$employeeCode),
            'TASK_TYPE' => 'A',
            'TRAN_DESC' => 'Leave Credit for ' . $employeeName,
            'SITE_CODE' => $workSite,
            'CREATED_BY' => trim((string)$createdBy),
        ],
        'print' => 0,
        'return' => 'ID',
    ]);
    if ($taskId === false || $taskId === null || $taskId === '') {
        throw new RuntimeException('Could not create the employee leave-credit task.');
    }
}
