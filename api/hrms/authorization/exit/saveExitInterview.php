<?php
/*
 * POST { user_task_id, action: 'save'|'submit', answers: { <question id>: "<answer>" },
 *        retention_flag: 'Y'|'N', exit_comments }
 * Exit Interview (20). Legacy separation_sv.php?saveExitInt / CloseExitInt:
 * save stores answers + exit comments; submit (all answered) closes the separation
 * and, unless the employee is retained, creates an Exit Activity task per
 * department with exit activities plus the Deactivate Employee task.
 */
require_once __DIR__ . '/exitCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = exitInput();

exitRunAction(function () use ($input, $empCode) {
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $retentionFlag = strtoupper(trim((string)($input['retention_flag'] ?? '')));
    $exitComments = trim((string)($input['exit_comments'] ?? ''));
    $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];

    if (!in_array($action, ['save', 'submit'], true)) {
        throw new ExitRequestException('Invalid action.', 422);
    }
    if (!in_array($retentionFlag, ['Y', 'N'], true)) {
        throw new ExitRequestException('Please select whether to retain the employee.', 422);
    }
    if ($exitComments === '') {
        throw new ExitRequestException('Exit / retention comments are required.', 422);
    }
    exitAssertLength($exitComments, 500, 'Exit / retention comments');

    [$task, $separation] = exitLoadTask(EXIT_TASK_INTERVIEW, $input['user_task_id'] ?? 0, $empCode);
    $office = exitEmployeeOffice($separation['EMP_CODE'], exitSeparationDate($separation));
    $jdId = $office['JD_ID'] ?? '';
    $questions = exitInterviewQuestions($jdId, $separation['ID']);
    if (!$questions) {
        throw new ExitRequestException('No exit interview questions are configured for this employee\'s Job Description.', 422);
    }

    // Validate answers against the configured questions and their options.
    $clean = [];
    foreach ($questions as $question) {
        $value = trim((string)($answers[$question['id']] ?? ''));
        if ($value !== '' && $question['input_type'] !== 'text') {
            $parts = array_map('trim', explode(',', $value));
            if ($question['input_type'] === 'radio' && count($parts) > 1) {
                throw new ExitRequestException("Select one option for: {$question['question']}", 422);
            }
            foreach ($parts as $part) {
                if (!in_array($part, $question['options'], true)) {
                    throw new ExitRequestException("Invalid option for: {$question['question']}", 422);
                }
            }
        }
        exitAssertLength($value, 1000, "Answer for {$question['question']}");
        if ($action === 'submit' && $value === '') {
            throw new ExitRequestException("Please answer: {$question['question']}", 422);
        }
        $clean[$question['id']] = $value;
    }

    exitExecute("DELETE FROM HR_EMP_EXIT_INTRV WHERE REF_ID = :ref_id",
        [':ref_id' => $separation['ID']], 'Could not save the exit interview answers.');
    foreach ($clean as $questionId => $value) {
        if ($value === '') continue;
        exitExecute("INSERT INTO HR_EMP_EXIT_INTRV
                (ID, REF_ID, EMP_CODE, ASON_DATE, HR_EMP_CODE, JD_ID, QUES_ID, EMP_ANS_TEXT, CHG_BY, CHG_ON)
            VALUES (NULL, :ref_id, :emp_code, SYSDATE, :hr_emp_code, :jd_id, :ques_id, :answer, :chg_by, SYSDATE)", [
            ':ref_id' => $separation['ID'],
            ':emp_code' => $separation['EMP_CODE'],
            ':hr_emp_code' => $empCode,
            ':jd_id' => $jdId,
            ':ques_id' => $questionId,
            ':answer' => $value,
            ':chg_by' => $empCode,
        ], 'Could not save the exit interview answers.');
    }

    exitExecute("UPDATE HR_EMP_SEPARATION
        SET EXIT_COMMENTS = :comments, RETENTION_FLAG = :retention, EXIT_COMMENT_BY = :comment_by, EXIT_COMMENT_ON = SYSDATE"
        . ($action === 'submit' ? ", STATUS = 'C'" : '') . "
        WHERE ID = :id", [
        ':comments' => $exitComments,
        ':retention' => $retentionFlag,
        ':comment_by' => $empCode, // ':by' is an Oracle reserved word (ORA-01745)
        ':id' => $separation['ID'],
    ], 'Could not save the exit comments.');

    if ($action === 'save') {
        return ['Exit interview saved successfully.', null];
    }

    $created = [];
    if ($retentionFlag === 'N') {
        $employeeName = getEmpInfoByCodeHR($separation['EMP_CODE']);
        // Departments mapped to the JD that have exit activities.
        $departments = multiRec("SELECT DISTINCT R.DEPT_ID FROM HR_JD_REF_DEPT R
            WHERE R.JD_ID = :jd_id
              AND R.DEPT_ID IN (SELECT DEPT_ID FROM HR_DEPT_JOEX_ACTIVITY WHERE ACT_TYPE = 'E')", [':jd_id' => (string)$jdId]);
        foreach ($departments ?: [] as $department) {
            $created[] = exitGenerateTask('EXIT_ACT', $separation, 'Exit activity for ' . $employeeName,
                $empCode, $office['COMPANY'] ?? '', $office['ORG_DIVSN_ID'] ?? '', $department['DEPT_ID']);
        }
        $created[] = exitGenerateTask('DEACT_EMP', $separation,
            'Deactive User  :  ' . $employeeName . ' - ' . ($separation['RELEIVING_DATE'] ?: $separation['EXP_RELIV_DATE']),
            $empCode, $office['COMPANY'] ?? '', $office['ORG_DIVSN_ID'] ?? '', EXIT_HR_DEPT_ID);
    }
    exitCloseTask($task['ID']);

    return [
        $retentionFlag === 'N'
            ? 'Exit interview submitted. Exit activity and deactivation tasks generated.'
            : 'Exit interview submitted. Employee retained, so no further exit tasks were generated.',
        ['tasks_created' => count($created)],
    ];
}, 'saveExitInterview.php');
