<?php
/* GET ?emp_code=&from_date=YYYY-MM-DD&to_date=YYYY-MM-DD -> Follow Up Report PDF.
 * Legacy reports/admin.php followup(): the follow-ups an employee recorded in the period. */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('followup', static function () {
    $empCode = trim((string)($_GET['emp_code'] ?? ''));
    if ($empCode === '' || !preg_match('/^[A-Za-z0-9]{1,20}$/', $empCode)) {
        throw new ReportsException('Please select the employee.', 422);
    }
    $from = reportsDate('from_date', 'from date');
    $to = reportsDate('to_date', 'to date');
    if ($to < $from) {
        throw new ReportsException('To date cannot be before the from date.', 422);
    }
    $employee = reportsRow("SELECT TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) AS NM
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code", [':code' => $empCode]);
    if (empty($employee['NM'])) {
        throw new ReportsException('Employee not found.', 404);
    }

    // Legacy did not select the communication mode, so its column was always empty.
    $rows = multiRec("SELECT TO_CHAR(A.ASON_DATE, 'DD-Mon-YYYY') AS ASON_DATE, A.REQ_ID, A.REMARKS, M.FMOD_DESC,
            CASE WHEN A.CAN_ID IS NULL THEN A.CAN_NAME ELSE B.CND_NAME END AS NAME,
            CASE WHEN A.CAN_ID IS NULL THEN A.CAN_EMAIL ELSE B.CND_EMAIL END AS EMAIL,
            CASE WHEN A.CAN_ID IS NULL THEN A.CAN_MOBILE ELSE B.CND_MOBILE END AS MOBILE,
            (SELECT C.SH_DESC || ' - ' || DV.DIVSN_DESC || ' - ' || DP.DEPT_DESC || ' - ' || DS.DESI_DESC || ' - ' || L.GEO_DESC
               FROM HR_RECRUITMENT R
               INNER JOIN HR_ORGANOGRAM O ON O.ID = R.ORG_ID
               LEFT JOIN HR_COMPANY C ON C.COMP_ID = O.COMPANY
               LEFT JOIN HR_DIVISIONS DV ON DV.DIVSN_ID = R.DIVSN_ID
               LEFT JOIN HR_DEPARTMENT DP ON DP.DEPT_ID = R.DEPT_ID
               LEFT JOIN HR_DESIGNATION DS ON DS.DESI_ID = R.DESIG_ID
               LEFT JOIN HR_ORGANOGRAM_LOC L ON L.ID = R.ORG_LOC_ID
              WHERE R.ID = A.REQ_ID) AS REQ_DESC
        FROM HR_CAN_FOLLOWUP A
        LEFT JOIN HR_CANDIDATE_INFO B ON B.ID = A.CAN_ID
        LEFT JOIN HR_FOLLOWUP_MODE M ON M.FMOD_ID = A.FMOD_ID
        WHERE A.FOLOUP_BY = :emp_code
          AND A.ASON_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD') AND A.ASON_DATE < TO_DATE(:to_date, 'YYYY-MM-DD') + 1
        ORDER BY A.ASON_DATE, A.ID", [
        ':emp_code' => $empCode, ':from_date' => $from->format('Y-m-d'), ':to_date' => $to->format('Y-m-d'),
    ]) ?: [];

    $columns = [['#', 3, 'center'], ['Date', 8, 'left'], ['Job ID', 6, 'left'], ['Req. Description', 22, 'left'], ['Name', 11, 'left'],
        ['Mobile', 8, 'left'], ['Email', 13, 'left'], ['Communication Mode', 9, 'left'], ['Remarks', 20, 'left']];
    $data = [];
    foreach ($rows as $index => $row) {
        $data[] = [$index + 1, $row['ASON_DATE'], $row['REQ_ID'] ? '#' . $row['REQ_ID'] : '', $row['REQ_DESC'] ?? '', $row['NAME'] ?? '',
            $row['MOBILE'] ?? '', $row['EMAIL'] ?? '', $row['FMOD_DESC'] ?? '', $row['REMARKS'] ?? ''];
    }
    $name = ucwords(strtolower($employee['NM']));
    return ['Follow Up Report', ['Employee' => $name, 'From Date' => reportsShowDate($from), 'To Date' => reportsShowDate($to)],
        $columns, $data, 'Follow_Up_Report_' . $name . '_' . reportsShowDate($from) . '_' . reportsShowDate($to)];
});
