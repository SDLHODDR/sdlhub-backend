<?php
/*
 * POST { id?, action: 'save'|'submit', emp_code, resign_type, resign_mode, recipient_email,
 *        resignation_date, expected_relieving_date (YYYY-MM-DD), employee_comments }
 * Separation Request page. Legacy separation_sv.php?saveresign / submitResignation:
 * save keeps the request as 'N'; submit sets 'T' and creates the Separation Request task (18).
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $id = (int)($input['id'] ?? 0);
    $employeeCode = trim((string)($input['emp_code'] ?? ''));
    $resignType = strtoupper(trim((string)($input['resign_type'] ?? '')));
    $resignMode = strtoupper(trim((string)($input['resign_mode'] ?? '')));
    $email = trim((string)($input['recipient_email'] ?? ''));
    $resignationDate = trim((string)($input['resignation_date'] ?? ''));
    $relievingDate = trim((string)($input['expected_relieving_date'] ?? ''));
    $comments = trim((string)($input['employee_comments'] ?? ''));

    if (!in_array($action, ['save', 'submit'], true)) {
        throw new ExitRequestException('Invalid action.', 422);
    }
    if (!preg_match('/^[A-Za-z0-9]+$/', $employeeCode)) {
        throw new ExitRequestException('Employee is required.', 422);
    }
    if (!in_array($resignType, ['A', 'T', 'R', 'W', 'C'], true)) {
        throw new ExitRequestException('Resignation type is required.', 422);
    }
    if (!in_array($resignMode, ['E', 'T', 'O'], true)) {
        throw new ExitRequestException('Resignation mode is required.', 422);
    }
    if ($resignMode === 'E') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ExitRequestException('Please enter a valid recipient email address.', 422);
        }
    } else {
        $email = '';
    }
    foreach (['Resignation date' => $resignationDate, 'Expected relieving date' => $relievingDate] as $label => $value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new ExitRequestException("{$label} is required.", 422);
        }
    }
    if ($relievingDate < $resignationDate) {
        throw new ExitRequestException('Expected relieving date cannot be before the resignation date.', 422);
    }
    if ($comments === '') {
        throw new ExitRequestException('Employee comments are required.', 422);
    }
    exitAssertLength($email, 50, 'Recipient email');
    exitAssertLength($comments, 2000, 'Employee comments');

    // Only employees within the user's profile scope.
    loadHrmsAccess($empCode);
    $inScope = singRec("SELECT COUNT(*) AS CNT FROM HR_EMP_OFFICE_DET C
        INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = C.EMP_CODE
        WHERE C.EMP_CODE = :emp_code AND " . exitScopeSql('C', 'E'), [':emp_code' => $employeeCode]);
    if (!hrmsHasScope() || (int)($inScope['CNT'] ?? 0) === 0) {
        throw new ExitRequestException('You are not authorized to raise a separation for this employee.', 403);
    }

    if ($id > 0) {
        $existing = singRec("SELECT ID, STATUS FROM HR_EMP_SEPARATION WHERE ID = :id", [':id' => (string)$id]);
        if (empty($existing['ID'])) {
            throw new ExitRequestException('Separation request not found.', 404);
        }
        if ($existing['STATUS'] !== 'N') {
            throw new ExitRequestException('This separation request has already been submitted.', 409);
        }
    }
    $open = singRec("SELECT COUNT(*) AS CNT FROM HR_EMP_SEPARATION
        WHERE EMP_CODE = :emp_code AND STATUS NOT IN ('C', 'R') AND ID <> :id",
        [':emp_code' => $employeeCode, ':id' => (string)$id]);
    if ((int)($open['CNT'] ?? 0) > 0) {
        throw new ExitRequestException('A separation request has already been raised for this employee.', 409);
    }

    $binds = [
        ':emp_code' => $employeeCode,
        ':resign_type' => $resignType,
        ':resign_mode' => $resignMode,
        ':email' => $email,
        ':resign_date' => $resignationDate,
        ':reliv_date' => $relievingDate,
        ':comments' => $comments,
        ':enter_by' => $empCode,
        ':handover' => findParentOrgEmp($employeeCode) ?: '',
    ];
    if ($id > 0) {
        exitExecute("UPDATE HR_EMP_SEPARATION
            SET EMP_CODE = :emp_code, RESIGN_TYPE = :resign_type, RESIGN_MODE = :resign_mode,
                RESIGN_RCP_EMAIL = :email, RESIGN_RCP_DATE = TO_DATE(:resign_date, 'YYYY-MM-DD'),
                EXP_RELIV_DATE = TO_DATE(:reliv_date, 'YYYY-MM-DD'), EMP_COMMENTS = :comments,
                ENTER_BY_EMP = :enter_by, ENTER_ON_EMP = SYSDATE, HANDOVER_TO = :handover
            WHERE ID = :id AND STATUS = 'N'", $binds + [':id' => (string)$id],
            'Could not save the separation request.');
    } else {
        global $sql___func___con;
        $stmt = oci_parse($sql___func___con, "INSERT INTO HR_EMP_SEPARATION
                (ID, EMP_CODE, RESIGN_TYPE, RESIGN_MODE, RESIGN_RCP_EMAIL, RESIGN_RCP_DATE, EXP_RELIV_DATE,
                 EMP_COMMENTS, ENTER_BY_EMP, ENTER_ON_EMP, STATUS, HANDOVER_TO)
            VALUES (NULL, :emp_code, :resign_type, :resign_mode, :email, TO_DATE(:resign_date, 'YYYY-MM-DD'),
                 TO_DATE(:reliv_date, 'YYYY-MM-DD'), :comments, :enter_by, SYSDATE, 'N', :handover)
            RETURNING ID INTO :new_id");
        foreach ($binds as $key => $value) {
            $binds[$key] = $value;
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }
        $newId = null;
        oci_bind_by_name($stmt, ':new_id', $newId, 40);
        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT) || !$newId) {
            logOracleError(oci_error($stmt), 'saveSeparationRequest insert');
            throw new RuntimeException('Could not save the separation request.');
        }
        oci_free_statement($stmt);
        $id = (int)$newId;
    }

    if ($action === 'save') {
        return ['Separation request saved successfully.', ['id' => $id]];
    }

    exitExecute("UPDATE HR_EMP_SEPARATION SET STATUS = 'T' WHERE ID = :id AND STATUS = 'N'",
        [':id' => (string)$id], 'Could not submit the separation request.');
    $company = singRec("SELECT COMP_ID FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    $office = exitEmployeeOffice($employeeCode, $relievingDate);
    $taskId = generateTaskHR('SEPARATION', $id, 'Resignation Request of  ' . getEmpInfoByCodeHR($employeeCode),
        null, null, 'O', null, $empCode, $company['COMP_ID'] ?? '', $office['DIVSN_ID'] ?? '', $office['DEPT_ID'] ?? '');
    if (empty($taskId)) {
        throw new RuntimeException('Could not create the Separation Request task.');
    }

    return ['Separation request submitted. A task has been generated for authorization.', ['id' => $id]];
}, 'saveSeparationRequest.php');
