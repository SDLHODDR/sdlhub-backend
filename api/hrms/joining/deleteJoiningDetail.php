<?php
/*
 * POST { user_task_id, id } - Delete a saved row of the task's own tab. The old
 * page offered Delete on Tenure and Family only.
 */
require_once __DIR__ . '/joiningDetailCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    $task = joiningLoadDetailTask($input['user_task_id'] ?? 0, $empCode);
    $tables = ['tenure' => 'HR_EMP_TENURE', 'family' => 'HR_EMP_FAMILY_INFO'];
    $table = $tables[$task['SECTION']] ?? '';
    if ($table === '') {
        throw new JoiningRequestException('Records of this task cannot be deleted.', 422);
    }
    $deleted = joiningExecute("DELETE FROM {$table} WHERE ID = :id AND EMP_CODE = :emp_code",
        [':id' => (string)(int)($input['id'] ?? 0), ':emp_code' => $task['TRAN_CODE']], 'Could not delete the record.');
    if (!$deleted) {
        throw new JoiningRequestException('The record was not found.', 404);
    }
    return ['Deleted successfully.', null];
}, 'deleteJoiningDetail.php');
