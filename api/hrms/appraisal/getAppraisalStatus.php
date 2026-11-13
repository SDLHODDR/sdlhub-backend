<?php
/* GET            -> published appraisals (legacy appraisal_status.php list).
 * GET ?id=       -> its employees by organogram with each appraisal level's appraiser and progress:
 *                   done (that appraiser approved), progress (with the appraiser), pending (not reached yet), vacant. */
define('APPRAISAL_PAGE', 'status');
require_once __DIR__ . '/appraisalCommon.php';

appraisalRunRead(static function () {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        $rows = multiRec("SELECT ID, DESCR, GET_SHCOMP_NAME(COMP_ID) AS COMP, APPR_TYPE, APPR_BASE,
                TO_CHAR(PUBLISH_DATE, 'DD-Mon-YYYY') AS PUBLISH_DATE
            FROM HR_APPRAISAL_SETUP WHERE STATUS = 'P' ORDER BY PUBLISH_DATE DESC, ID DESC") ?: [];
        // The old page showed type and base in each other's columns; here each is in its own.
        return ['Appraisals fetched successfully.', ['appraisals' => array_map(static fn($row) => [
            'id' => $row['ID'],
            'company' => $row['COMP'] ?? '',
            'description' => $row['DESCR'] ?? '',
            'type' => APPRAISAL_TYPES[$row['APPR_TYPE']] ?? $row['APPR_TYPE'],
            'base' => APPRAISAL_BASES[$row['APPR_BASE']] ?? $row['APPR_BASE'],
            'publishDate' => $row['PUBLISH_DATE'] ?? '',
        ], $rows)]];
    }

    $appraisal = appraisalLoad($id);
    $id = (string)$appraisal['ID'];
    $employees = multiRec("SELECT A.EMP_CODE, A.ORG_ID, GET_ORG_NAME(A.ORG_ID) AS ORG_TITLE, INITCAP(TRIM(B.FNAME || ' ' || B.LNAME)) AS NM
        FROM HR_APPR_ORG_EMP A INNER JOIN HR_EMPLOYEE_INFO B ON B.EMP_CODE = A.EMP_CODE
        WHERE A.APPR_ID = :id AND A.STATUS IS NOT NULL ORDER BY A.ORG_ID, B.FNAME", [':id' => $id]) ?: [];
    $reviews = [];
    foreach (multiRec("SELECT EMP_CODE, EMP_CODE_APPR, STATUS FROM HR_APPR_EMP_MGR WHERE APPR_ID = :id ORDER BY ID", [':id' => $id]) ?: [] as $row) {
        $reviews[$row['EMP_CODE'] . '|' . $row['EMP_CODE_APPR']] = $row['STATUS'];
    }

    $levels = [];
    $codes = [];
    foreach ($employees as $employee) {
        $levels[$employee['EMP_CODE']] = appraisalAppraisers($employee['EMP_CODE'])['levels'];
        foreach ($levels[$employee['EMP_CODE']] as [, $code]) {
            $codes[] = $code;
        }
    }
    $names = appraisalEmployeeNames($codes);
    $maxLevel = 0;
    foreach ($levels as $list) {
        foreach ($list as [$level]) {
            $maxLevel = max($maxLevel, $level);
        }
    }

    $organograms = [];
    foreach ($employees as $employee) {
        $byLevel = [];
        foreach ($levels[$employee['EMP_CODE']] as [$level, $code]) {
            $byLevel[$level] = $code;
        }
        $cells = [];
        for ($level = 1; $level <= $maxLevel; $level++) {
            $code = $byLevel[$level] ?? null;
            if ($code === null) {
                $cells[] = ['name' => 'Vacant', 'state' => 'vacant'];
                continue;
            }
            $status = $reviews[$employee['EMP_CODE'] . '|' . $code] ?? null;
            $cells[] = [
                'name' => $names[$code] ?? $code,
                'state' => $status === 'A' ? 'done' : ($status === 'I' ? 'progress' : 'pending'),
            ];
        }
        $organograms[$employee['ORG_ID']] ??= ['id' => $employee['ORG_ID'], 'name' => $employee['ORG_TITLE'] ?? '', 'employees' => []];
        $organograms[$employee['ORG_ID']]['employees'][] = ['code' => $employee['EMP_CODE'], 'name' => $employee['NM'] ?? '', 'levels' => $cells];
    }
    return ['Appraisal status fetched successfully.', [
        'appraisal' => ['id' => $id, 'description' => $appraisal['DESCR'] ?? ''],
        'levelCount' => $maxLevel,
        'organograms' => array_values($organograms),
    ]];
}, 'getAppraisalStatus.php');
