<?php
/*
 * GET ?user_task_id= - Employee Appraisal task (46), legacy employee_appraisal.php:
 * employee summary, increment history, earlier appraisers' recommendations, and this
 * appraiser's form (questions for a fixed-format appraisal, KRAs for a KRA-based one).
 */
require_once __DIR__ . '/employeeAppraisalCommon.php';

employeeAppraisalRunRead(function () use ($empCode) {
    $task = employeeAppraisalLoadTask($_GET['user_task_id'] ?? 0, $empCode);
    $manager = $task['MANAGER'];
    $appraisal = $task['APPRAISAL'];
    $code = (string)$manager['EMP_CODE'];
    $mgrId = (string)$manager['ID'];
    $bind = [':code' => $code];

    $employee = appraisalRow("SELECT E.EMP_CODE, INITCAP(TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' '))) AS NAME,
            TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ, TRUNC(MONTHS_BETWEEN(SYSDATE, E.DOJ)) AS MONTHS,
            GET_DESIGN_NAME(O.DESI_ID) AS DESIGNATION, GET_DEPT_NAME(O.DEPT_ID) AS DEPARTMENT,
            GET_DIVISION_NAME(O.DIVSN_ID) AS DIVISION, GET_ORG_LOC_NAME(O.ORG_LOC_ID) AS LOCATION,
            GET_TOTAL_CTC(E.EMP_CODE, SYSDATE) AS CTC
        FROM HR_EMPLOYEE_INFO E
        LEFT JOIN HR_EMP_OFFICE_DET O ON O.EMP_CODE = E.EMP_CODE AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
        WHERE E.EMP_CODE = :code ORDER BY O.ID DESC FETCH FIRST 1 ROWS ONLY", $bind);

    // Reporting to: the first person in place above the employee.
    $chain = appraisalRow("SELECT GET_EMP_PARENTAL(:code, SYSDATE) AS ORG FROM DUAL", $bind);
    $reportsTo = '';
    foreach (explode('#', rtrim((string)($chain['ORG'] ?? ''), '#')) as $entry) {
        $parts = explode(',', $entry);
        if (count($parts) === 3 && strcasecmp($parts[2], 'vacant') !== 0) {
            $reportsTo = $parts[2];
            break;
        }
    }

    $increment = appraisalRow("SELECT COUNT(*) AS CNT, TO_CHAR(MAX(EFF_DATE), 'DD-Mon-YYYY') AS PREV_INCR,
            TO_CHAR(MAX(EXP_DATE) + 1, 'DD-Mon-YYYY') AS NEXT_DUE
        FROM HR_BCS_EMPLOYEE_AD WHERE AD_CODE = 'MBAS' AND EMP_CODE = :code", $bind);

    // Increment history (legacy table): today plus the last four completed financial years.
    $dates = [['key' => 'present', 'label' => 'Present', 'date' => date('Y-m-d')]];
    foreach (multiRec("SELECT * FROM (SELECT TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DT, CODE FROM HR_BCS_ACCT_PERIOD
        WHERE TO_DATE < TRUNC(SYSDATE) ORDER BY TO_DATE DESC) WHERE ROWNUM <= 4") ?: [] as $period) {
        $dates[] = ['key' => $period['TO_DT'], 'label' => 'FY ' . $period['CODE'], 'date' => $period['TO_DT']];
    }
    $heads = multiRec("SELECT DISTINCT A.AD_CODE, B.SH_DESCR, B.SEQ_NO FROM HR_BCS_EMPLOYEE_AD A
        INNER JOIN HR_BCS_ALLW_DEDN B ON B.AD_CODE = A.AD_CODE AND B.COST_COMPANY = 'Y'
        WHERE A.EMP_CODE = :code ORDER BY B.SEQ_NO", $bind) ?: [];
    $history = ['columns' => array_map(static fn($d) => ['key' => $d['key'], 'label' => $d['label']], $dates), 'heads' => [], 'totals' => [], 'ctc' => []];
    foreach ($heads as $head) {
        $history['heads'][$head['AD_CODE']] = ['label' => $head['SH_DESCR'], 'values' => []];
    }
    foreach ($dates as $d) {
        $at = [':code' => $code, ':at' => $d['date']];
        $total = 0;
        foreach (multiRec("SELECT A.AD_CODE, A.AMOUNT FROM HR_BCS_EMPLOYEE_AD A
            INNER JOIN HR_BCS_ALLW_DEDN B ON B.AD_CODE = A.AD_CODE AND B.COST_COMPANY = 'Y'
            WHERE A.EMP_CODE = :code AND TO_DATE(:at, 'YYYY-MM-DD') BETWEEN A.EFF_DATE AND A.EXP_DATE", $at) ?: [] as $row) {
            if (isset($history['heads'][$row['AD_CODE']])) {
                $history['heads'][$row['AD_CODE']]['values'][$d['key']] = (float)$row['AMOUNT'];
                $total += (float)$row['AMOUNT'];
            }
        }
        $ctc = appraisalRow("SELECT GET_TOTAL_CTC(:code, TO_DATE(:at, 'YYYY-MM-DD')) / 12 AS CTC FROM DUAL", $at);
        $history['totals'][$d['key']] = $total;
        $history['ctc'][$d['key']] = round((float)($ctc['CTC'] ?? 0));
    }
    $history['heads'] = array_values($history['heads']);

    // Earlier appraisers of this appraisal (submitted rows).
    $earlier = multiRec("SELECT M.ID, M.APPR_LEVEL, M.EMP_CODE_APPR, M.PROP_CTC, M.RECO_PROMO, M.APPR_REMARKS,
            TO_CHAR(M.NXT_APPR_DUE, 'Mon-YYYY') AS NXT_DUE
        FROM HR_APPR_EMP_MGR M WHERE M.APPR_ID = :appr AND M.EMP_CODE = :code AND M.STATUS = 'A' AND M.ID <> :mgr
        ORDER BY M.APPR_LEVEL, M.ID", [':appr' => (string)$appraisal['ID'], ':code' => $code, ':mgr' => $mgrId]) ?: [];
    $names = appraisalEmployeeNames(array_merge([$reportsTo, $manager['EMP_CODE_APPR']], array_column($earlier, 'EMP_CODE_APPR')));

    $form = ['questions' => [], 'kras' => []];
    if ($appraisal['APPR_BASE'] === 'K') {
        $saved = [];
        foreach (multiRec("SELECT KRA_ID, KRA_ACT_ID, APPR_PERC, KRA_REMARKS FROM HR_APPR_EMP_APPR
            WHERE EMP_MGR_ID = :mgr AND APPR_ID = :appr AND KRA_ID IS NOT NULL",
            [':mgr' => $mgrId, ':appr' => (string)$appraisal['ID']]) ?: [] as $row) {
            $saved[$row['KRA_ID']]['perc'] = $row['APPR_PERC'];
            $saved[$row['KRA_ID']]['acts'][$row['KRA_ACT_ID']] = $row['KRA_REMARKS'];
        }
        foreach (multiRec("SELECT A.KRA_ID, A.KRA_DESC, B.RESP_PERC FROM HR_KRA_MASTER A
            INNER JOIN HR_EMP_KRA B ON B.KRA_ID = A.KRA_ID
            WHERE B.EMP_CODE = :code AND SYSDATE BETWEEN B.EFFEC_FROM AND NVL(B.EFFEC_TO, DATE '3000-03-01')
            ORDER BY A.KRA_ID", $bind) ?: [] as $kra) {
            $activities = multiRec("SELECT ID, ACTT_DESC FROM HR_KRA_ACTIVITY WHERE KRA_ID = :kra AND STATUS = 'A' ORDER BY ID",
                [':kra' => $kra['KRA_ID']]) ?: [];
            $form['kras'][] = [
                'id' => (string)$kra['KRA_ID'],
                'description' => (string)$kra['KRA_DESC'],
                'weightage' => (string)$kra['RESP_PERC'],
                'perc' => (string)($saved[$kra['KRA_ID']]['perc'] ?? ''),
                'activities' => array_map(static fn($a) => [
                    'id' => (string)$a['ID'],
                    'description' => ucwords(strtolower((string)$a['ACTT_DESC'])),
                    'remarks' => (string)($saved[$kra['KRA_ID']]['acts'][$a['ID']] ?? ''),
                ], $activities),
            ];
        }
    } else {
        $options = [];
        foreach (multiRec("SELECT O.QUESTION_ID, O.OPTS_TEXT FROM HR_QUESTION_OPTS O
            WHERE O.QUESTION_ID IN (SELECT QUESTION_ID FROM HR_APPR_QUESTION WHERE APPR_ID = :appr)
            ORDER BY O.QUESTION_ID, O.OPTS_SEQ, O.ID", [':appr' => (string)$appraisal['ID']]) ?: [] as $row) {
            $options[$row['QUESTION_ID']][] = (string)$row['OPTS_TEXT'];
        }
        foreach (multiRec("SELECT Q.QUESTION_ID, Q.DISP_SEQ, M.QUESTION, M.RATING_TYPE,
                G.QSGRP_DESC AS GRP, S.QSSGRP_DESC AS SUBGRP, A.ANS_TEXT, A.QUES_REMARKS
            FROM HR_APPR_QUESTION Q
            INNER JOIN HR_QUESTION_MASTER M ON M.ID = Q.QUESTION_ID
            LEFT JOIN HR_QUESTION_SGROUP G ON G.QSGRP_ID = M.QGRP_ID
            LEFT JOIN HR_QUESTION_SSGROUP S ON S.QSSGRP_ID = M.QSGRP_ID
            LEFT JOIN HR_APPR_EMP_APPR A ON A.EMP_MGR_ID = :mgr AND A.APPR_ID = Q.APPR_ID AND A.QUES_ID = Q.QUESTION_ID
            WHERE Q.APPR_ID = :appr
            ORDER BY Q.ID", [':mgr' => $mgrId, ':appr' => (string)$appraisal['ID']]) ?: [] as $q) {
            $type = (string)$q['RATING_TYPE'];
            $answer = (string)($q['ANS_TEXT'] ?? '');
            $form['questions'][] = [
                'id' => (string)$q['QUESTION_ID'],
                'seq' => (string)$q['DISP_SEQ'],
                'question' => ucwords(strtolower(trim((string)$q['QUESTION']))),
                'type' => in_array($type, ['R', 'C'], true) ? $type : 'T',
                'group' => trim((string)($q['GRP'] ?? '')),
                'subgroup' => trim((string)($q['SUBGRP'] ?? '')),
                'options' => $options[$q['QUESTION_ID']] ?? [],
                'answer' => $type === 'C' ? ($answer === '' ? [] : explode(',', $answer)) : $answer,
                'remarks' => (string)($q['QUES_REMARKS'] ?? ''),
            ];
        }
    }

    return ['Appraisal fetched successfully.', [
        'appraisal' => [
            'id' => (string)$appraisal['ID'],
            'title' => (string)$appraisal['DESCR'],
            'base' => $appraisal['APPR_BASE'] === 'K' ? 'K' : 'F',
            'type' => (string)$appraisal['APPR_TYPE'],
            'typeText' => APPRAISAL_TYPES[$appraisal['APPR_TYPE']] ?? '',
        ],
        'employee' => [
            'code' => $code,
            'name' => (string)($employee['NAME'] ?? ''),
            'designation' => (string)($employee['DESIGNATION'] ?? ''),
            'department' => (string)($employee['DEPARTMENT'] ?? ''),
            'division' => (string)($employee['DIVISION'] ?? ''),
            'location' => (string)($employee['LOCATION'] ?? ''),
            'doj' => (string)($employee['DOJ'] ?? ''),
            'years' => intdiv((int)($employee['MONTHS'] ?? 0), 12) . ' Years ' . ((int)($employee['MONTHS'] ?? 0) % 12) . ' Months',
            'reportsTo' => $reportsTo !== '' ? ($names[$reportsTo] ?? $reportsTo) : '',
            'ctc' => (float)($employee['CTC'] ?? 0),
            'lastIncrement' => (int)($increment['CNT'] ?? 0) > 0 ? (string)$increment['PREV_INCR'] : '',
            'nextIncrementDue' => (int)($increment['CNT'] ?? 0) > 0 ? (string)$increment['NEXT_DUE'] : '',
        ],
        'assessmentDate' => date('d-M-Y'),
        'level' => (int)$manager['APPR_LEVEL'],
        'appraiser' => $names[$manager['EMP_CODE_APPR']] ?? (string)$manager['EMP_CODE_APPR'],
        'history' => $history,
        'earlier' => array_map(static fn($row) => [
            'level' => (int)$row['APPR_LEVEL'],
            'appraiser' => $names[$row['EMP_CODE_APPR']] ?? (string)$row['EMP_CODE_APPR'],
            'propCtc' => $row['PROP_CTC'] !== null && $row['PROP_CTC'] !== '' ? (float)$row['PROP_CTC'] : null,
            'recoPromo' => (string)($row['RECO_PROMO'] ?? ''),
            'nextDue' => (string)($row['NXT_DUE'] ?? ''),
            'remarks' => (string)($row['APPR_REMARKS'] ?? ''),
        ], $earlier),
        'form' => $form,
        'decision' => [
            'propCtc' => $manager['PROP_CTC'] !== null && $manager['PROP_CTC'] !== '' ? (string)$manager['PROP_CTC'] : '',
            'recoPromo' => (string)($manager['RECO_PROMO'] ?? ''),
            'nextDue' => (string)($manager['NXT_APPR_DUE'] ?? ''),
            'remarks' => (string)($manager['APPR_REMARKS'] ?? ''),
        ],
        'dueOptions' => employeeAppraisalDueOptions(),
    ]];
}, 'getEmployeeAppraisal.php');
