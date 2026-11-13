<?php
/* GET ?from_date=&to_date= -> Requisition wise Tracker PDF. Legacy reports/admin.php req_track():
 * per request raised in the period (not rejected), its candidates counted by current status. */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('req_track', static function () {
    [$from, $to] = reportsPeriod();
    $rows = multiRec("SELECT R.ID, TO_CHAR(R.REQ_DATE, 'DD-Mon-YY') AS REQ_DATE, R.STATUS,
            " . sprintf(REPORTS_REQUEST_DESC_SQL, 'R.ID') . " AS REQ_DESC,
            " . reportsEmpNameSql('R.ASSIGNED_TO') . " AS RECRUITER,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID) AS TOTAL,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'N') AS CNT_N,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'A') AS CNT_A,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'R') AS CNT_R,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'I') AS CNT_I,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'C') AS CNT_C,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'O') AS CNT_O,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'J') AS CNT_J,
            (SELECT COUNT(*) FROM HR_CAN_APPLICATION_DETAILS A WHERE A.REQ_ID = R.ID AND A.STATUS = 'E') AS CNT_E,
            (SELECT COUNT(*) FROM HR_INTRV_EMPLOYEE IE WHERE IE.REQ_ID = R.ID AND IE.INTRVER_TYPE = 'I' AND IE.PASS_FAIL IS NOT NULL) AS INT_DONE,
            (SELECT COUNT(*) FROM HR_INTRV_EMPLOYEE IE WHERE IE.REQ_ID = R.ID AND IE.INTRVER_TYPE = 'R' AND IE.PASS_FAIL IS NOT NULL) AS RECO_DONE
        FROM HR_RECRUITMENT R
        WHERE R.REQ_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD') AND R.REQ_DATE < TO_DATE(:to_date, 'YYYY-MM-DD') + 1
          AND R.STATUS <> 'R'
        ORDER BY R.REQ_DATE, R.ID", [':from_date' => $from->format('Y-m-d'), ':to_date' => $to->format('Y-m-d')]) ?: [];

    $columns = [['#', 3, 'center'], ['Req. Date', 6, 'left'], ['Job ID', 4, 'left'], ['Req. Description', 11, 'left'], ['Req. Status', 6, 'left'],
        ['Recruiter', 7, 'left'], ['Total Applicants', 6, 'center'], ['Yet to Shortlist / Reject', 5, 'center'], ['Total Shortlisted', 6, 'center'],
        ['Total Rejected', 5, 'center'], ['Int. Scheduled', 6, 'center'], ['Int. Confirmation Pending', 7, 'center'], ['Interviewed', 6, 'center'],
        ['Final Round Interviewed', 6, 'center'], ['Offer Letter', 4, 'center'], ['Offer Confirmed', 6, 'center'], ['Employed', 6, 'center']];
    $data = [];
    foreach ($rows as $index => $row) {
        $data[] = [$index + 1, $row['REQ_DATE'], '#' . $row['ID'], $row['REQ_DESC'] ?? '',
            REPORTS_REQUEST_STATUSES[$row['STATUS']] ?? $row['STATUS'], $row['RECRUITER'] ?? '', $row['TOTAL'], $row['CNT_N'], $row['CNT_A'],
            $row['CNT_R'], $row['CNT_I'], max(0, (int)$row['CNT_I'] - (int)$row['CNT_C']), $row['INT_DONE'], $row['RECO_DONE'],
            $row['CNT_O'], $row['CNT_J'], $row['CNT_E']];
    }
    return ['Requisition wise Tracker', ['From Date' => reportsShowDate($from), 'To Date' => reportsShowDate($to)],
        $columns, $data, 'Req_Tracker_' . reportsShowDate($from) . '_' . reportsShowDate($to)];
});
