<?php
/* GET -> open requests assigned to the user as recruiter, with their candidates.
 * Legacy pending_recruitments.php, which also listed closed requests. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_PENDING]);
    session_write_close();
    $binds = [':emp' => $empCode];

    $requests = multiRec("SELECT HR.ID, HR.REC_TITLE, GET_DESIGN_NAME(HR.DESIG_ID) AS DESIGNATION,
            TO_CHAR(HR.REQ_BY_DATE, 'DD-Mon-YYYY') AS REQ_BY_DATE, HOL.GEO_DESC
        FROM HR_RECRUITMENT HR
        INNER JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
        WHERE HR.ASSIGNED_TO = :emp AND HR.STATUS <> 'C'
        ORDER BY HR.ID DESC", $binds) ?: [];
    $candidates = recruitmentRequestCandidates("SELECT ID FROM HR_RECRUITMENT WHERE ASSIGNED_TO = :emp AND STATUS <> 'C'", $binds, false);

    return ['Pending recruitments fetched successfully.', [
        'requests' => array_map(static fn($row) => [
            'id' => $row['ID'],
            'title' => $row['REC_TITLE'] ?? '',
            'location' => $row['GEO_DESC'] ?? '',
            'designation' => $row['DESIGNATION'] ?? '',
            'tillDate' => $row['REQ_BY_DATE'] ?? '',
            'candidates' => $candidates[$row['ID']] ?? [],
        ], $requests),
    ]];
}, 'getPendingRecruitments.php');
