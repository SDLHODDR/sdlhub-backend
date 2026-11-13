<?php
/* GET ?id=&qgrp_id=  -> active questions of a group, marking those already in the appraisal.
 * GET ?id=&org_id=   -> current permanent / contractual employees of an organogram, marking locations
 *                       already added (legacy: checked by location); hasLevels false when the organogram
 *                       has no appraisal levels, as employees cannot be added then ("Please set Appraisal Level"). */
require_once __DIR__ . '/appraisalCommon.php';

appraisalRunRead(static function () {
    $appraisal = appraisalLoad($_GET['id'] ?? 0);
    appraisalRequireNew($appraisal);
    $id = (string)$appraisal['ID'];

    $groupId = trim((string)($_GET['qgrp_id'] ?? ''));
    if ($groupId !== '') {
        if (!ctype_digit($groupId)) {
            throw new AppraisalRequestException('Invalid question group.', 422);
        }
        return ['Questions fetched successfully.', ['questions' => array_map(static fn($row) => [
            'id' => $row['ID'], 'question' => $row['QUESTION'] ?? '', 'added' => (int)$row['ADDED'] > 0,
        ], multiRec("SELECT Q.ID, Q.QUESTION,
                (SELECT COUNT(*) FROM HR_APPR_QUESTION A WHERE A.APPR_ID = :id AND A.QUESTION_ID = Q.ID) AS ADDED
            FROM HR_QUESTION_MASTER Q WHERE Q.STATUS = 'A' AND Q.QGRP_ID = :grp ORDER BY Q.ID", [':id' => $id, ':grp' => $groupId]) ?: [])]];
    }

    $orgId = trim((string)($_GET['org_id'] ?? ''));
    if (!ctype_digit($orgId)) {
        throw new AppraisalRequestException('Please select an organogram.', 422);
    }
    $org = appraisalRow("SELECT O.ID FROM HR_ORGANOGRAM O WHERE O.ID = :org AND O.COMPANY = :comp AND " . appraisalOrgScopeSql('O'),
        [':org' => $orgId, ':comp' => (string)$appraisal['COMP_ID']]);
    if (empty($org['ID'])) {
        throw new AppraisalRequestException('This organogram is not available for the appraisal.', 403);
    }
    $levels = appraisalRow("SELECT COUNT(*) AS CNT FROM HR_ORG_APPR_LEVELS WHERE ORG_ID = :org", [':org' => $orgId]);
    if ((int)($levels['CNT'] ?? 0) === 0) {
        return ['Employees fetched successfully.', ['hasLevels' => false, 'employees' => []]];
    }
    $rows = multiRec("SELECT L.ID AS LOC_ID, E.EMP_CODE, INITCAP(TRIM(E.FNAME || ' ' || E.LNAME)) AS NM,
            TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ,
            (SELECT COUNT(*) FROM HR_APPR_ORG_EMP X WHERE X.APPR_ID = :id AND X.ORG_LOC_ID = L.ID) AS ADDED
        FROM HR_ORGANOGRAM_LOC L
        INNER JOIN HR_EMP_OFFICE_DET D ON D.ORG_LOC_ID = L.ID AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
        INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = D.EMP_CODE AND E.STATUS = 'A'
        WHERE L.ORG_ID = :org
          AND EXISTS (SELECT 1 FROM HR_EMP_TENURE T WHERE T.EMP_CODE = E.EMP_CODE AND T.ETYPE_ID IN ('" . implode("','", APPRAISAL_EMP_TYPES) . "'))
        ORDER BY L.ID", [':id' => $id, ':org' => $orgId]) ?: [];
    return ['Employees fetched successfully.', ['hasLevels' => true, 'employees' => array_map(static fn($row) => [
        'code' => $row['EMP_CODE'], 'locationId' => $row['LOC_ID'], 'name' => $row['NM'] ?? '', 'doj' => $row['DOJ'] ?? '',
        'added' => (int)$row['ADDED'] > 0,
    ], $rows)]];
}, 'getAppraisalSetupChoices.php');
