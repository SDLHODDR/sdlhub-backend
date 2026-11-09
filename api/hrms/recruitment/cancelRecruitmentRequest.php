<?php
/* POST { id }. Only the user's own request that has not been sent yet. Legacy recruitment_sv.php?cancelReq. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunAction(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_REQUEST]);
    $input = recruitmentInput();
    $updated = recruitmentExecute("UPDATE HR_RECRUITMENT SET STATUS = 'X', CHG_ON = SYSDATE, CHG_BY = :emp
        WHERE ID = :id AND ENTER_BY_EMP = :emp AND STATUS = 'N'",
        [':id' => (string)(int)($input['id'] ?? 0), ':emp' => $empCode], 'Could not cancel the recruitment request.');
    if ($updated !== 1) {
        throw new RecruitmentRequestException('This recruitment request can no longer be cancelled.', 409);
    }
    return ['Recruitment request cancelled.', null];
}, 'cancelRecruitmentRequest.php');
