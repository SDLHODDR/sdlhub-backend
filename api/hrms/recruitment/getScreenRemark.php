<?php
/* GET ?id= -> saved screen remark (if any) and the qualification options. Legacy candidate_info_sv.php?showData. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES]);
    session_write_close();
    $candidate = recruitmentCandidate($_GET['id'] ?? 0);
    $remark = recruitmentRow("SELECT * FROM (
            SELECT EXPECTED_SAL, CURRENT_AGE, RELAVENT_EXP, CURRENT_QUALI, SCREEN_REMARK
            FROM HR_CAN_SCREENREMARK WHERE CAN_ID = :id ORDER BY ID DESC)
        WHERE ROWNUM = 1", [':id' => (string)$candidate['ID']]);
    $levels = multiRec("SELECT EDU_LEVEL, EDU_DESC FROM HR_EDUCATION_LEVEL ORDER BY EDU_LEVEL") ?: [];

    return ['Screen remark fetched successfully.', [
        'remark' => $remark ? [
            'expectedSalary' => $remark['EXPECTED_SAL'] ?? '',
            'currentAge' => $remark['CURRENT_AGE'] ?? '',
            'relevantExperience' => $remark['RELAVENT_EXP'] ?? '',
            'qualification' => $remark['CURRENT_QUALI'] ?? '',
            'screenRemark' => trim((string)($remark['SCREEN_REMARK'] ?? '')),
        ] : null,
        'qualifications' => array_map(static fn($row) => ['value' => $row['EDU_LEVEL'], 'label' => $row['EDU_DESC']], $levels),
    ]];
}, 'getScreenRemark.php');
