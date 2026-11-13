<?php
/*
 * Schedule Interview (8), Interview Confirmation (16) and Assign Interviewer and Recommender (11).
 * Legacy schedule_int.php, conf_interview.php, conf_can_int.php, assign_int.php and
 * recruitment_sv.php?ScheduleInterviewCandidate / closeTaskSchedInt / ConfInterviewCandidate / AssignIntandreco.
 *
 * In legacy the recruiter submitted the schedule and then clicked the candidate's
 * "Yes, Available" / "No, Reschedule" link (conf_interview.php) from the email preview.
 * Here Submit records that answer directly: confirmed -> Interview Confirmation (16),
 * reschedule -> a new Schedule Interview task (8).
 */

function recruitmentFlowInterviewHistory(array $application)
{
    return array_map('recruitmentFlowInterviewView', multiRec("SELECT ID, CAN_ID, REQ_ID, INTRV_STATUS, INTRV_MODE,
            TO_CHAR(INTRV_DATE, 'YYYY-MM-DD') AS INTRV_DAY, TO_CHAR(INTRV_DATE, 'HH24:MI') AS INTRV_TIME,
            TO_CHAR(INTRV_DATE, 'DD-Mon-YYYY') AS INTRV_DATE_TEXT,
            CTC_OFFER, ETYPE_ID, ETYPE_PERIOD, NULL AS TNT_JOIN_DAY, NULL AS TNT_JOIN_TEXT
        FROM HR_INTRV_DATES WHERE CAN_ID = :can_id AND REQ_ID = :req_id ORDER BY ID",
        [':can_id' => (string)$application['CAN_ID'], ':req_id' => (string)$application['REQ_ID']]) ?: []);
}

/** Latest interview of the application in a status ('N' = being scheduled, 'C' = confirmed). */
function recruitmentFlowLatestInterview(array $application, $status)
{
    $row = recruitmentRow("SELECT ID FROM HR_INTRV_DATES WHERE CAN_ID = :can_id AND REQ_ID = :req_id AND INTRV_STATUS = :status
        ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':can_id' => (string)$application['CAN_ID'], ':req_id' => (string)$application['REQ_ID'], ':status' => $status]);
    return empty($row['ID']) ? [] : recruitmentFlowInterview($row['ID']);
}

function recruitmentFlowLoadInterviewTask(array $task, array $context)
{
    $application = $context['application'];
    $workflowTaskId = (int)$task['TASK_ID'];
    if ($workflowTaskId === RECRUITMENT_TASK_SCHEDULE_INTERVIEW) {
        $current = recruitmentFlowLatestInterview($application, 'N');
        return [
            'current' => $current ? recruitmentFlowInterviewView($current) : null,
            'history' => recruitmentFlowInterviewHistory($application),
            'modes' => array_map(static fn($value, $label) => ['value' => $value, 'label' => $label],
                array_keys(RECRUITMENT_INTERVIEW_MODES), RECRUITMENT_INTERVIEW_MODES),
        ];
    }

    $confirmed = recruitmentFlowLatestInterview($application, 'C');
    $data = ['confirmed' => $confirmed ? recruitmentFlowInterviewView($confirmed) : null];
    if ($workflowTaskId === RECRUITMENT_TASK_ASSIGN_INTERVIEWER) {
        $data['employees'] = recruitmentFlowActiveEmployees();
    }
    return $data;
}

/** Validated date / time / mode of the schedule form. */
function recruitmentFlowScheduleInput(array $input)
{
    $date = trim((string)($input['date'] ?? ''));
    recruitmentAssertDate($date, 'Interview date');
    $time = trim((string)($input['time'] ?? ''));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new RecruitmentRequestException('Please enter the interview time as HH:MM (24 hours).', 422);
    }
    $mode = strtoupper(trim((string)($input['mode'] ?? '')));
    if (!isset(RECRUITMENT_INTERVIEW_MODES[$mode])) {
        throw new RecruitmentRequestException('Please select the interview mode.', 422);
    }
    return [$date . ' ' . $time, $mode];
}

/** Insert or update the application's interview being scheduled (status 'N'). Returns its id. */
function recruitmentFlowSaveSchedule(array $application, array $input, $userTaskId, $empCode)
{
    [$dateTime, $mode] = recruitmentFlowScheduleInput($input);
    $current = recruitmentFlowLatestInterview($application, 'N');
    if (!empty($current['ID'])) {
        $interviewId = $current['ID'];
        recruitmentExecute("UPDATE HR_INTRV_DATES SET INTRV_DATE = TO_DATE(:dt, 'YYYY-MM-DD HH24:MI'), INTRV_MODE = :intrv_mode,
                CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE ID = :id AND INTRV_STATUS = 'N'",
            [':dt' => $dateTime, ':intrv_mode' => $mode, ':chg_by' => $empCode, ':id' => (string)$interviewId], 'Could not save the interview.');
    } else {
        $interviewId = recruitmentFlowInsert('HR_INTRV_DATES', [
            'CAN_ID' => $application['CAN_ID'],
            'REQ_ID' => $application['REQ_ID'],
            'INTRV_STATUS' => 'N',
            'INTRV_MODE' => $mode,
            'CHG_BY' => $empCode,
        ], ['CHG_ON']);
        recruitmentExecute("UPDATE HR_INTRV_DATES SET INTRV_DATE = TO_DATE(:dt, 'YYYY-MM-DD HH24:MI') WHERE ID = :id",
            [':dt' => $dateTime, ':id' => (string)$interviewId], 'Could not save the interview.');
    }
    $current = recruitmentFlowApplication($application['ID']);
    if (($current['STATUS'] ?? '') !== 'I') {
        recruitmentFlowSetApplicationStatus($application, 'I', $userTaskId, $empCode);
    }
    return $interviewId;
}

function recruitmentFlowInterviewAction(array $task, array $context, $action, array $input, $empCode)
{
    $request = $context['request'];
    $application = $context['application'];
    $candidateName = recruitmentFlowCandidateName($context['candidate']);
    $userTaskId = (string)$task['ID'];
    $workflowTaskId = (int)$task['TASK_ID'];

    if ($workflowTaskId === RECRUITMENT_TASK_SCHEDULE_INTERVIEW) {
        if ($action === 'save') {
            recruitmentFlowSaveSchedule($application, $input, $userTaskId, $empCode);
            return ['Interview details saved.', null];
        }
        if ($action !== 'submit') {
            throw new RecruitmentRequestException('Invalid action.', 422);
        }
        $outcome = strtoupper(trim((string)($input['outcome'] ?? '')));
        if (!in_array($outcome, ['C', 'R'], true)) {
            throw new RecruitmentRequestException("Please select the candidate's response.", 422);
        }
        // Legacy conf_interview.php: once confirmed, the candidate cannot answer again.
        if (recruitmentFlowHasOpenTask(RECRUITMENT_TASK_INTERVIEW_CONFIRMATION, $application['ID'])
            || recruitmentFlowHasOpenTask(RECRUITMENT_TASK_ASSIGN_INTERVIEWER, $application['ID'])) {
            throw new RecruitmentRequestException('The candidate has already confirmed an interview for this request.', 409);
        }
        $interviewId = recruitmentFlowSaveSchedule($application, $input, $userTaskId, $empCode);
        recruitmentExecute("UPDATE HR_INTRV_DATES SET INTRV_STATUS = :status, CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE ID = :id AND INTRV_STATUS = 'N'",
            [':status' => $outcome, ':chg_by' => $empCode, ':id' => (string)$interviewId], 'Could not update the interview.');
        recruitmentCloseTask($userTaskId, $empCode);

        if ($outcome === 'C') {
            recruitmentFlowSetApplicationStatus($application, 'D', $userTaskId, $empCode);
            recruitmentFlowCreateTask('INT_CONF', $application['ID'], 'Confirmation Recd from ' . $candidateName,
                $empCode, $empCode, $request);
            return ['Interview confirmed. An Interview Confirmation task has been generated.', null];
        }
        recruitmentFlowSetApplicationStatus($application, 'U', $userTaskId, $empCode);
        recruitmentFlowCreateTask('SCHED_INT', $application['ID'], 'Reschedule Interview for ' . $candidateName,
            $empCode, $empCode, $request);
        return ['Interview marked for rescheduling. A new Schedule Interview task has been generated.', null];
    }

    $confirmed = recruitmentFlowLatestInterview($application, 'C');
    if (empty($confirmed['ID'])) {
        throw new RecruitmentRequestException('No confirmed interview was found for this candidate.', 409);
    }

    if ($workflowTaskId === RECRUITMENT_TASK_INTERVIEW_CONFIRMATION) {
        if ($action !== 'send') {
            throw new RecruitmentRequestException('Invalid action.', 422);
        }
        if (trim((string)($request['AUTH_BY'] ?? '')) === '') {
            throw new RecruitmentRequestException('The request has no authorizing HOD to send the interview to.', 409);
        }
        recruitmentCloseTask($userTaskId, $empCode);
        recruitmentFlowCreateTask('ASS_INT', $application['ID'],
            'Assign Interviewer and Recommendor for ' . $candidateName . ' REQ ID #' . $request['ID'],
            $request['AUTH_BY'], $empCode, $request);
        return ['Sent to HOD for assigning the interviewers.', null];
    }

    // Assign Interviewer and Recommender (11).
    if ($action !== 'assign') {
        throw new RecruitmentRequestException('Invalid action.', 422);
    }
    $active = array_column(recruitmentFlowActiveEmployees(), 'label', 'value');
    $interviewers = array_values(array_unique(array_filter(array_map(static fn($code) => trim((string)$code),
        (array)($input['interviewers'] ?? [])))));
    $recommender = trim((string)($input['recommender'] ?? ''));
    if (!$interviewers) {
        throw new RecruitmentRequestException('Please select at least one interviewer.', 422);
    }
    if ($recommender === '') {
        throw new RecruitmentRequestException('Please select the recommender.', 422);
    }
    foreach (array_merge($interviewers, [$recommender]) as $code) {
        if (!isset($active[$code])) {
            throw new RecruitmentRequestException('Please select active employees only.', 422);
        }
    }
    $existing = recruitmentRow("SELECT COUNT(*) AS CNT FROM HR_INTRV_EMPLOYEE WHERE INTRV_ID = :id", [':id' => (string)$confirmed['ID']]);
    if ((int)($existing['CNT'] ?? 0) > 0) {
        throw new RecruitmentRequestException('Interviewers have already been assigned for this interview.', 409);
    }

    $interviewId = (string)$confirmed['ID'];
    $assign = static function ($code, $type) use ($interviewId, $application, $empCode) {
        recruitmentFlowInsert('HR_INTRV_EMPLOYEE', [
            'INTRV_ID' => $interviewId,
            'CAN_ID' => $application['CAN_ID'],
            'REQ_ID' => $application['REQ_ID'],
            'INTRVER_CODE' => $code,
            'INTRVER_TYPE' => $type,
            'CHG_BY' => $empCode,
        ], ['CHG_ON']);
    };
    foreach ($interviewers as $code) {
        $assign($code, 'I');
        recruitmentFlowCreateTask('SESS_INT', $interviewId,
            'REQ_ID#' . $request['ID'] . ' Interview Feedback for ' . $candidateName, $code, $empCode, $request);
    }
    $assign($recommender, 'R');
    recruitmentFlowSetApplicationStatus($application, 'S', $userTaskId, $empCode);
    recruitmentFlowCreateTask('SESS_INT_RECO', $interviewId,
        'REQ_ID#' . $request['ID'] . ' Recommendors Feedback for ' . $candidateName, $recommender, $empCode, $request);
    recruitmentCloseTask($userTaskId, $empCode);
    return ['Interviewers and recommender assigned. Feedback tasks have been generated.', null];
}
