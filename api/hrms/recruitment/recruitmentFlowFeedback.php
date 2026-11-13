<?php
/*
 * Interview Session (9, each interviewer) and Session as Recommender (17).
 * Legacy session_int.php / session_int_reco.php and recruitment_sv.php?SaveSessionData / CloseSessionTask.
 */

const RECRUITMENT_RESULT_LABELS = ['P' => 'Pass', 'F' => 'Fail', 'H' => 'On Hold'];

/** The user's HR_INTRV_EMPLOYEE row on this interview as interviewer ('I') or recommender ('R'). */
function recruitmentFlowPanelMember($interviewId, $empCode, $type)
{
    $row = recruitmentRow("SELECT ID, INTRVER_CODE, INTRVER_TYPE, PASS_FAIL, INTRVER_REMARKS FROM HR_INTRV_EMPLOYEE
        WHERE INTRV_ID = :id AND INTRVER_CODE = :code AND INTRVER_TYPE = :type ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':id' => (string)$interviewId, ':code' => (string)$empCode, ':type' => $type]);
    if (empty($row['ID'])) {
        throw new RecruitmentRequestException('You are not on the interview panel of this candidate.', 403);
    }
    return $row;
}

/** JD interview questions with their options (legacy session_int.php). */
function recruitmentFlowQuestions($jdId)
{
    $questions = multiRec("SELECT Q.QUESTION_ID, Q.DISP_SEQ, M.QUESTION, M.RATING_TYPE, G.QSGRP_DESC
        FROM HR_JD_QUESTIONS Q
        INNER JOIN HR_QUESTION_MASTER M ON M.ID = Q.QUESTION_ID
        LEFT JOIN HR_QUESTION_SGROUP G ON G.QSGRP_ID = M.QGRP_ID
        WHERE Q.JD_ID = :jd_id AND Q.QGRP_TYPE = 'I'
        ORDER BY Q.QGRP_TYPE, Q.DISP_SEQ, Q.ID", [':jd_id' => (string)(int)$jdId]) ?: [];
    if (!$questions) {
        return [];
    }
    $options = [];
    foreach (multiRec("SELECT O.QUESTION_ID, O.OPTS_TEXT FROM HR_QUESTION_OPTS O
        WHERE O.QUESTION_ID IN (SELECT QUESTION_ID FROM HR_JD_QUESTIONS WHERE JD_ID = :jd_id AND QGRP_TYPE = 'I')
        ORDER BY O.QUESTION_ID, O.OPTS_SEQ, O.ID", [':jd_id' => (string)(int)$jdId]) ?: [] as $row) {
        $options[$row['QUESTION_ID']][] = (string)$row['OPTS_TEXT'];
    }
    $list = [];
    foreach ($questions as $row) {
        $type = in_array($row['RATING_TYPE'], ['R', 'C'], true) ? $row['RATING_TYPE'] : 'T';
        $list[$row['QUESTION_ID']] = [
            'id' => $row['QUESTION_ID'],
            'group' => strtoupper(trim((string)($row['QSGRP_DESC'] ?? ''))),
            'question' => ucwords(strtolower(trim((string)$row['QUESTION']))),
            'type' => $type,
            'options' => $options[$row['QUESTION_ID']] ?? [],
        ];
    }
    return $list;
}

function recruitmentFlowCapabilities($jdId)
{
    return multiRec("SELECT A.CAPA_ID, A.CAPALVL_ID, B.CAPA_CODE, B.CAPA_DESC, L.CAPALVL_DESC AS EXPECTED
        FROM HR_JD_CAPABILITIES A
        INNER JOIN HR_CAPABILITIES B ON B.CAPA_ID = A.CAPA_ID
        LEFT JOIN HR_CAPA_LEVEL L ON L.CAPALVL_ID = A.CAPALVL_ID
        WHERE A.JD_ID = :jd_id
        ORDER BY B.CAPA_CODE, A.ID", [':jd_id' => (string)(int)$jdId]) ?: [];
}

function recruitmentFlowCapabilityLevels()
{
    return array_map(static fn($row) => ['value' => $row['CAPALVL_ID'], 'label' => $row['CAPALVL_DESC']],
        multiRec("SELECT CAPALVL_ID, CAPALVL_DESC FROM HR_CAPA_LEVEL ORDER BY CAPALVL_ID DESC") ?: []);
}

/** Saved answers and capability levels of one interviewer. */
function recruitmentFlowSavedFeedback($interviewId, $interviewerCode)
{
    $answers = [];
    foreach (multiRec("SELECT QUES_ID, CAN_ANS_TEXT, REMARKS FROM HR_INTRV_ANSWERS WHERE INTRV_ID = :id AND INTRVER_CODE = :code",
        [':id' => (string)$interviewId, ':code' => (string)$interviewerCode]) ?: [] as $row) {
        $answers[$row['QUES_ID']] = ['answer' => (string)($row['CAN_ANS_TEXT'] ?? ''), 'remarks' => (string)($row['REMARKS'] ?? '')];
    }
    $levels = [];
    foreach (multiRec("SELECT CAPA_ID, CAN_CAPALVL_ID FROM HR_INTRV_CAPA_ANSWERS WHERE INTRV_ID = :id AND INTRVER_CODE = :code",
        [':id' => (string)$interviewId, ':code' => (string)$interviewerCode]) ?: [] as $row) {
        $levels[$row['CAPA_ID']] = (string)($row['CAN_CAPALVL_ID'] ?? '');
    }
    return [$answers, $levels];
}

/** Every interviewer's result and scores, for the recommender (legacy "View Results"). */
function recruitmentFlowPanelResults($interviewId, $jdId)
{
    $questions = recruitmentFlowQuestions($jdId);
    $capabilities = recruitmentFlowCapabilities($jdId);
    $levelNames = array_column(recruitmentFlowCapabilityLevels(), 'label', 'value');
    $expected = recruitmentRow("SELECT NVL(SUM(L.SCORE), 0) AS SCORE FROM HR_JD_CAPABILITIES A
        INNER JOIN HR_CAPA_LEVEL L ON L.CAPALVL_ID = A.CAPALVL_ID WHERE A.JD_ID = :jd_id", [':jd_id' => (string)(int)$jdId]);

    $interviewers = [];
    // One entry per interviewer: older data can hold the same interviewer twice (legacy "select distinct").
    foreach (multiRec("SELECT INTRVER_CODE, MAX(PASS_FAIL) AS PASS_FAIL, MAX(INTRVER_REMARKS) AS INTRVER_REMARKS
        FROM HR_INTRV_EMPLOYEE WHERE INTRV_ID = :id AND INTRVER_TYPE = 'I'
        GROUP BY INTRVER_CODE ORDER BY MIN(ID)", [':id' => (string)$interviewId]) ?: [] as $row) {
        [$answers, $levels] = recruitmentFlowSavedFeedback($interviewId, $row['INTRVER_CODE']);
        $score = recruitmentRow("SELECT NVL(SUM(L.SCORE), 0) AS SCORE FROM HR_INTRV_CAPA_ANSWERS A
            INNER JOIN HR_CAPA_LEVEL L ON L.CAPALVL_ID = A.CAN_CAPALVL_ID
            WHERE A.INTRV_ID = :id AND A.INTRVER_CODE = :code", [':id' => (string)$interviewId, ':code' => $row['INTRVER_CODE']]);
        $interviewers[] = [
            'code' => $row['INTRVER_CODE'],
            'name' => recruitmentFlowEmployeeName($row['INTRVER_CODE']),
            'result' => $row['PASS_FAIL'] ?? '',
            'resultText' => RECRUITMENT_RESULT_LABELS[$row['PASS_FAIL'] ?? ''] ?? '',
            'remarks' => $row['INTRVER_REMARKS'] ?? '',
            'answers' => array_map(static fn($answer) => $answer['answer'], $answers),
            'levels' => array_map(static fn($level) => $levelNames[$level] ?? '', $levels),
            'score' => (int)($score['SCORE'] ?? 0),
        ];
    }
    return [
        'interviewers' => $interviewers,
        'questions' => array_values(array_map(static fn($q) => ['id' => $q['id'], 'question' => $q['question']], $questions)),
        'capabilities' => array_map(static fn($row) => [
            'id' => $row['CAPA_ID'],
            'description' => ucwords(strtolower(trim((string)$row['CAPA_DESC']))),
            'expected' => $row['EXPECTED'] ?? '',
        ], $capabilities),
        'expectedScore' => (int)($expected['SCORE'] ?? 0),
    ];
}

function recruitmentFlowLoadFeedbackTask(array $task, array $context, $empCode)
{
    $interview = $context['interview'];
    $jdId = $context['request']['JD_ID'];

    if ((int)$task['TASK_ID'] === RECRUITMENT_TASK_INTERVIEW_SESSION) {
        $member = recruitmentFlowPanelMember($interview['ID'], $empCode, 'I');
        [$answers, $levels] = recruitmentFlowSavedFeedback($interview['ID'], $empCode);
        return [
            'questions' => array_values(recruitmentFlowQuestions($jdId)),
            'capabilities' => array_map(static fn($row) => [
                'id' => $row['CAPA_ID'],
                'group' => strtoupper(trim((string)$row['CAPA_CODE'])),
                'description' => ucwords(strtolower(trim((string)$row['CAPA_DESC']))),
            ], recruitmentFlowCapabilities($jdId)),
            'levels' => recruitmentFlowCapabilityLevels(),
            'saved' => [
                'answers' => (object)$answers,
                'levels' => (object)$levels,
                'result' => $member['PASS_FAIL'] ?? '',
                'remarks' => $member['INTRVER_REMARKS'] ?? '',
            ],
            'results' => [['value' => 'P', 'label' => 'Pass'], ['value' => 'F', 'label' => 'Fail']],
        ];
    }

    $member = recruitmentFlowPanelMember($interview['ID'], $empCode, 'R');
    $panel = recruitmentFlowPanelResults($interview['ID'], $jdId);
    $pending = array_values(array_map(static fn($row) => $row['name'],
        array_filter($panel['interviewers'], static fn($row) => $row['result'] === '')));
    $anyFail = (bool)array_filter($panel['interviewers'], static fn($row) => $row['result'] === 'F');
    $allowed = $anyFail ? ['F'] : ['P', 'F', 'H'];

    return [
        'panel' => $panel,
        'pendingInterviewers' => $pending,
        'results' => array_map(static fn($code) => ['value' => $code, 'label' => RECRUITMENT_RESULT_LABELS[$code]], $allowed),
        'employeeTypes' => array_map(static fn($row) => ['value' => $row['ETYPE_ID'], 'label' => ucwords(strtolower($row['EMP_TYPE']))],
            multiRec("SELECT ETYPE_ID, EMP_TYPE FROM HR_EMP_TYPE ORDER BY ETYPE_ID") ?: []),
        'saved' => [
            'result' => $member['PASS_FAIL'] ?? '',
            'ctcOffer' => $interview['CTC_OFFER'] ?? '',
            'employeeType' => $interview['ETYPE_ID'] ?? '',
            'joiningDate' => $interview['TNT_JOIN_DAY'] ?? '',
            'period' => $interview['ETYPE_PERIOD'] ?? '',
        ],
    ];
}

/** Save the interviewer's answers, capability levels and result. Returns true when everything is filled. */
function recruitmentFlowSaveInterviewerFeedback(array $context, array $member, array $input, $empCode)
{
    $interview = $context['interview'];
    $application = $context['application'];
    $jdId = $context['request']['JD_ID'];
    $questions = recruitmentFlowQuestions($jdId);
    $capabilities = array_column(recruitmentFlowCapabilities($jdId), 'CAPA_ID', 'CAPA_ID');
    $levelIds = array_column(recruitmentFlowCapabilityLevels(), 'value', 'value');
    $answers = (array)($input['answers'] ?? []);
    $answerRemarks = (array)($input['answer_remarks'] ?? []);
    $levels = (array)($input['levels'] ?? []);
    $complete = true;

    $scope = [':id' => (string)$interview['ID'], ':code' => (string)$empCode];
    recruitmentExecute("DELETE FROM HR_INTRV_ANSWERS WHERE INTRV_ID = :id AND INTRVER_CODE = :code", $scope, 'Could not save the answers.');
    foreach ($questions as $questionId => $question) {
        $value = $answers[$questionId] ?? '';
        if ($question['type'] === 'C') {
            $chosen = array_values(array_unique(array_map('strval', (array)$value)));
            foreach ($chosen as $option) {
                if (!in_array($option, $question['options'], true)) {
                    throw new RecruitmentRequestException('Please choose a listed option for every question.', 422);
                }
            }
            $text = implode(',', $chosen);
        } else {
            $text = trim((string)(is_array($value) ? reset($value) : $value));
            if ($question['type'] === 'R' && $text !== '' && !in_array($text, $question['options'], true)) {
                throw new RecruitmentRequestException('Please choose a listed option for every question.', 422);
            }
        }
        $remark = trim((string)($answerRemarks[$questionId] ?? ''));
        recruitmentAssertLength($text, RECRUITMENT_ANSWER_MAX, 'An answer');
        recruitmentAssertLength($remark, RECRUITMENT_ANSWER_MAX, 'A remark');
        if ($text === '') {
            $complete = false;
            if ($remark === '') {
                continue;
            }
        }
        recruitmentFlowInsert('HR_INTRV_ANSWERS', [
            'INTRV_ID' => $interview['ID'],
            'INTRVER_CODE' => $empCode,
            'CAN_ID' => $application['CAN_ID'],
            'REQ_ID' => $application['REQ_ID'],
            'JD_ID' => $jdId,
            'QUES_ID' => $questionId,
            'CAN_ANS_TEXT' => $text,
            'REMARKS' => $remark,
            'CHG_BY' => $empCode,
        ], ['CHG_ON']);
    }

    recruitmentExecute("DELETE FROM HR_INTRV_CAPA_ANSWERS WHERE INTRV_ID = :id AND INTRVER_CODE = :code", $scope, 'Could not save the capabilities.');
    foreach ($capabilities as $capaId) {
        $level = trim((string)($levels[$capaId] ?? ''));
        if ($level === '') {
            $complete = false;
            continue;
        }
        if (!isset($levelIds[$level])) {
            throw new RecruitmentRequestException('Please choose a listed level for every capability.', 422);
        }
        recruitmentFlowInsert('HR_INTRV_CAPA_ANSWERS', [
            'INTRV_ID' => $interview['ID'],
            'INTRVER_CODE' => $empCode,
            'CAN_ID' => $application['CAN_ID'],
            'REQ_ID' => $application['REQ_ID'],
            'JD_ID' => $jdId,
            'CAPA_ID' => $capaId,
            'CAN_CAPALVL_ID' => $level,
            'CHG_BY' => $empCode,
        ], ['CHG_ON']);
    }
    recruitmentExecute("UPDATE HR_INTRV_ANSWERS SET INTRV_DATE = (SELECT TRUNC(INTRV_DATE) FROM HR_INTRV_DATES WHERE ID = :id)
        WHERE INTRV_ID = :id AND INTRVER_CODE = :code", $scope, 'Could not save the answers.');
    recruitmentExecute("UPDATE HR_INTRV_CAPA_ANSWERS SET INTRV_DATE = (SELECT TRUNC(INTRV_DATE) FROM HR_INTRV_DATES WHERE ID = :id)
        WHERE INTRV_ID = :id AND INTRVER_CODE = :code", $scope, 'Could not save the capabilities.');

    $result = strtoupper(trim((string)($input['result'] ?? '')));
    if ($result !== '' && !in_array($result, ['P', 'F'], true)) {
        throw new RecruitmentRequestException('Please select Pass or Fail.', 422);
    }
    $remarks = trim((string)($input['remarks'] ?? ''));
    recruitmentAssertLength($remarks, RECRUITMENT_FEEDBACK_REMARK_MAX, 'Remarks');
    // Every row of this interviewer on the interview (older data can hold duplicates).
    recruitmentExecute("UPDATE HR_INTRV_EMPLOYEE SET PASS_FAIL = :result, INTRVER_REMARKS = :remarks, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE INTRV_ID = :intrv_id AND INTRVER_CODE = :code AND INTRVER_TYPE = 'I'",
        [':result' => $result, ':remarks' => $remarks, ':chg_by' => $empCode, ':intrv_id' => (string)$interview['ID'], ':code' => (string)$empCode],
        'Could not save the result.');

    return $complete && $result !== '';
}

function recruitmentFlowFeedbackAction(array $task, array $context, $action, array $input, $empCode)
{
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new RecruitmentRequestException('Invalid action.', 422);
    }
    $interview = $context['interview'];
    $userTaskId = (string)$task['ID'];

    if ((int)$task['TASK_ID'] === RECRUITMENT_TASK_INTERVIEW_SESSION) {
        $member = recruitmentFlowPanelMember($interview['ID'], $empCode, 'I');
        $complete = recruitmentFlowSaveInterviewerFeedback($context, $member, $input, $empCode);
        if ($action === 'save') {
            return ['Interview feedback saved.', null];
        }
        if (!$complete) {
            throw new RecruitmentRequestException('Please answer every question, rate every capability and select the result before submitting.', 422);
        }
        recruitmentCloseTask($userTaskId, $empCode);
        return ['Interview feedback submitted.', null];
    }

    // Recommender (17): waits for every interviewer.
    $member = recruitmentFlowPanelMember($interview['ID'], $empCode, 'R');
    $panel = recruitmentFlowPanelResults($interview['ID'], $context['request']['JD_ID']);
    if (array_filter($panel['interviewers'], static fn($row) => $row['result'] === '')) {
        throw new RecruitmentRequestException('All interviewers have not filled their feedback yet.', 409);
    }
    $anyFail = (bool)array_filter($panel['interviewers'], static fn($row) => $row['result'] === 'F');
    $result = strtoupper(trim((string)($input['result'] ?? '')));
    if (!in_array($result, $anyFail ? ['F'] : ['P', 'F', 'H'], true)) {
        throw new RecruitmentRequestException($anyFail ? 'An interviewer has failed the candidate, so the result can only be Fail.' : 'Please select the result.', 422);
    }

    $ctc = $type = $joiningDate = $period = '';
    if ($result === 'P') {
        $ctc = recruitmentFlowAssertNumber($input['ctc_offer'] ?? '', 'CTC offer (lakhs per annum)');
        $type = trim((string)($input['employee_type'] ?? ''));
        $types = array_column(multiRec("SELECT ETYPE_ID FROM HR_EMP_TYPE") ?: [], 'ETYPE_ID');
        if (!in_array($type, $types, true)) {
            throw new RecruitmentRequestException('Please select the employee type.', 422);
        }
        $joiningDate = trim((string)($input['joining_date'] ?? ''));
        recruitmentAssertDate($joiningDate, 'Tentative joining date');
        $period = recruitmentFlowAssertNumber($input['period'] ?? '', 'period (months)', 0, true);
    } elseif ($result === 'H') {
        // Legacy keeps the offer details on hold; clears them only on Fail.
        $ctc = (string)($interview['CTC_OFFER'] ?? '');
        $type = (string)($interview['ETYPE_ID'] ?? '');
        $joiningDate = (string)($interview['TNT_JOIN_DAY'] ?? '');
        $period = (string)($interview['ETYPE_PERIOD'] ?? '');
    }

    recruitmentExecute("UPDATE HR_INTRV_DATES SET CTC_OFFER = :ctc, ETYPE_ID = :etype, ETYPE_PERIOD = :period,
            TNT_JOIN_DATE = TO_DATE(:join_date, 'YYYY-MM-DD'), CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE ID = :id", [
        ':ctc' => $ctc, ':etype' => $type, ':period' => $period, ':join_date' => $joiningDate,
        ':chg_by' => $empCode, ':id' => (string)$interview['ID'],
    ], 'Could not save the offer details.');
    recruitmentExecute("UPDATE HR_INTRV_EMPLOYEE SET PASS_FAIL = :result, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE INTRV_ID = :intrv_id AND INTRVER_CODE = :code AND INTRVER_TYPE = 'R'",
        [':result' => $result, ':chg_by' => $empCode, ':intrv_id' => (string)$interview['ID'], ':code' => (string)$empCode],
        'Could not save the result.');
    if (($context['application']['STATUS'] ?? '') !== $result) {
        recruitmentFlowSetApplicationStatus($context['application'], $result, $userTaskId, $empCode);
    }

    if ($action === 'save') {
        return ['Recommendation saved.', null];
    }
    if ($result === 'P') {
        recruitmentFlowCreateTask('OFFER_GEN', $interview['ID'],
            'REQ_ID#' . $context['request']['ID'] . ' Generate Offer for ' . recruitmentFlowCandidateName($context['candidate']),
            null, $empCode, $context['request']);
    }
    recruitmentCloseTask($userTaskId, $empCode);
    return [$result === 'P'
        ? 'Recommendation submitted. A Generate Offer task has been generated for HR.'
        : 'Recommendation submitted.', null];
}
