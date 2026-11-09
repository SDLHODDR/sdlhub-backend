<?php
/* GET ?can_id=  -> follow-ups of a candidate, with the mode / type / employee options.
 * GET ?req_id=  -> candidate-search follow-ups of a recruitment request.
 * Legacy candidate_info_sv.php?showFollowData. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES, RECRUITMENT_PAGE_PENDING]);
    session_write_close();

    $reqId = (int)($_GET['req_id'] ?? 0);
    if ($reqId > 0 && empty($_GET['can_id'])) {
        $rows = multiRec("SELECT TO_CHAR(ASON_DATE, 'DD-Mon-YYYY') AS ASON_DATE, CAN_NAME, CAN_EMAIL, CAN_MOBILE, REMARKS
            FROM HR_CAN_FOLLOWUP WHERE REQ_ID = :req_id AND CAN_ID IS NULL ORDER BY ID DESC", [':req_id' => (string)$reqId]) ?: [];
        return ['Follow-ups fetched successfully.', [
            'history' => array_map(static fn($row) => [
                'date' => $row['ASON_DATE'] ?? '', 'name' => $row['CAN_NAME'] ?? '', 'email' => $row['CAN_EMAIL'] ?? '',
                'mobile' => $row['CAN_MOBILE'] ?? '', 'remarks' => $row['REMARKS'] ?? '',
            ], $rows),
        ]];
    }

    $candidate = recruitmentCandidate($_GET['can_id'] ?? 0);
    $rows = multiRec("SELECT TO_CHAR(A.ASON_DATE, 'DD-Mon-YYYY') AS ASON_DATE, A.REMARKS, M.FMOD_DESC,
            TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' ')) AS FOLLOWED_BY
        FROM HR_CAN_FOLLOWUP A
        LEFT JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = A.FOLOUP_BY
        LEFT JOIN HR_FOLLOWUP_MODE M ON M.FMOD_ID = A.FMOD_ID
        WHERE A.CAN_ID = :id ORDER BY A.ID DESC", [':id' => (string)$candidate['ID']]) ?: [];
    $modes = multiRec("SELECT FMOD_ID, FMOD_DESC FROM HR_FOLLOWUP_MODE ORDER BY FMOD_ID") ?: [];
    $types = multiRec("SELECT FTYP_ID, FTYP_DESC FROM HR_FOLLOWUP_TYPES ORDER BY DISP_SEQ, FTYP_ID") ?: [];
    $employees = multiRec("SELECT EMP_CODE, TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) AS NAME
        FROM HR_EMPLOYEE_INFO WHERE STATUS = 'A' ORDER BY 2") ?: [];

    return ['Follow-ups fetched successfully.', [
        'currentEmployee' => $empCode,
        'history' => array_map(static fn($row) => [
            'date' => $row['ASON_DATE'] ?? '', 'by' => $row['FOLLOWED_BY'] ?? '',
            'mode' => $row['FMOD_DESC'] ?? '', 'remarks' => $row['REMARKS'] ?? '',
        ], $rows),
        'modes' => array_map(static fn($row) => ['value' => $row['FMOD_ID'], 'label' => $row['FMOD_DESC']], $modes),
        'types' => array_map(static fn($row) => ['value' => $row['FTYP_ID'], 'label' => $row['FTYP_DESC']], $types),
        'employees' => array_map(static fn($row) => ['value' => $row['EMP_CODE'], 'label' => $row['NAME']], $employees),
    ]];
}, 'getFollowUps.php');
