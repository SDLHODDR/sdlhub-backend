<?php
/* POST { id?, action: 'save'|'send', org_id, location_id, fill_by_date (YYYY-MM-DD), remarks }
 * Save keeps the request as 'N'; send also sets 'T' and creates the Recruitment Request task (2).
 * Legacy recruitment_sv.php?saveReq / sendReqData. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunAction(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_REQUEST]);
    loadHrmsAccess($empCode);
    $input = recruitmentInput();

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $id = (int)($input['id'] ?? 0);
    $orgId = (int)($input['org_id'] ?? 0);
    $locationId = (int)($input['location_id'] ?? 0);
    $fillBy = trim((string)($input['fill_by_date'] ?? ''));
    $remarks = trim((string)($input['remarks'] ?? ''));

    if (!in_array($action, ['save', 'send'], true)) {
        throw new RecruitmentRequestException('Invalid action.', 422);
    }
    if ($orgId <= 0) {
        throw new RecruitmentRequestException('Organogram is required.', 422);
    }
    if ($locationId <= 0) {
        throw new RecruitmentRequestException('Open location is required.', 422);
    }
    recruitmentAssertDate($fillBy, 'Fill by date');
    if ($fillBy < date('Y-m-d')) {
        throw new RecruitmentRequestException('Fill by date cannot be in the past.', 422);
    }
    if ($remarks === '') {
        throw new RecruitmentRequestException('Remarks are required.', 422);
    }
    recruitmentAssertLength($remarks, 500, 'Remarks');

    if ($id > 0) {
        $existing = recruitmentRow("SELECT ID, STATUS FROM HR_RECRUITMENT WHERE ID = :id AND ENTER_BY_EMP = :emp",
            [':id' => (string)$id, ':emp' => $empCode]);
        if (empty($existing['ID'])) {
            throw new RecruitmentRequestException('Recruitment request not found.', 404);
        }
        if ($existing['STATUS'] !== 'N') {
            throw new RecruitmentRequestException('This recruitment request has already been sent for authorization.', 409);
        }
    }

    $org = recruitmentRow("SELECT O.JD_ID, O.DEPT_ID, O.DESI_ID, O.DIVSN_ID, O.COMPANY, O.POSI_COUNT, O.FILL_COUNT,
            GET_SHCOMP_NAME(O.COMPANY) || '-' || HD.DIVSN_DESC || ' - ' || HDD.DEPT_DESC || '(' || O.ID || ')' AS TITLE,
            GET_DEPT_NAME(O.DEPT_ID) || '-' || GET_DESIGN_NAME(O.DESI_ID) AS POSITION,
            J.DESCR, J.SH_DESC
        FROM HR_ORGANOGRAM O
        INNER JOIN HR_DIVISIONS HD ON HD.DIVSN_ID = O.DIVSN_ID
        INNER JOIN HR_DEPARTMENT HDD ON HDD.DEPT_ID = O.DEPT_ID
        LEFT JOIN HR_JD J ON J.ID = O.JD_ID
        WHERE O.ID = :id AND " . recruitmentOrgScopeSql('O'), [':id' => (string)$orgId]);
    if ($org === []) {
        throw new RecruitmentRequestException('This organogram is not in your access.', 403);
    }
    if (!hrmsHasScope()) {
        throw new RecruitmentRequestException('No company / division / department access is configured for your profile.', 403);
    }
    $openIds = array_column(recruitmentOpenLocations($orgId, $id), 'ID');
    if (!in_array((string)$locationId, array_map('strval', $openIds), true)) {
        throw new RecruitmentRequestException('This location is no longer open. Please select another.', 409);
    }

    $binds = [
        ':emp' => $empCode,
        ':org_id' => (string)$orgId,
        ':location_id' => (string)$locationId,
        ':posi_count' => (string)($org['POSI_COUNT'] ?? ''),
        ':fill_count' => (string)($org['FILL_COUNT'] ?? ''),
        ':fill_by' => $fillBy,
        ':remarks' => $remarks,
        ':title' => mb_strcut((string)$org['TITLE'], 0, 50, 'UTF-8'),
        ':jd_id' => (string)($org['JD_ID'] ?? ''),
        ':dept_id' => (string)($org['DEPT_ID'] ?? ''),
        ':desig_id' => (string)($org['DESI_ID'] ?? ''),
        ':divsn_id' => (string)($org['DIVSN_ID'] ?? ''),
        ':descr' => (string)($org['DESCR'] ?? ''),
        ':just_descr' => (string)($org['SH_DESC'] ?? ''),
    ];
    // Exactly one position per request (legacy REQ_COUNT = 1).
    if ($id > 0) {
        recruitmentExecute("UPDATE HR_RECRUITMENT SET REQ_DATE = SYSDATE, ENTER_BY_EMP = :emp, ORG_ID = :org_id, ORG_LOC_ID = :location_id,
                POSI_COUNT = :posi_count, FILL_COUNT = :fill_count, REQ_COUNT = 1, REQ_BY_DATE = TO_DATE(:fill_by, 'YYYY-MM-DD'),
                REMARKS = :remarks, REC_TITLE = :title, JD_ID = :jd_id, DEPT_ID = :dept_id, DESIG_ID = :desig_id, DIVSN_ID = :divsn_id,
                DESCR = :descr, JUST_DESCR = :just_descr, CHG_ON = SYSDATE, CHG_BY = :emp
            WHERE ID = :id AND STATUS = 'N'", $binds + [':id' => (string)$id], 'Could not save the recruitment request.');
    } else {
        global $sql___func___con;
        $stmt = oci_parse($sql___func___con, "INSERT INTO HR_RECRUITMENT
                (REQ_DATE, ENTER_BY_EMP, ORG_ID, ORG_LOC_ID, POSI_COUNT, FILL_COUNT, REQ_COUNT, REQ_BY_DATE, REMARKS,
                 REC_TITLE, JD_ID, DEPT_ID, DESIG_ID, DIVSN_ID, DESCR, JUST_DESCR, STATUS, CHG_ON, CHG_BY)
            VALUES (SYSDATE, :emp, :org_id, :location_id, :posi_count, :fill_count, 1, TO_DATE(:fill_by, 'YYYY-MM-DD'), :remarks,
                 :title, :jd_id, :dept_id, :desig_id, :divsn_id, :descr, :just_descr, 'N', SYSDATE, :emp)
            RETURNING ID INTO :new_id");
        foreach ($binds as $key => $value) {
            $binds[$key] = $value;
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }
        $newId = null;
        oci_bind_by_name($stmt, ':new_id', $newId, 40);
        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT) || !$newId) {
            logOracleError(oci_error($stmt), 'saveRecruitmentRequest insert');
            throw new RuntimeException('Could not save the recruitment request.');
        }
        oci_free_statement($stmt);
        $id = (int)$newId;
    }

    if ($action === 'save') {
        return ['Recruitment request saved successfully.', ['id' => $id]];
    }

    recruitmentExecute("UPDATE HR_RECRUITMENT SET STATUS = 'T' WHERE ID = :id AND STATUS = 'N'",
        [':id' => (string)$id], 'Could not send the recruitment request.');
    $taskId = generateTaskHR('RECRUT_REQ', $id, 'REQ_ID#' . $id . ' : Send Recruitment request for ' . ($org['POSITION'] ?? ''),
        'RECRUT_REQ', null, 'O', null, $empCode, $org['COMPANY'] ?? '', $org['DIVSN_ID'] ?? '', $org['DEPT_ID'] ?? '');
    if (empty($taskId)) {
        throw new RuntimeException('Could not create the Recruitment Request task.');
    }

    return ['Recruitment request sent. A task has been generated for the HOD to authorize.', ['id' => $id]];
}, 'saveRecruitmentRequest.php');
