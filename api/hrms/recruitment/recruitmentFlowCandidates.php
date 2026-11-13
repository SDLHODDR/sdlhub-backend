<?php
/*
 * Send Suitable Candidates (6) and Shortlist Candidates (7).
 * Legacy send_suitable_cand.php / short_can.php and recruitment_sv.php?sendForSuitable / ShortlistCan.
 * HR_CAN_APPLICATION_DETAILS has no SUTABLE_TASK_ID column, so Shortlist works on every
 * candidate sent for the request.
 */

/** Legacy suitability score: 25 points per screening criterion met (latest screening remark). */
const RECRUITMENT_SUITABILITY_SQL = "SELECT CAN_ID,
        25 * (CASE WHEN EXPECTED_SAL BETWEEN 2 AND 5 THEN 1 ELSE 0 END
            + CASE WHEN RELAVENT_EXP BETWEEN 2 AND 10 THEN 1 ELSE 0 END
            + CASE WHEN CURRENT_AGE BETWEEN 25 AND 40 THEN 1 ELSE 0 END
            + CASE WHEN CURRENT_QUALI BETWEEN 10 AND 25 THEN 1 ELSE 0 END) AS SCORE
    FROM (SELECT S.*, ROW_NUMBER() OVER (PARTITION BY S.CAN_ID ORDER BY S.ID DESC) AS RN FROM HR_CAN_SCREENREMARK S)
    WHERE RN = 1";

/** Candidates for task 6: applicants of the request first, then verified ('V') candidates not yet in it. */
function recruitmentFlowSuitablePool($requestId)
{
    return multiRec("SELECT P.CAN_ID, P.CND_NAME, P.CND_EMAIL, P.CND_MOBILE, P.DOC_PATH, P.APP_STATUS, P.ORDR,
            NVL(SC.SCORE, 0) AS SCORE
        FROM (
            SELECT CI.ID AS CAN_ID, CI.CND_NAME, CI.CND_EMAIL, CI.CND_MOBILE, CI.DOC_PATH, CAD.STATUS AS APP_STATUS, 1 AS ORDR
            FROM HR_CAN_APPLICATION_DETAILS CAD
            INNER JOIN HR_CANDIDATE_INFO CI ON CI.ID = CAD.CAN_ID
            WHERE CAD.REQ_ID = :req_id
            UNION ALL
            SELECT CI.ID, CI.CND_NAME, CI.CND_EMAIL, CI.CND_MOBILE, CI.DOC_PATH, NULL, 2
            FROM HR_CANDIDATE_INFO CI
            WHERE CI.STATUS = 'V'
              AND NOT EXISTS (SELECT 1 FROM HR_CAN_APPLICATION_DETAILS CAD WHERE CAD.REQ_ID = :req_id AND CAD.CAN_ID = CI.ID)
        ) P
        LEFT JOIN (" . RECRUITMENT_SUITABILITY_SQL . ") SC ON SC.CAN_ID = TO_CHAR(P.CAN_ID)
        ORDER BY P.ORDR, UPPER(P.CND_NAME)", [':req_id' => (string)$requestId]) ?: [];
}

/** Applications for task 7, each with whether it can still be decided. */
function recruitmentFlowShortlistRows($requestId)
{
    return multiRec("SELECT A.ID AS APP_ID, A.CAN_ID, A.REQ_ID, A.STATUS, TO_CHAR(A.CHG_ON, 'DD-Mon-YYYY') AS LAST_UPDT,
            B.CND_NAME, B.CND_EMAIL, B.CND_MOBILE, B.DOC_PATH,
            (SELECT COUNT(*) FROM HR_USER_TASKS T WHERE T.TRAN_CODE = TO_CHAR(A.ID) AND T.TASK_ID = :sched_task
                AND T.STATUS IS NOT NULL) AS SCHED_CNT
        FROM HR_CAN_APPLICATION_DETAILS A
        INNER JOIN HR_CANDIDATE_INFO B ON B.ID = A.CAN_ID
        WHERE A.REQ_ID = :req_id AND A.STATUS IS NOT NULL
        ORDER BY UPPER(B.CND_NAME)", [
        ':req_id' => (string)$requestId,
        ':sched_task' => (string)RECRUITMENT_TASK_SCHEDULE_INTERVIEW,
    ]) ?: [];
}

function recruitmentFlowShortlistEditable(array $row)
{
    return (int)$row['SCHED_CNT'] === 0 && in_array($row['STATUS'], ['N', 'A', 'R'], true);
}

function recruitmentFlowLoadCandidatesTask(array $task, array $context)
{
    $requestId = $context['request']['ID'];
    if ((int)$task['TASK_ID'] === RECRUITMENT_TASK_SEND_SUITABLE) {
        return ['candidates' => array_map(static fn($row) => [
            'id' => $row['CAN_ID'],
            'name' => ucwords(strtolower(trim((string)$row['CND_NAME']))),
            'email' => $row['CND_EMAIL'] ?? '',
            'mobile' => $row['CND_MOBILE'] ?? '',
            'docPath' => $row['DOC_PATH'] ?? '',
            'score' => (int)$row['SCORE'],
            'applicationStatus' => $row['APP_STATUS'] ?? '',
            'applied' => (int)$row['ORDR'] === 1,
        ], recruitmentFlowSuitablePool($requestId))];
    }

    return ['candidates' => array_map(static fn($row) => [
        'id' => $row['CAN_ID'],
        'applicationId' => $row['APP_ID'],
        'name' => ucwords(strtolower(trim((string)$row['CND_NAME']))),
        'email' => $row['CND_EMAIL'] ?? '',
        'mobile' => $row['CND_MOBILE'] ?? '',
        'docPath' => $row['DOC_PATH'] ?? '',
        'updatedOn' => $row['LAST_UPDT'] ?? '',
        'status' => $row['STATUS'] ?? '',
        'editable' => recruitmentFlowShortlistEditable($row),
    ], recruitmentFlowShortlistRows($requestId))];
}

function recruitmentFlowCandidatesAction(array $task, array $context, $action, array $input, $empCode)
{
    $request = $context['request'];
    $requestId = (string)$request['ID'];
    $userTaskId = (string)$task['ID'];

    if ((int)$task['TASK_ID'] === RECRUITMENT_TASK_SEND_SUITABLE) {
        if ($action !== 'send') {
            throw new RecruitmentRequestException('Invalid action.', 422);
        }
        $selected = array_values(array_unique(array_map('intval', (array)($input['candidate_ids'] ?? []))));
        $selected = array_filter($selected, static fn($id) => $id > 0);
        if (!$selected) {
            throw new RecruitmentRequestException('Please select at least one candidate.', 422);
        }
        $pool = [];
        foreach (recruitmentFlowSuitablePool($requestId) as $row) {
            $pool[(int)$row['CAN_ID']] = $row;
        }
        foreach ($selected as $canId) {
            if (!isset($pool[$canId])) {
                throw new RecruitmentRequestException('A selected candidate is not available for this request.', 422);
            }
            if (in_array($pool[$canId]['APP_STATUS'], ['A', 'R'], true)) {
                throw new RecruitmentRequestException('Shortlisted or rejected candidates cannot be sent again.', 409);
            }
        }

        foreach ($selected as $canId) {
            $application = recruitmentFlowApplicationFor($canId, $requestId);
            if (empty($application['ID'])) {
                $applicationId = recruitmentFlowInsert('HR_CAN_APPLICATION_DETAILS', [
                    'CAN_ID' => $canId,
                    'REQ_ID' => $requestId,
                    'DESIG_ID' => $request['DESIG_ID'],
                    'DEPT_ID' => $request['DEPT_ID'],
                    'POOL_STATUS' => 'N',
                    'ORG_ID' => $request['ORG_ID'],
                    'STATUS' => 'N',
                    'CHG_BY' => $empCode,
                ], ['APL_DATE', 'CHG_ON']);
                recruitmentFlowLog(['ID' => $applicationId, 'CAN_ID' => $canId, 'REQ_ID' => $requestId], 'N', $userTaskId, $empCode);
            } else {
                recruitmentFlowSetApplicationStatus($application, 'N', $userTaskId, $empCode);
            }
        }

        // Legacy creates one Shortlist task per send; one open task already lists every sent candidate.
        if (!recruitmentFlowHasOpenTask(RECRUITMENT_TASK_SHORTLIST, $requestId)) {
            recruitmentFlowCreateTask('SHORT_CAN', $requestId, 'Shortlist Candidates for Job ID : #' . $requestId, null, $empCode, $request);
        }
        return [count($selected) . ' candidate(s) sent for suitability check.', null];
    }

    if (!in_array($action, ['save', 'authorize'], true)) {
        throw new RecruitmentRequestException('Invalid action.', 422);
    }
    $decisions = (array)($input['decisions'] ?? []);
    $rows = recruitmentFlowShortlistRows($requestId);
    $applied = 0;
    foreach ($rows as $row) {
        if (!recruitmentFlowShortlistEditable($row)) {
            continue;
        }
        $decision = strtoupper(trim((string)($decisions[$row['CAN_ID']] ?? '')));
        if ($decision === '') {
            continue;
        }
        if (!in_array($decision, ['A', 'R'], true)) {
            throw new RecruitmentRequestException('Please choose Shortlist or Reject.', 422);
        }
        $application = ['ID' => $row['APP_ID'], 'CAN_ID' => $row['CAN_ID'], 'REQ_ID' => $row['REQ_ID']];
        if ($decision !== $row['STATUS']) {
            recruitmentFlowSetApplicationStatus($application, $decision, $userTaskId, $empCode);
        }
        $applied++;
    }

    if ($action === 'save') {
        if ($applied === 0) {
            throw new RecruitmentRequestException('Please choose Shortlist or Reject for at least one candidate.', 422);
        }
        return ['Candidate status saved.', null];
    }

    // Authorize: a Schedule Interview task for every shortlisted candidate, then close.
    $created = 0;
    $decided = 0;
    foreach (recruitmentFlowShortlistRows($requestId) as $row) {
        if (!recruitmentFlowShortlistEditable($row)) {
            continue;
        }
        if ($row['STATUS'] === 'R') {
            $decided++;
        }
        if ($row['STATUS'] !== 'A') {
            continue;
        }
        $decided++;
        if (!recruitmentFlowHasOpenTask(RECRUITMENT_TASK_SCHEDULE_INTERVIEW, $row['APP_ID'])) {
            recruitmentFlowCreateTask('SCHED_INT', $row['APP_ID'],
                'REQ_ID#' . $requestId . ' Schedule Interview for ' . ucwords(strtolower(trim((string)$row['CND_NAME']))),
                $request['ASSIGNED_TO'], $empCode, $request);
            $created++;
        }
    }
    if ($decided === 0) {
        throw new RecruitmentRequestException('Please shortlist or reject at least one candidate.', 422);
    }
    recruitmentCloseTask($userTaskId, $empCode);
    return [$created > 0
        ? "Candidates sent. {$created} Schedule Interview task(s) generated for the recruiter."
        : 'Candidates sent.', null];
}
