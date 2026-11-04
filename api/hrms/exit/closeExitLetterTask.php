<?php
/*
 * POST { user_task_id }
 * Relieving & Experience Letter (24): closes the task once both letters have
 * their reference codes.
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    [$task, $separation] = exitLoadTask(EXIT_TASK_LETTER, $input['user_task_id'] ?? 0, $empCode);
    $docs = exitLetterDocs($separation['EMP_CODE'], $separation['ENTER_ON_ISO']);
    if (in_array(null, $docs, true)) {
        throw new ExitRequestException('Generate the reference codes for both letters before closing the task.', 422);
    }
    exitCloseTask($task['ID'], 'RELIEVING AND EXPERIENCE LETTER PRINTED & CLOSED');
    return ['Relieving & Experience Letter task closed.', null];
}, 'closeExitLetterTask.php');
