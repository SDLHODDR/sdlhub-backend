<?php
/*
 * GET ?user_task_id= (Employee Code Generation, task 3).
 * Before the employee exists: the candidate's details to pre-fill the Personal
 * tab and the office the employee will get (from the recruitment request).
 * Once the employee exists: { empCode } so the page opens that employee.
 */
require_once __DIR__ . '/joiningCandidate.php';

try {
    $task = joiningLoadCandidateTask($_GET['user_task_id'] ?? 0, $empCode);
    $application = $task['APPLICATION'];

    $empCodeLinked = trim((string)($application['EMP_CODE'] ?? ''));
    if ($empCodeLinked !== '') {
        apiResponse(true, 'Employee already created.', ['empCode' => $empCodeLinked, 'taskId' => (string)$task['ID']], 200);
        exit;
    }
    if ($application['STATUS'] !== 'T') {
        throw new JoiningRequestException('This candidate is not waiting for an employee code.', 409);
    }

    $prefill = joiningCandidatePrefill($application);
    apiResponse(true, 'Candidate fetched successfully.', [
        'empCode' => '',
        'taskId' => (string)$task['ID'],
        'candidateName' => $prefill['name'],
        'personal' => $prefill,
        'office' => [
            'company' => $application['COMPANY_NAME'],
            'division' => $application['DIVISION'],
            'department' => $application['DEPARTMENT'],
            'designation' => $application['DESIGNATION'],
            'location' => $application['LOCATION'],
        ],
    ], 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getCandidateJoinee.php');
    apiResponse(false, 'Unable to load the candidate.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
