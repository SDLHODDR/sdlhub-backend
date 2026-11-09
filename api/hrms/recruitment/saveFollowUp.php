<?php
/* POST candidate follow-up: { can_id, req_id?, ason_date, foloup_by, fmod_id, ftyp_id, remarks }
 * POST request follow-up:   { req_id, ason_date, can_name, can_mobile, can_email, remarks }
 * Legacy candidate_info_sv.php?saveFollowData (which saved every follow-up as type 1 whatever was chosen). */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunAction(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES, RECRUITMENT_PAGE_PENDING]);
    $input = recruitmentInput();

    $canId = (int)($input['can_id'] ?? 0);
    $reqId = (int)($input['req_id'] ?? 0);
    $asOn = trim((string)($input['ason_date'] ?? ''));
    $remarks = trim((string)($input['remarks'] ?? ''));
    recruitmentAssertDate($asOn, 'Date');
    if ($remarks === '') {
        throw new RecruitmentRequestException('Remarks are required.', 422);
    }
    recruitmentAssertLength($remarks, RECRUITMENT_REMARK_MAX, 'Remarks');

    $binds = [
        ':req_id' => $reqId > 0 ? (string)$reqId : '',
        ':ason' => $asOn,
        ':remarks' => $remarks,
        ':changed_by' => $empCode,
    ];

    if ($canId > 0) {
        $candidate = recruitmentCandidate($canId);
        $followedBy = trim((string)($input['foloup_by'] ?? ''));
        $employee = recruitmentRow("SELECT EMP_CODE FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code AND STATUS = 'A'", [':code' => $followedBy]);
        if (empty($employee['EMP_CODE'])) {
            throw new RecruitmentRequestException('Follow up by is required.', 422);
        }
        $mode = recruitmentRow("SELECT FMOD_ID, FMOD_TYPE FROM HR_FOLLOWUP_MODE WHERE FMOD_ID = :id",
            [':id' => (string)(int)($input['fmod_id'] ?? 0)]);
        if (empty($mode['FMOD_ID'])) {
            throw new RecruitmentRequestException('Follow up mode is required.', 422);
        }
        $type = recruitmentRow("SELECT FTYP_ID, FTYP_TYPE FROM HR_FOLLOWUP_TYPES WHERE FTYP_ID = :id",
            [':id' => (string)(int)($input['ftyp_id'] ?? 0)]);
        if (empty($type['FTYP_ID'])) {
            throw new RecruitmentRequestException('Follow up type is required.', 422);
        }
        $binds += [
            ':can_id' => (string)$candidate['ID'],
            ':followed_by' => $followedBy,
            ':ftyp_id' => $type['FTYP_ID'],
            ':ftyp_type' => $type['FTYP_TYPE'],
            ':fmod_id' => $mode['FMOD_ID'],
            ':fmod_type' => $mode['FMOD_TYPE'],
            ':can_name' => mb_strcut((string)$candidate['CND_NAME'], 0, 50, 'UTF-8'),
            ':can_mobile' => mb_strcut((string)$candidate['CND_MOBILE'], 0, 50, 'UTF-8'),
            ':can_email' => mb_strcut((string)$candidate['CND_EMAIL'], 0, 50, 'UTF-8'),
        ];
    } elseif ($reqId > 0) {
        // Follow-up on a request is limited to its recruiter (the Pending Recruitment list only shows their own).
        $request = recruitmentRow("SELECT ID FROM HR_RECRUITMENT WHERE ID = :id AND ASSIGNED_TO = :emp",
            [':id' => (string)$reqId, ':emp' => $empCode]);
        if (empty($request['ID'])) {
            throw new RecruitmentRequestException('This recruitment request is not assigned to you.', 403);
        }
        $name = trim((string)($input['can_name'] ?? ''));
        $mobile = trim((string)($input['can_mobile'] ?? ''));
        $email = trim((string)($input['can_email'] ?? ''));
        if ($name === '') {
            throw new RecruitmentRequestException('Candidate name is required.', 422);
        }
        if (!preg_match('/^[0-9+\- ]{7,20}$/', $mobile)) {
            throw new RecruitmentRequestException('Please enter a valid mobile number.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RecruitmentRequestException('Please enter a valid email address.', 422);
        }
        recruitmentAssertLength($name, 50, 'Candidate name');
        recruitmentAssertLength($email, 50, 'Email address');
        $binds += [
            ':can_id' => '',
            ':followed_by' => $empCode,
            // HR_FOLLOWUP_TYPES 1 = Candidate Search.
            ':ftyp_id' => '1',
            ':ftyp_type' => 'R',
            ':fmod_id' => '',
            ':fmod_type' => '',
            ':can_name' => $name,
            ':can_mobile' => $mobile,
            ':can_email' => $email,
        ];
    } else {
        throw new RecruitmentRequestException('A candidate or a recruitment request is required.', 422);
    }

    recruitmentExecute("INSERT INTO HR_CAN_FOLLOWUP
            (CAN_ID, REQ_ID, ASON_DATE, FOLOUP_BY, FTYP_ID, FTYP_TYPE, FMOD_ID, FMOD_TYPE, REMARKS,
             CAN_NAME, CAN_MOBILE, CAN_EMAIL, CHG_ON, CHG_BY)
        VALUES (:can_id, :req_id, TO_DATE(:ason, 'YYYY-MM-DD'), :followed_by, :ftyp_id, :ftyp_type, :fmod_id, :fmod_type, :remarks,
             :can_name, :can_mobile, :can_email, SYSDATE, :changed_by)", $binds, 'Could not save the follow-up.');

    return ['Follow-up saved successfully.', null];
}, 'saveFollowUp.php');
