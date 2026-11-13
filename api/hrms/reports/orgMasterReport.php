<?php
/* GET ?comp_id=&divsn_id=&dept_id= -> Organogram Master PDF. Legacy reports/admin.php orgmaster().
 * Legacy read a missing ORG_LEVEL column, so its Org Level was always blank; HR_ORGANOGRAM.OLVL_ID is used. */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('orgmaster', static function () {
    $compId = reportsId('comp_id', 'company');
    $divsnId = reportsId('divsn_id', 'division');
    $deptId = reportsId('dept_id', 'department');
    $names = reportsRow("SELECT (SELECT COMP_DESC FROM HR_COMPANY WHERE COMP_ID = :comp_id) AS CMP,
            (SELECT DIVSN_DESC FROM HR_DIVISIONS WHERE DIVSN_ID = :divsn_id) AS DIV,
            (SELECT DEPT_DESC FROM HR_DEPARTMENT WHERE DEPT_ID = :dept_id) AS DEPT FROM DUAL",
        [':comp_id' => $compId, ':divsn_id' => $divsnId, ':dept_id' => $deptId]);

    $rows = multiRec("SELECT GET_COMP_NAME(A.COMPANY) AS CMP, GET_DIVISION_NAME(A.DIVSN_ID) AS DIV, GET_DEPT_NAME(A.DEPT_ID) AS DEPT,
            GET_DESIGN_NAME(A.DESI_ID) AS DESI, A.POSI_COUNT, A.FILL_COUNT,
            (SELECT MAX(EL.LEVL_DESC) FROM HR_SFM_NEW_EMP_LEVELS EL WHERE EL.LEVL = A.EMP_LEVEL) AS EMP_LEVEL,
            (SELECT MAX(OL.OLVL_DESC) FROM HR_ORG_LEVEL OL WHERE OL.OLVL_ID = A.OLVL_ID) AS ORG_LEVEL,
            (SELECT MAX(J.SH_DESC) FROM HR_JD J WHERE J.ID = A.JD_ID) AS JD_LABEL,
            (SELECT COUNT(L.ID) FROM HR_ORGANOGRAM_LOC L WHERE L.ORG_ID = A.ID
                AND SYSDATE BETWEEN L.EFFEC_FROM AND NVL(L.EFFEC_TO, DATE '3000-03-01')) AS LOC_CNT,
            (SELECT COUNT(P.ID) FROM HR_ORG_APPR_LEVELS P WHERE P.ORG_ID = A.ID) AS APPR_CNT
        FROM HR_ORGANOGRAM A
        WHERE A.COMPANY = :comp_id AND A.DIVSN_ID = :divsn_id AND A.DEPT_ID = :dept_id
        ORDER BY A.ID", [':comp_id' => $compId, ':divsn_id' => $divsnId, ':dept_id' => $deptId]) ?: [];

    $columns = [['#', 3, 'center'], ['Fin Entity', 13, 'left'], ['Division', 8, 'left'], ['Department', 9, 'left'], ['Designation', 13, 'left'],
        ['Employee Level', 9, 'left'], ['Org Level', 8, 'left'], ['JD Label', 13, 'left'], ['Total Position', 6, 'center'],
        ['Filled Position', 6, 'center'], ['Location Count', 6, 'center'], ['Appraisal Level Count', 6, 'center']];
    $data = [];
    foreach ($rows as $index => $row) {
        $data[] = [$index + 1, $row['CMP'] ?? '', $row['DIV'] ?? '', $row['DEPT'] ?? '', $row['DESI'] ?? '', $row['EMP_LEVEL'] ?? '',
            $row['ORG_LEVEL'] ?? '', $row['JD_LABEL'] ?? '', $row['POSI_COUNT'] ?? '', $row['FILL_COUNT'] ?? '', $row['LOC_CNT'], $row['APPR_CNT']];
    }
    return ['Organogram Master', ['Company' => $names['CMP'] ?? '', 'Department' => $names['DEPT'] ?? '', 'Division' => $names['DIV'] ?? ''],
        $columns, $data, 'Organogram_' . ($names['CMP'] ?? '') . '_' . ($names['DEPT'] ?? '') . '_' . ($names['DIV'] ?? '')];
});
