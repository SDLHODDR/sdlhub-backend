<?php
/* GET ?id= -> one appraisal: the setup, its questions by group, its employees by organogram with their
 * appraisers, and (while New) the question groups and organograms that can be added. */
require_once __DIR__ . '/appraisalCommon.php';

appraisalRunRead(static function () {
    $appraisal = appraisalLoad($_GET['id'] ?? 0);
    $id = (string)$appraisal['ID'];

    $questionRows = multiRec("SELECT A.QUESTION_ID, A.DISP_SEQ, B.QUESTION, B.QGRP_ID, G.QSGRP_DESC AS GRP, S.QSSGRP_DESC AS SUBGRP,
            MIN(A.ID) OVER (PARTITION BY B.QGRP_ID) AS GRP_ORDER
        FROM HR_APPR_QUESTION A
        INNER JOIN HR_QUESTION_MASTER B ON B.ID = A.QUESTION_ID AND B.STATUS = 'A'
        LEFT JOIN HR_QUESTION_SGROUP G ON G.QSGRP_ID = B.QGRP_ID
        LEFT JOIN HR_QUESTION_SSGROUP S ON S.QSSGRP_ID = B.QSGRP_ID
        WHERE A.APPR_ID = :id
        ORDER BY GRP_ORDER, A.DISP_SEQ, A.ID", [':id' => $id]) ?: [];
    $groups = [];
    foreach ($questionRows as $row) {
        $groups[$row['QGRP_ID']] ??= ['id' => $row['QGRP_ID'], 'name' => $row['GRP'] ?? '', 'questions' => []];
        $groups[$row['QGRP_ID']]['questions'][] = [
            'id' => $row['QUESTION_ID'],
            'sequence' => (int)$row['DISP_SEQ'],
            'subgroup' => $row['SUBGRP'] ?? '',
            'question' => $row['QUESTION'] ?? '',
        ];
    }

    $employeeRows = multiRec("SELECT A.EMP_CODE, A.ORG_ID, GET_ORG_NAME(A.ORG_ID) AS ORG_TITLE,
            INITCAP(TRIM(B.FNAME || ' ' || B.LNAME)) AS NM, TO_CHAR(B.DOJ, 'DD-Mon-YYYY') AS DOJ,
            (SELECT TO_CHAR(MIN(T.EFF_FROM), 'DD-Mon-YYYY') FROM HR_EMP_TENURE T WHERE T.EMP_CODE = A.EMP_CODE) AS CONF_DATE,
            M.EMP_CODE_APPR
        FROM HR_APPR_ORG_EMP A
        INNER JOIN HR_EMPLOYEE_INFO B ON B.EMP_CODE = A.EMP_CODE
        LEFT JOIN HR_APPR_EMP_MGR M ON M.APPR_ID = A.APPR_ID AND M.EMP_CODE = A.EMP_CODE AND M.APPR_LEVEL = 1
        WHERE A.APPR_ID = :id AND A.STATUS IS NOT NULL
        ORDER BY A.ORG_ID, B.FNAME", [':id' => $id]) ?: [];
    $appraisers = [];
    foreach ($employeeRows as $row) {
        $appraisers[$row['EMP_CODE']] = appraisalAppraisers($row['EMP_CODE']);
    }
    $appraiserCodes = [];
    foreach ($appraisers as $appraiser) {
        foreach ($appraiser['levels'] as [, $code]) {
            $appraiserCodes[] = $code;
        }
    }
    $names = appraisalEmployeeNames($appraiserCodes);
    $organograms = [];
    foreach ($employeeRows as $row) {
        $organograms[$row['ORG_ID']] ??= ['id' => $row['ORG_ID'], 'name' => $row['ORG_TITLE'] ?? '', 'employees' => []];
        $organograms[$row['ORG_ID']]['employees'][] = [
            'code' => $row['EMP_CODE'],
            'name' => $row['NM'] ?? '',
            'doj' => $row['DOJ'] ?? '',
            'confirmationDate' => $row['CONF_DATE'] ?? '',
            'appraisers' => array_map(static fn($level) => $level[0] . '. ' . ($level[1] !== null ? ($names[$level[1]] ?? $level[1]) : 'Vacant'),
                $appraisers[$row['EMP_CODE']]['levels']),
        ];
    }

    $data = [
        'appraisal' => [
            'id' => $appraisal['ID'],
            'company' => $appraisal['COMP_ID'],
            'description' => $appraisal['DESCR'] ?? '',
            'type' => $appraisal['APPR_TYPE'],
            'base' => $appraisal['APPR_BASE'],
            'effectiveFrom' => $appraisal['EFFEC_FROM'] ?? '',
            'effectiveTo' => $appraisal['EFFEC_TO'] ?? '',
            'baseDays' => $appraisal['APPR_DAYS'] ?? '',
            'appliedFrom' => $appraisal['INCR_EFFEC_FROM'] ?? '',
            'publishDate' => $appraisal['PUBLISH_DATE'] ?? '',
            'closeDate' => $appraisal['CLOSE_DATE'] ?? '',
            'status' => $appraisal['STATUS'],
        ],
        'questionGroups' => array_values($groups),
        'organograms' => array_values($organograms),
    ];
    if ($appraisal['STATUS'] === 'N') {
        $data['groupOptions'] = array_map(static fn($row) => ['value' => $row['QGRP_ID'], 'label' => $row['QSGRP_DESC']],
            multiRec("SELECT DISTINCT A.QGRP_ID, B.QSGRP_DESC FROM HR_QUESTION_MASTER A
                INNER JOIN HR_QUESTION_SGROUP B ON B.QSGRP_ID = A.QGRP_ID WHERE A.STATUS = 'A' ORDER BY B.QSGRP_DESC") ?: []);
        // Organograms of the appraisal's company in the user's division / department scope (legacy).
        $data['organogramOptions'] = array_map(static fn($row) => ['value' => $row['ID'], 'label' => $row['L']],
            multiRec("SELECT O.ID, GET_SHCOMP_NAME(O.COMPANY) || ' - ' || GET_DIVISION_NAME(O.DIVSN_ID) || ' - '
                    || GET_DEPT_NAME(O.DEPT_ID) || ' - ' || GET_DESIGN_NAME(O.DESI_ID) AS L
                FROM HR_ORGANOGRAM O WHERE O.COMPANY = :comp_id AND " . appraisalOrgScopeSql('O') . " ORDER BY O.ID DESC",
                [':comp_id' => (string)$appraisal['COMP_ID']]) ?: []);
    }
    return ['Appraisal fetched successfully.', $data];
}, 'getAppraisalSetup.php');
