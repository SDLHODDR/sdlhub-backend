<?php
/* GET          -> appraisals with at least one approved employee (legacy final_appraisal.php dropdown).
 * GET ?id=     -> the employees by department: CTC now and the two years before, the appraiser's and the
 *                 final recommendation; editable while the employee is not yet submitted.
 * GET ?id=&response=<emp code> -> the approving appraiser's answers and remarks (legacy "HOD Remarks"). */
require_once __DIR__ . '/finalAppraisalCommon.php';

appraisalRunRead(static function () {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        return ['Appraisals fetched successfully.', ['appraisals' => array_map(static fn($row) => ['value' => $row['ID'], 'label' => $row['DESCR']],
            multiRec("SELECT S.ID, S.DESCR FROM HR_APPRAISAL_SETUP S
                WHERE EXISTS (SELECT 1 FROM HR_APPR_EMP_MGR M WHERE M.APPR_ID = S.ID AND M.STATUS = 'A')
                ORDER BY S.ID DESC") ?: [])]];
    }
    $appraisal = appraisalLoad($id);
    $id = (string)$appraisal['ID'];
    $employees = finalAppraisalEmployees($id);

    $responseFor = trim((string)($_GET['response'] ?? ''));
    if ($responseFor !== '') {
        $review = $employees[$responseFor] ?? null;
        if (!$review) {
            throw new AppraisalRequestException('No approved appraisal was found for this employee.', 404);
        }
        $answers = multiRec("SELECT A.ANS_TEXT, A.QUES_REMARKS, B.QUESTION, G.QSGRP_DESC AS GRP, S.QSSGRP_DESC AS SUBGRP
            FROM HR_APPR_EMP_APPR A
            INNER JOIN HR_QUESTION_MASTER B ON B.ID = A.QUES_ID
            LEFT JOIN HR_APPR_QUESTION Q ON Q.APPR_ID = A.APPR_ID AND Q.QUESTION_ID = A.QUES_ID
            LEFT JOIN HR_QUESTION_SGROUP G ON G.QSGRP_ID = B.QGRP_ID
            LEFT JOIN HR_QUESTION_SSGROUP S ON S.QSSGRP_ID = B.QSGRP_ID
            WHERE A.EMP_MGR_ID = :mgr AND A.QUES_ID IS NOT NULL
            ORDER BY Q.ID, Q.DISP_SEQ, A.ID", [':mgr' => (string)$review['MGR_ID']]) ?: [];
        $names = appraisalEmployeeNames([$responseFor, $review['EMP_CODE_APPR']]);
        return ['Response fetched successfully.', [
            'employee' => $names[$responseFor] ?? $responseFor,
            'appraiser' => $names[$review['EMP_CODE_APPR']] ?? $review['EMP_CODE_APPR'],
            'promotion' => ($review['RECO_PROMO'] ?? '') === 'Y' ? 'Yes' : 'No',
            'increment' => $review['PROP_CTC'] ?? '',
            'remarks' => trim(html_entity_decode(strip_tags((string)($review['APPR_REMARKS'] ?? '')), ENT_QUOTES, 'UTF-8')),
            'answers' => array_map(static fn($row) => [
                'group' => $row['GRP'] ?? '',
                'subgroup' => $row['SUBGRP'] ?? '',
                'question' => $row['QUESTION'] ?? '',
                'answer' => $row['ANS_TEXT'] ?? '',
                'remarks' => $row['QUES_REMARKS'] ?? '',
            ], $answers),
        ]];
    }

    // CTC on the day before the appraisal period, and a year and two years before that (legacy).
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$appraisal['EFFEC_FROM']) ?: new DateTimeImmutable('today');
    $ctcDates = [];
    for ($back = 0; $back < 3; $back++) {
        $date = $from->modify('-1 day')->modify("-{$back} year");
        $ctcDates[] = ['date' => $date->format('Y-m-d'), 'label' => ((int)$date->format('Y') - 1) . '-' . $date->format('Y')];
    }
    $names = appraisalEmployeeNames(array_keys($employees));
    $default = (string)($appraisal['INCR_EFFEC_FROM'] ?? '');
    $rows = [];
    foreach ($employees as $code => $row) {
        $office = appraisalRow("SELECT GET_DEPT_NAME(D.DEPT_ID) AS DEPT, GET_DESIGN_NAME(D.DESI_ID) AS DESIG,
                TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ,
                GET_TOTAL_CTC(E.EMP_CODE, TO_DATE(:d0, 'YYYY-MM-DD')) AS CTC0,
                GET_TOTAL_CTC(E.EMP_CODE, TO_DATE(:d1, 'YYYY-MM-DD')) AS CTC1,
                GET_TOTAL_CTC(E.EMP_CODE, TO_DATE(:d2, 'YYYY-MM-DD')) AS CTC2
            FROM HR_EMPLOYEE_INFO E
            LEFT JOIN HR_EMP_OFFICE_DET D ON D.EMP_CODE = E.EMP_CODE AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
            WHERE E.EMP_CODE = :code ORDER BY D.ID DESC FETCH FIRST 1 ROWS ONLY",
            [':code' => (string)$code, ':d0' => $ctcDates[0]['date'], ':d1' => $ctcDates[1]['date'], ':d2' => $ctcDates[2]['date']]);
        $rows[] = [
            'code' => (string)$code,
            'name' => $names[$code] ?? (string)$code,
            'department' => $office['DEPT'] ?? '',
            'designation' => $office['DESIG'] ?? '',
            'doj' => $office['DOJ'] ?? '',
            'ctc' => array_map(static fn($value) => $value === null || $value === '' ? null : (float)$value,
                [$office['CTC0'] ?? null, $office['CTC1'] ?? null, $office['CTC2'] ?? null]),
            'hodPromotion' => ($row['RECO_PROMO'] ?? '') === 'Y' ? 'Yes' : 'No',
            'hodIncrement' => $row['PROP_CTC'] !== null && $row['PROP_CTC'] !== '' ? (float)$row['PROP_CTC'] : null,
            'promotion' => ($row['RECO_PROMO'] ?? '') === 'Y' ? 'Y' : 'N',
            'increment' => $row['PROP_CTC'] ?? '',
            'incrementFrom' => $row['INCR_FROM'] ?: $default,
            'promotionFrom' => $row['PROMO_FROM'] ?: $default,
            'remarks' => trim(html_entity_decode(strip_tags((string)($row['APPR_REMARKS'] ?? '')), ENT_QUOTES, 'UTF-8')),
            'editable' => $row['ORG_STATUS'] === 'I',
            'status' => $row['ORG_STATUS'],
        ];
    }
    usort($rows, static fn($a, $b) => [$a['department'], $a['name']] <=> [$b['department'], $b['name']]);
    $minMonth = finalAppraisalMinMonth($appraisal);
    return ['Final appraisal fetched successfully.', [
        'appraisal' => ['id' => $id, 'description' => $appraisal['DESCR'] ?? ''],
        'ctcYears' => array_column($ctcDates, 'label'),
        'minEffectiveFrom' => $minMonth ? $minMonth->format('Y-m-d') : '',
        'employees' => $rows,
    ]];
}, 'getFinalAppraisal.php');
