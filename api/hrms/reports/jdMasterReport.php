<?php
/* GET ?dept_id=&desig_id=<id>|ALL -> JD Master PDF. Legacy reports/admin.php jdmaster(). */
require_once __DIR__ . '/reportsCommon.php';

const JD_MASTER_COUNTS = [
    'RESP' => 'HR_JD_DESCRIPTION', 'KRA' => 'HR_JD_KRA', 'EDU' => 'HR_JD_EDU_DET', 'SKILLS' => 'HR_JD_CAPABILITIES',
    'ALLOW' => 'HR_JD_ALLOWANCES', 'CTC' => 'HR_JD_CTC_HEADS', 'DEPT_REF' => 'HR_JD_REF_DEPT', 'DIVSN' => 'HR_JD_DIVSN',
    'INDUCTION' => 'HR_JD_INDUCTION',
];

reportsRunPdf('jdmaster', static function () {
    $deptId = reportsId('dept_id', 'department');
    $desig = strtoupper(trim((string)($_GET['desig_id'] ?? '')));
    $all = $desig === 'ALL';
    if (!$all) {
        $desig = reportsId('desig_id', 'designation');
    }
    $names = reportsRow("SELECT (SELECT DEPT_DESC FROM HR_DEPARTMENT WHERE DEPT_ID = :dept_id) AS DEPT,
            (SELECT DESI_DESC FROM HR_DESIGNATION WHERE DESI_ID = :desig_id) AS DESI FROM DUAL",
        [':dept_id' => $deptId, ':desig_id' => $all ? '' : $desig]);

    $counts = '';
    foreach (JD_MASTER_COUNTS as $alias => $table) {
        $counts .= ", (SELECT COUNT(*) FROM {$table} X WHERE X.JD_ID = A.ID) AS CNT_{$alias}";
    }
    $binds = [':dept_id' => $deptId];
    $where = 'A.DEPT_ID = :dept_id';
    if (!$all) {
        $where .= ' AND A.DESIG_ID = :desig_id';
        $binds[':desig_id'] = $desig;
    }
    // Mapped questions: question groups with at least one question for the JD, out of all groups.
    $rows = multiRec("SELECT A.ID, A.SH_DESC, GET_DESIGN_NAME(A.DESIG_ID) AS DESI, A.MIN_SAL, A.MAX_SAL, A.MIN_AGE, A.MAX_AGE,
            A.MIN_EXP, A.MAX_EXP,
            (SELECT MAX(E.EDU_DESC) FROM HR_EDUCATION_LEVEL E WHERE E.EDU_LEVEL = A.MIN_QUALI) AS MIN_QUALI,
            (SELECT MAX(E.EDU_DESC) FROM HR_EDUCATION_LEVEL E WHERE E.EDU_LEVEL = A.MAX_QUALI) AS MAX_QUALI,
            (SELECT COUNT(DISTINCT Q.QGRP_ID) FROM HR_JD_QUESTIONS Q INNER JOIN HR_QUESTION_MASTER QM ON QM.ID = Q.QUESTION_ID
               WHERE Q.JD_ID = A.ID AND Q.QGRP_TYPE IS NOT NULL
                 AND Q.QGRP_ID IN (SELECT QGRP_ID FROM HR_QUESTION_GROUP)) AS QUES_GRP
            {$counts}
        FROM HR_JD A WHERE {$where}
        ORDER BY A.LVL_ID, A.ID", $binds) ?: [];
    $groups = reportsRow("SELECT COUNT(QGRP_ID) AS CNT FROM HR_QUESTION_GROUP");

    $years = static fn($value) => trim((string)$value) === '' ? '' : $value . ' Years';
    $columns = [['#', 2, 'center'], ['JD Label', 9, 'left'], ['Designation', 9, 'left'], ['Min Salary', 5, 'right'], ['Max Salary', 5, 'right'],
        ['Min Age', 4, 'center'], ['Max Age', 4, 'center'], ['Min Exp', 4, 'center'], ['Max Exp', 4, 'center'], ['Min Quali.', 6, 'left'],
        ['Max Quali.', 6, 'left'], ['Respon- sibilities', 5, 'center'], ['KRA', 3, 'center'], ['Edu- cation', 4, 'center'],
        ['Skills', 4, 'center'], ['Allow- ances', 4, 'center'], ['CTC Heads', 4, 'center'], ['Mapped Ques', 5, 'center'],
        ['Dept Ref', 4, 'center'], ['Mapped Division', 5, 'center'], ['Induc- tion', 4, 'center']];
    $data = [];
    foreach ($rows as $index => $row) {
        $data[] = [$index + 1, $row['SH_DESC'] ?? '', $row['DESI'] ?? '', $row['MIN_SAL'] ?? '', $row['MAX_SAL'] ?? '',
            $years($row['MIN_AGE']), $years($row['MAX_AGE']), $years($row['MIN_EXP']), $years($row['MAX_EXP']),
            $row['MIN_QUALI'] ?? '', $row['MAX_QUALI'] ?? '', $row['CNT_RESP'], $row['CNT_KRA'], $row['CNT_EDU'], $row['CNT_SKILLS'],
            $row['CNT_ALLOW'], $row['CNT_CTC'], $row['QUES_GRP'] . '/' . ($groups['CNT'] ?? 0), $row['CNT_DEPT_REF'], $row['CNT_DIVSN'],
            $row['CNT_INDUCTION']];
    }
    return ['JD Master', ['Department' => $names['DEPT'] ?? '', 'Designation' => $all ? 'ALL' : ($names['DESI'] ?? '')],
        $columns, $data, 'JD_Master_' . ($names['DEPT'] ?? '') . '_' . ($all ? 'ALL' : ($names['DESI'] ?? ''))];
});
