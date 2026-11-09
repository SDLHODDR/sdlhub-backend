<?php
/* POST { can_id }. Offered after 5 follow-ups; cannot be undone from the screen.
 * Legacy candidate_info_sv.php?BlockCan. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunAction(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES, RECRUITMENT_PAGE_PENDING]);
    $input = recruitmentInput();
    $candidate = recruitmentCandidate($input['can_id'] ?? 0);
    $bind = [':id' => (string)$candidate['ID']];

    if (($candidate['STATUS'] ?? '') === 'B') {
        throw new RecruitmentRequestException('This candidate is already blocked.', 409);
    }
    $followUps = recruitmentRow("SELECT COUNT(*) AS CNT FROM HR_CAN_FOLLOWUP WHERE CAN_ID = :id", $bind);
    if ((int)($followUps['CNT'] ?? 0) < RECRUITMENT_BLOCK_AFTER_FOLLOWUPS) {
        throw new RecruitmentRequestException('A candidate can be blocked only after ' . RECRUITMENT_BLOCK_AFTER_FOLLOWUPS . ' follow-ups.', 409);
    }

    recruitmentExecute("INSERT INTO HR_CAN_APP_DET_LOG (CAN_APP_DET_ID, CAN_ID, REQ_ID, STATUS, CHG_ON, CHG_BY)
        SELECT ID, CAN_ID, REQ_ID, 'B', SYSDATE, :changed_by FROM HR_CAN_APPLICATION_DETAILS WHERE CAN_ID = :id",
        $bind + [':changed_by' => $empCode], 'Could not record the block.');
    recruitmentExecute("UPDATE HR_CAN_APPLICATION_DETAILS SET STATUS = 'B' WHERE CAN_ID = :id", $bind, 'Could not block the applications.');
    recruitmentExecute("UPDATE HR_CANDIDATE_INFO SET STATUS = 'B' WHERE ID = :id", $bind, 'Could not block the candidate.');

    return ['Candidate is blocked.', null];
}, 'blockCandidate.php');
