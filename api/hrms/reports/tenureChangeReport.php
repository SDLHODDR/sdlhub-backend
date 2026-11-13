<?php
/* GET ?comp_id=&emp_code=<code>|ALL[&from_date=&to_date=] -> Tenure Change PDF. Legacy reports/admin.php tenure().
 * The old report ignored its dates; here From / To (optional, ALL only) filter on the tenure change's Effect From. */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('tenure', static function () {
    $compId = reportsId('comp_id', 'company');
    $empCode = strtoupper(trim((string)($_GET['emp_code'] ?? 'ALL')));
    $all = $empCode === '' || $empCode === 'ALL';
    if (!$all && !preg_match('/^[A-Z0-9]{1,20}$/', $empCode)) {
        throw new ReportsException('Please select a valid employee.', 422);
    }
    $from = $all ? reportsOptionalDate('from_date', 'from date') : null;
    $to = $all ? reportsOptionalDate('to_date', 'to date') : null;
    if ($from && $to && $to < $from) {
        throw new ReportsException('To date cannot be before the from date.', 422);
    }

    $binds = [':comp_id' => $compId];
    $where = 'B.COMP_ID = :comp_id';
    if (!$all) {
        $where .= ' AND T.EMP_CODE = :emp_code';
        $binds[':emp_code'] = $empCode;
    }
    if ($from) {
        $where .= " AND T.EFF_FROM >= TO_DATE(:from_date, 'YYYY-MM-DD')";
        $binds[':from_date'] = $from->format('Y-m-d');
    }
    if ($to) {
        $where .= " AND T.EFF_FROM < TO_DATE(:to_date, 'YYYY-MM-DD') + 1";
        $binds[':to_date'] = $to->format('Y-m-d');
    }

    // Latest (RN 1) and previous (RN 2) employee tenures, as the old report read them.
    $rows = multiRec("WITH TEN AS (
            SELECT ET.EMP_CODE, ETM.EMP_TYPE, ET.ETYPE_PERIOD, ET.EFF_FROM, ET.EFF_TO,
                ROW_NUMBER() OVER (PARTITION BY ET.EMP_CODE ORDER BY ET.EFF_TO DESC NULLS FIRST, ET.ID DESC) AS RN
            FROM HR_EMP_TENURE ET INNER JOIN HR_EMP_TYPE ETM ON ETM.ETYPE_ID = ET.ETYPE_ID)
        SELECT T.EMP_CODE, T.STATUS, T.AUTH_BY, INITCAP(TRIM(B.FNAME || ' ' || B.LNAME)) AS EMP_NAME,
            TO_CHAR(B.DOJ, 'DD-Mon-YYYY') AS DOJ,
            (SELECT GET_DESIGN_NAME(O.DESI_ID) || '|' || L.GEO_DESC FROM HR_EMP_OFFICE_DET O
               LEFT JOIN HR_ORGANOGRAM_LOC L ON L.ID = O.ORG_LOC_ID
              WHERE O.EMP_CODE = T.EMP_CODE AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
              ORDER BY O.ID DESC FETCH FIRST 1 ROWS ONLY) AS OFFICE,
            N.EMP_TYPE AS NEW_TYPE, N.ETYPE_PERIOD AS NEW_PERIOD,
            TO_CHAR(N.EFF_FROM, 'DD-Mon-YYYY') AS NEW_FROM, TO_CHAR(N.EFF_TO, 'DD-Mon-YYYY') AS NEW_TO,
            P.EMP_TYPE AS PREV_TYPE, TO_CHAR(P.EFF_TO, 'DD-Mon-YYYY') AS PREV_TO,
            (SELECT MAX(UT.EMP_CODE_FOR) FROM HR_USER_TASKS UT WHERE UT.TASK_ID = 25 AND UT.STATUS = 'O' AND UT.TRAN_CODE = T.EMP_CODE) AS PENDING_WITH,
            " . reportsEmpNameSql('T.AUTH_BY') . " AS AUTH_NAME
        FROM HR_EMP_TENURE_CHANGE T
        INNER JOIN HR_EMPLOYEE_INFO B ON B.EMP_CODE = T.EMP_CODE
        LEFT JOIN TEN N ON N.EMP_CODE = T.EMP_CODE AND N.RN = 1
        LEFT JOIN TEN P ON P.EMP_CODE = T.EMP_CODE AND P.RN = 2
        WHERE {$where}
        ORDER BY T.EMP_CODE, T.ID", $binds) ?: [];

    $company = reportsRow("SELECT MAX(FINENT) AS FINENT FROM HR_ORGANOGRAM WHERE COMPANY = :comp_id", [':comp_id' => $compId]);
    $employee = $all ? 'ALL' : (reportsRow("SELECT INITCAP(TRIM(FNAME || ' ' || LNAME)) AS NM FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code",
        [':code' => $empCode])['NM'] ?? $empCode);
    $pendingNames = [];

    $columns = [['#', 3, 'center'], ['Emp Code', 4, 'left'], ['Emp Name', 7, 'left'], ['Designation', 9, 'left'], ['Org. Location', 6, 'left'],
        ['Emp Type', 9, 'left'], ['DOJ', 7, 'left'], ['Tenure Due', 7, 'left'], ['New Emp Type', 9, 'left'], ['Tenure Period', 4, 'center'],
        ['Status', 6, 'left'], ['Emp Code for', 6, 'left'], ['Effect From', 7, 'left'], ['Effect To', 7, 'center'], ['Auth By', 9, 'left']];
    $data = [];
    foreach ($rows as $index => $row) {
        $status = (string)$row['STATUS'];
        [$designation, $location] = array_pad(explode('|', (string)($row['OFFICE'] ?? ''), 2), 2, '');
        $pending = (string)($row['PENDING_WITH'] ?? '');
        if ($pending !== '' && !isset($pendingNames[$pending])) {
            $pendingNames[$pending] = reportsRow("SELECT INITCAP(TRIM(FNAME || ' ' || LNAME)) AS NM FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code",
                [':code' => $pending])['NM'] ?? $pending;
        }
        $data[] = [$index + 1, $row['EMP_CODE'], $row['EMP_NAME'] ?? '', $designation, $location,
            in_array($status, ['N', 'T'], true) ? ($row['NEW_TYPE'] ?? '') : ($row['PREV_TYPE'] ?? ''),
            $row['DOJ'] ?? '',
            ($row['PREV_TO'] ?? '') !== '' ? $row['PREV_TO'] : ($row['NEW_FROM'] ?? ''),
            $status !== 'A' ? 'Pending' : ($row['NEW_TYPE'] ?? ''),
            ($row['NEW_PERIOD'] ?? '') !== '' ? $row['NEW_PERIOD'] : 'NA',
            REPORTS_TENURE_STATUSES[$status] ?? $status,
            $pending !== '' ? $pendingNames[$pending] : '',
            $row['NEW_FROM'] ?? '',
            ($row['NEW_TO'] ?? '') !== '' ? $row['NEW_TO'] : 'NA',
            ($row['AUTH_BY'] ?? '') === '' ? 'Pending' : ($row['AUTH_NAME'] ?? $row['AUTH_BY'])];
    }
    return ['Tenure Change', [
        'Company' => $company['FINENT'] ?? '', 'Employee' => $employee,
        'From Date' => $from ? reportsShowDate($from) : 'NA', 'To Date' => $to ? reportsShowDate($to) : 'NA',
    ], $columns, $data, 'Tenure_Change_' . ($company['FINENT'] ?? '') . '_' . $employee];
});
