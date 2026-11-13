<?php
/* GET ?task_id=6..22&user_task_id= -> the candidate, interview and offer details a flow task needs.
 * See recruitmentFlowCommon.php for the tasks. */
require_once __DIR__ . '/recruitmentFlowStages.php';

recruitmentRunRead(function () use ($empCode) {
    $workflowTaskId = (int)($_GET['task_id'] ?? 0);
    [$loader] = recruitmentFlowStage($workflowTaskId);
    [$task, $context] = recruitmentFlowLoadTask($workflowTaskId, $_GET['user_task_id'] ?? 0, $empCode);
    session_write_close();

    return ['Task fetched successfully.', array_merge(
        recruitmentFlowSummary($context),
        ['taskDescription' => $task['TRAN_DESC'] ?? ''],
        $loader($task, $context, $empCode)
    )];
}, 'getRecruitmentFlowTask.php');
