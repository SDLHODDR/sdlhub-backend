<?php
/* GET ?from_date=&to_date= -> Candidate wise Tracker PDF. The old report was broken (no filter page,
 * counted every log row); this lists each candidate of the requests raised in the period with the
 * date each stage was reached (latest HR_CAN_APP_DET_LOG entry of that status). */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('can_track', static function () {
    [$from, $to] = reportsPeriod();
    $stage = static fn($alias, $statuses) => "(SELECT TO_CHAR(MAX(LG.CHG_ON), 'DD-Mon-YY') FROM HR_CAN_APP_DET_LOG LG
        WHERE LG.CAN_APP_DET_ID = A.ID AND LG.STATUS IN ({$statuses})) AS {$alias}";
    $latest = static fn($alias, $statuses) => "(SELECT MAX(LG.STATUS) KEEP (DENSE_RANK LAST ORDER BY LG.CHG_ON, LG.ID) FROM HR_CAN_APP_DET_LOG LG
        WHERE LG.CAN_APP_DET_ID = A.ID AND LG.STATUS IN ({$statuses})) AS {$alias}";

    $rows = multiRec("SELECT A.ID, A.STATUS, R.ID AS REQ_ID, INITCAP(TRIM(C.CND_NAME)) AS CND_NAME,
            " . sprintf(REPORTS_REQUEST_DESC_SQL, 'R.ID') . " AS REQ_DESC,
            " . reportsEmpNameSql('R.ASSIGNED_TO') . " AS RECRUITER,
            " . $stage('D_SENT', "'N'") . ", " . $latest('S_DECIDED', "'A','R'") . ", " . $stage('D_DECIDED', "'A','R'") . ",
            " . $stage('D_SCHED', "'I'") . ", " . $stage('D_CONF', "'D'") . ", " . $stage('D_PANEL', "'S'") . ",
            " . $latest('S_RESULT', "'P','F','H'") . ", " . $stage('D_RESULT', "'P','F','H'") . ",
            " . $stage('D_OFFER', "'O'") . ", " . $stage('D_ACCEPT', "'J'") . ", " . $stage('D_EMP', "'E'") . "
        FROM HR_RECRUITMENT R
        INNER JOIN HR_CAN_APPLICATION_DETAILS A ON A.REQ_ID = R.ID AND A.STATUS IS NOT NULL
        INNER JOIN HR_CANDIDATE_INFO C ON C.ID = A.CAN_ID
        WHERE R.REQ_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD') AND R.REQ_DATE < TO_DATE(:to_date, 'YYYY-MM-DD') + 1
        ORDER BY R.ID, UPPER(C.CND_NAME)", [':from_date' => $from->format('Y-m-d'), ':to_date' => $to->format('Y-m-d')]) ?: [];

    $with = static fn($status, $date) => $date ? trim((REPORTS_APPLICATION_STATUSES[$status] ?? $status) . "\n" . $date) : '';
    $columns = [['#', 3, 'center'], ['Candidate', 9, 'left'], ['Job ID', 4, 'left'], ['Req. Description', 15, 'left'], ['Recruiter', 7, 'left'],
        ['Current Status', 7, 'left'], ['Sent', 6, 'center'], ['Shortlisted / Rejected', 7, 'center'], ['Int. Scheduled', 6, 'center'],
        ['Int. Confirmed', 6, 'center'], ['Interviewer Assigned', 6, 'center'], ['Result', 6, 'center'], ['Offer Sent', 6, 'center'],
        ['Offer Accepted', 6, 'center'], ['Employed', 6, 'center']];
    $data = [];
    foreach ($rows as $index => $row) {
        $data[] = [$index + 1, $row['CND_NAME'] ?? '', '#' . $row['REQ_ID'], $row['REQ_DESC'] ?? '', $row['RECRUITER'] ?? '',
            REPORTS_APPLICATION_STATUSES[$row['STATUS']] ?? $row['STATUS'], $row['D_SENT'] ?? '',
            $with($row['S_DECIDED'] ?? '', $row['D_DECIDED'] ?? ''), $row['D_SCHED'] ?? '', $row['D_CONF'] ?? '', $row['D_PANEL'] ?? '',
            $with($row['S_RESULT'] ?? '', $row['D_RESULT'] ?? ''), $row['D_OFFER'] ?? '', $row['D_ACCEPT'] ?? '', $row['D_EMP'] ?? ''];
    }
    return ['Candidate wise Tracker', ['From Date' => reportsShowDate($from), 'To Date' => reportsShowDate($to)],
        $columns, $data, 'Candidate_Tracker_' . reportsShowDate($from) . '_' . reportsShowDate($to)];
});
