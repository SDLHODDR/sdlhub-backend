<?php
/*
 * GET. New Joinee list (legacy new_joinee.php): selected candidates (application
 * status J) with a tentative joining date, in the user's company scope.
 */
require_once __DIR__ . '/joiningCommon.php';

try {
    loadHrmsAccess($empCode);
    $companies = implode(',', array_map('intval', joiningUserCompanies())) ?: '0';

    $rows = joiningRecords("SELECT A.ID AS APP_ID, A.REQ_ID, A.ORG_ID, B.CND_NAME,
            GET_SHCOMP_NAME(E.COMPANY) || ' - ' || GET_DIVISION_NAME(E.DIVSN_ID) || ' - '
                || GET_DEPT_NAME(E.DEPT_ID) || ' - ' || GET_DESIGN_NAME(E.DESI_ID) AS ORGANOGRAM,
            GET_ORG_LOC_NAME(D.ORG_LOC_ID) AS ORG_LOCATION,
            TO_CHAR(C.TNT_JOIN_DATE, 'YYYY-MM-DD') AS TENTATIVE_ISO,
            TO_CHAR(C.TNT_JOIN_DATE, 'DD-Mon-YYYY') AS TENTATIVE_DATE,
            TO_CHAR(A.JOINING_DATE, 'YYYY-MM-DD') AS JOINING_ISO,
            A.JOINING_LOCATION, A.JOINING_REMARKS
        FROM HR_CAN_APPLICATION_DETAILS A
        INNER JOIN HR_CANDIDATE_INFO B ON B.ID = A.CAN_ID
        INNER JOIN HR_INTRV_DATES C ON C.ID = (
            SELECT MAX(X.ID) FROM HR_INTRV_DATES X WHERE X.CAN_ID = A.CAN_ID AND X.TNT_JOIN_DATE IS NOT NULL)
        INNER JOIN HR_RECRUITMENT D ON D.ID = A.REQ_ID
        INNER JOIN HR_ORGANOGRAM E ON E.ID = A.ORG_ID
        WHERE A.STATUS = 'J' AND E.COMPANY IN ({$companies})
        ORDER BY C.TNT_JOIN_DATE DESC, A.ID DESC");

    apiResponse(true, 'New joinees fetched successfully.', array_map(static fn($row) => [
        'appId' => $row['APP_ID'],
        'reqId' => $row['REQ_ID'],
        'name' => ucwords(strtolower((string)$row['CND_NAME'])),
        'organogram' => $row['ORGANOGRAM'],
        'orgLocation' => $row['ORG_LOCATION'],
        'tentativeIso' => $row['TENTATIVE_ISO'],
        'tentativeDate' => $row['TENTATIVE_DATE'],
        'joiningIso' => $row['JOINING_ISO'] ?? '',
        'joiningLocation' => $row['JOINING_LOCATION'] ?? '',
        'joiningRemarks' => $row['JOINING_REMARKS'] ?? '',
    ], $rows), 200);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getNewJoinees.php');
    apiResponse(false, 'Unable to load the new joinees.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
