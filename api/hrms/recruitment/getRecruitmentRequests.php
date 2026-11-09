<?php
/* GET        -> { requests: my requests with their candidates, organograms: dropdown options }
 * GET ?id=   -> { request } for editing. Legacy recruitment_req.php. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_REQUEST]);
    loadHrmsAccess($empCode);
    session_write_close();

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $row = recruitmentRow("SELECT ID, ORG_ID, ORG_LOC_ID, REMARKS, STATUS, TO_CHAR(REQ_BY_DATE, 'YYYY-MM-DD') AS REQ_BY_DATE
            FROM HR_RECRUITMENT WHERE ID = :id AND ENTER_BY_EMP = :emp", [':id' => (string)$id, ':emp' => $empCode]);
        if (empty($row['ID'])) {
            throw new RecruitmentRequestException('Recruitment request not found.', 404);
        }
        return ['Recruitment request fetched successfully.', ['request' => [
            'id' => $row['ID'],
            'orgId' => $row['ORG_ID'] ?? '',
            'locationId' => $row['ORG_LOC_ID'] ?? '',
            'fillByDate' => $row['REQ_BY_DATE'] ?? '',
            'remarks' => $row['REMARKS'] ?? '',
            'status' => $row['STATUS'] ?? '',
        ]]];
    }

    if (!hrmsHasScope()) {
        return ['No company / division / department access is configured for your profile.', ['requests' => [], 'organograms' => []]];
    }
    $scope = recruitmentOrgScopeSql('O');

    $organograms = multiRec("SELECT O.ID,
            GET_SHCOMP_NAME(O.COMPANY) || ' - ' || GET_DIVISION_NAME(O.DIVSN_ID) || ' - ' || GET_DEPT_NAME(O.DEPT_ID) || ' - ' || GET_DESIGN_NAME(O.DESI_ID) AS LABEL
        FROM HR_ORGANOGRAM O
        WHERE O.POSI_COUNT != O.FILL_COUNT AND {$scope}
        ORDER BY O.ID DESC") ?: [];

    $requestFilter = "SELECT HR.ID FROM HR_RECRUITMENT HR
        INNER JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
        INNER JOIN HR_ORGANOGRAM O ON O.ID = HOL.ORG_ID
        WHERE HR.ENTER_BY_EMP = :emp AND {$scope}";
    $binds = [':emp' => $empCode];

    $requests = multiRec("SELECT HR.ID, HR.REC_TITLE, HR.STATUS, TO_CHAR(HR.REQ_DATE, 'DD-Mon-YYYY') AS REQ_DATE,
            GET_DESIGN_NAME(HR.DESIG_ID) AS DESIGNATION, TO_CHAR(HR.REQ_BY_DATE, 'DD-Mon-YYYY') AS REQ_BY_DATE, HOL.GEO_DESC
        FROM HR_RECRUITMENT HR
        INNER JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
        WHERE HR.ID IN ({$requestFilter})
        ORDER BY HR.ID DESC", $binds) ?: [];
    $candidates = recruitmentRequestCandidates($requestFilter, $binds, true);

    return ['Recruitment requests fetched successfully.', [
        'organograms' => array_map(static fn($row) => ['value' => $row['ID'], 'label' => $row['LABEL']], $organograms),
        'requests' => array_map(static fn($row) => [
            'id' => $row['ID'],
            'raisedOn' => $row['REQ_DATE'] ?? '',
            'title' => $row['REC_TITLE'] ?? '',
            'location' => $row['GEO_DESC'] ?? '',
            'designation' => $row['DESIGNATION'] ?? '',
            'tillDate' => $row['REQ_BY_DATE'] ?? '',
            'status' => $row['STATUS'] ?? '',
            'candidates' => $candidates[$row['ID']] ?? [],
        ], $requests),
    ]];
}, 'getRecruitmentRequests.php');
