<?php
/* GET ?org_id=&rec_id= -> position counts and open locations of an organogram. Legacy populate_org_loc. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_REQUEST]);
    loadHrmsAccess($empCode);
    session_write_close();

    $orgId = (int)($_GET['org_id'] ?? 0);
    $recId = (int)($_GET['rec_id'] ?? 0);
    if ($orgId <= 0) {
        throw new RecruitmentRequestException('A valid organogram is required.', 422);
    }
    $org = recruitmentRow("SELECT O.POSI_COUNT, O.FILL_COUNT,
            (SELECT COUNT(*) FROM HR_RECRUITMENT R WHERE R.ORG_ID = O.ID AND R.STATUS IN ('I', 'A')) AS IN_PROGRESS
        FROM HR_ORGANOGRAM O WHERE O.ID = :id AND " . recruitmentOrgScopeSql('O'), [':id' => (string)$orgId]);
    if ($org === []) {
        throw new RecruitmentRequestException('This organogram is not in your access.', 403);
    }

    return ['Locations fetched successfully.', [
        'positionCount' => $org['POSI_COUNT'] ?? '',
        'positionsOccupied' => $org['FILL_COUNT'] ?? '',
        'positionsInProgress' => $org['IN_PROGRESS'] ?? '',
        'locations' => array_map(static fn($row) => ['value' => $row['ID'], 'label' => $row['LABEL']],
            recruitmentOpenLocations($orgId, $recId)),
    ]];
}, 'getOrganogramLocations.php');
