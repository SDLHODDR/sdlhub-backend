<?php
/* POST { task_id: 6..22, user_task_id, action, ... } -> saves a flow task or completes it and raises the next one.
 * Actions per task:
 *   6  send (candidate_ids)              7  save | authorize (decisions {can_id: A|R})
 *   8  save | submit (date, time, mode, outcome C|R)
 *   16 send                              11 assign (interviewers[], recommender)
 *   9  save | submit (answers, answer_remarks, levels, result, remarks)
 *   17 save | submit (result, ctc_offer, employee_type, joining_date, period)
 *   10 save | submit (amounts {code: monthly})
 *   21 save | email | authorize (amounts)
 *   22 close                             12 save (accepted Y|N, date) | submit */
require_once __DIR__ . '/recruitmentFlowStages.php';

recruitmentRunAction(function () use ($empCode) {
    $input = recruitmentInput();
    $workflowTaskId = (int)($input['task_id'] ?? 0);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    [, $handler] = recruitmentFlowStage($workflowTaskId);
    [$task, $context] = recruitmentFlowLoadTask($workflowTaskId, $input['user_task_id'] ?? 0, $empCode);
    // Older tasks can still be open for a candidate who has since joined; acting on them would undo the joining status.
    if (($context['application']['STATUS'] ?? '') === 'E') {
        throw new RecruitmentRequestException('This candidate has already joined, so this task can no longer be processed.', 409);
    }

    return $handler($task, $context, $action, $input, $empCode);
}, 'processRecruitmentFlowTask.php');
