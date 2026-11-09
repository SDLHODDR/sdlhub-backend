<?php
/* POST { can_id, expected_salary, current_age, relevant_experience, qualification, screen_remark }
 * "Mark As Verified". Legacy candidate_info_sv.php?saveData. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunAction(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES]);
    $input = recruitmentInput();
    $candidate = recruitmentCandidate($input['can_id'] ?? 0);

    $salary = trim((string)($input['expected_salary'] ?? ''));
    $age = trim((string)($input['current_age'] ?? ''));
    $experience = trim((string)($input['relevant_experience'] ?? ''));
    $qualification = trim((string)($input['qualification'] ?? ''));
    $remark = trim((string)($input['screen_remark'] ?? ''));

    if (!ctype_digit($salary) || strlen($salary) > 12) {
        throw new RecruitmentRequestException('Expected salary must be a number.', 422);
    }
    if (!ctype_digit($age) || strlen($age) > 2) {
        throw new RecruitmentRequestException('Current age must be a number of up to 2 digits.', 422);
    }
    if (!ctype_digit($experience) || strlen($experience) > 2) {
        throw new RecruitmentRequestException('Relevant experience must be a number of up to 2 digits.', 422);
    }
    $level = recruitmentRow("SELECT EDU_LEVEL FROM HR_EDUCATION_LEVEL WHERE EDU_LEVEL = :edu_level",
        [':edu_level' => (string)(int)$qualification]);
    if (empty($level['EDU_LEVEL'])) {
        throw new RecruitmentRequestException('Current qualification is required.', 422);
    }
    if ($remark === '') {
        throw new RecruitmentRequestException('Screen remarks are required.', 422);
    }
    recruitmentAssertLength($remark, RECRUITMENT_REMARK_MAX, 'Screen remarks');

    $existing = recruitmentRow("SELECT COUNT(*) AS CNT FROM HR_CAN_SCREENREMARK WHERE CAN_ID = :id",
        [':id' => (string)$candidate['ID']]);
    if ((int)($existing['CNT'] ?? 0) > 0) {
        throw new RecruitmentRequestException('This candidate has already been verified.', 409);
    }

    recruitmentExecute("INSERT INTO HR_CAN_SCREENREMARK
            (CAN_ID, SCREEN_DATE, EXPECTED_SAL, CURRENT_AGE, RELAVENT_EXP, CURRENT_QUALI, SCREEN_REMARK, PROFILE_ACTIVE, CHG_ON, CHG_BY)
        VALUES (:id, SYSDATE, :salary, :age, :experience, :qualification, :remark, 'Y', SYSDATE, :changed_by)", [
        ':id' => (string)$candidate['ID'],
        ':salary' => $salary,
        ':age' => $age,
        ':experience' => $experience,
        ':qualification' => (string)(int)$qualification,
        ':remark' => $remark,
        ':changed_by' => $empCode,
    ], 'Could not save the screen remark.');
    recruitmentExecute("UPDATE HR_CANDIDATE_INFO SET STATUS = 'V' WHERE ID = :id",
        [':id' => (string)$candidate['ID']], 'Could not mark the candidate as verified.');

    return ['Candidate marked as verified.', null];
}, 'saveScreenRemark.php');
