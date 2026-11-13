<?php
/* GET ?comp_id=[&divsn_id=&dept_id=] -> Open Position PDF. The old report never produced rows; this lists
 * every current organogram location with nobody in it:
 *   Vacant Since   day after the last occupant left, or the location's start date if never filled
 *   Fill Req.      latest open recruitment request for the location (not cancelled / rejected / closed)
 *   Tentative Joining  of that request's accepted offer (date entered in Update Offer Acceptance, else the offer's). */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('openpositions', static function () {
    $compId = reportsId('comp_id', 'company');
    $divsnId = reportsOptionalId('divsn_id', 'division');
    $deptId = reportsOptionalId('dept_id', 'department');
    $binds = [':comp_id' => $compId];
    $where = 'HO.COMPANY = :comp_id';
    if ($divsnId) {
        $where .= ' AND HO.DIVSN_ID = :divsn_id';
        $binds[':divsn_id'] = $divsnId;
    }
    if ($deptId) {
        $where .= ' AND HO.DEPT_ID = :dept_id';
        $binds[':dept_id'] = $deptId;
    }

    $rows = multiRec("SELECT V.ID, V.GEO_DESC, V.DESI, V.REQ_ID, V.ENTER_BY_EMP,
            TO_CHAR(V.VACANT_SINCE, 'DD-Mon-YYYY') AS VACANT_SINCE, TO_CHAR(V.REQ_DATE, 'DD-Mon-YYYY') AS REQ_DATE,
            TO_CHAR(V.JOIN_DATE, 'DD-Mon-YYYY') AS JOIN_DATE,
            TRUNC(SYSDATE) - V.VACANT_SINCE AS VACANT_DAYS,
            TRUNC(V.REQ_DATE) - V.VACANT_SINCE AS DAYS_TILL_REQ,
            TRUNC(V.JOIN_DATE) - V.VACANT_SINCE AS DAYS_TILL_JOIN
        FROM (
            SELECT HOL.ID, HOL.GEO_DESC, GET_DESIGN_NAME(HO.DESI_ID) AS DESI,
                NVL((SELECT MAX(D.EFFEC_TO) + 1 FROM HR_EMP_OFFICE_DET D WHERE D.ORG_LOC_ID = HOL.ID), TRUNC(HOL.EFFEC_FROM)) AS VACANT_SINCE,
                RQ.ID AS REQ_ID, RQ.REQ_DATE, RQ.ENTER_BY_EMP,
                (SELECT MAX(NVL(OF1.OFFR_ACCEPT_DATE, OF1.TNT_JOIN_DATE)) FROM HR_OFFERS OF1
                  WHERE OF1.REQ_ID = RQ.ID AND OF1.OFFR_ACCEPTED = 'Y') AS JOIN_DATE
            FROM HR_ORGANOGRAM_LOC HOL
            INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HOL.ORG_ID
            LEFT JOIN (SELECT R.*, ROW_NUMBER() OVER (PARTITION BY R.ORG_LOC_ID ORDER BY R.REQ_DATE DESC, R.ID DESC) AS RN
                       FROM HR_RECRUITMENT R WHERE R.STATUS NOT IN ('X', 'R', 'C')) RQ ON RQ.ORG_LOC_ID = HOL.ID AND RQ.RN = 1
            WHERE {$where}
              AND SYSDATE BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
              AND NOT EXISTS (SELECT 1 FROM HR_EMP_OFFICE_DET D WHERE D.ORG_LOC_ID = HOL.ID
                    AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01'))
        ) V
        ORDER BY V.DESI, V.GEO_DESC", $binds) ?: [];

    $names = reportsRow("SELECT (SELECT COMP_DESC FROM HR_COMPANY WHERE COMP_ID = :comp_id) AS CMP,
            (SELECT DIVSN_DESC FROM HR_DIVISIONS WHERE DIVSN_ID = :divsn_id) AS DIV,
            (SELECT DEPT_DESC FROM HR_DEPARTMENT WHERE DEPT_ID = :dept_id) AS DEPT FROM DUAL",
        [':comp_id' => $compId, ':divsn_id' => (string)$divsnId, ':dept_id' => (string)$deptId]);
    $requesters = [];

    $columns = [['#', 3, 'center'], ['Designation', 15, 'left'], ['Location', 13, 'left'], ['Vacant Since', 8, 'left'], ['Vacant Days', 7, 'center'],
        ['Fill Req., Date', 11, 'left'], ['Request By', 13, 'left'], ['Vacant Days till Request', 10, 'center'],
        ['Tentative Joining Date', 10, 'left'], ['Vacant Days till Joining', 10, 'center']];
    $data = [];
    foreach ($rows as $index => $row) {
        $by = (string)($row['ENTER_BY_EMP'] ?? '');
        if ($by !== '' && !isset($requesters[$by])) {
            $requesters[$by] = reportsRow("SELECT INITCAP(TRIM(FNAME || ' ' || LNAME)) AS NM FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code",
                [':code' => $by])['NM'] ?? $by;
        }
        $data[] = [$index + 1, $row['DESI'] ?? '', $row['GEO_DESC'] ?? '', ($row['VACANT_SINCE'] ?? ''), $row['VACANT_DAYS'],
            $row['REQ_ID'] ? '#' . $row['REQ_ID'] . ', ' . $row['REQ_DATE'] : '', $by !== '' ? $requesters[$by] : '',
            $row['REQ_ID'] ? $row['DAYS_TILL_REQ'] : '', ($row['JOIN_DATE'] ?? ''), $row['JOIN_DATE'] ? $row['DAYS_TILL_JOIN'] : ''];
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('d-M-Y');
    return ['Open Position As On ' . $today, [
        'Company' => $names['CMP'] ?? '', 'Department' => $deptId ? ($names['DEPT'] ?? '') : 'ALL', 'Division' => $divsnId ? ($names['DIV'] ?? '') : 'ALL',
    ], $columns, $data, 'Open_Positions_' . ($names['CMP'] ?? '')];
});
