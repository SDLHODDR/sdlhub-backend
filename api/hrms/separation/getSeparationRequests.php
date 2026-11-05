<?php
/*
 * GET            -> { requests: [...], employees: [...] }  (Separation Request page)
 * GET ?id=<id>   -> { request: {...} }
 * Legacy separation_edit.php (no task): separations of employees within the
 * user's company / division / department scope.
 */
require_once __DIR__ . '/../authorization/exit/exitCommon.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new ExitRequestException('Invalid request method.', 405);
    }
    loadHrmsAccess($empCode);
    if (!hrmsHasScope()) {
        apiResponse(true, 'No company / division / department access is configured for your profile.',
            ['requests' => [], 'employees' => []], 200);
        exit;
    }
    $scope = exitScopeSql('C', 'E');

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $request = exitRecord("SELECT S.ID, S.EMP_CODE, S.RESIGN_TYPE, S.RESIGN_MODE, S.RESIGN_RCP_EMAIL,
                TO_CHAR(S.RESIGN_RCP_DATE, 'YYYY-MM-DD') AS RESIGN_RCP_DATE,
                TO_CHAR(S.EXP_RELIV_DATE, 'YYYY-MM-DD') AS EXP_RELIV_DATE,
                S.EMP_COMMENTS, S.STATUS,
                TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' ')) || ' - ' || E.EMP_CODE AS EMP_LABEL
            FROM HR_EMP_SEPARATION S
            INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = S.EMP_CODE
            WHERE S.ID = :id
              AND EXISTS (SELECT 1 FROM HR_EMP_OFFICE_DET C WHERE C.EMP_CODE = S.EMP_CODE AND {$scope})",
            [':id' => (string)$id]);
        if (empty($request['ID'])) {
            throw new ExitRequestException('Separation request not found.', 404);
        }
        apiResponse(true, 'Separation request fetched successfully.', ['request' => [
            'id' => $request['ID'],
            'empCode' => $request['EMP_CODE'],
            'empLabel' => $request['EMP_LABEL'],
            'resignType' => $request['RESIGN_TYPE'],
            'resignMode' => $request['RESIGN_MODE'],
            'recipientEmail' => $request['RESIGN_RCP_EMAIL'] ?? '',
            'resignationDate' => $request['RESIGN_RCP_DATE'],
            'expectedRelievingDate' => $request['EXP_RELIV_DATE'],
            'employeeComments' => $request['EMP_COMMENTS'] ?? '',
            'status' => $request['STATUS'],
        ]], 200);
        exit;
    }

    $requests = multiRec("SELECT S.ID, S.EMP_CODE, S.STATUS,
            TO_CHAR(S.RESIGN_RCP_DATE, 'DD-Mon-YYYY') AS RESIGN_RCP_DATE,
            TO_CHAR(S.EXP_RELIV_DATE, 'DD-Mon-YYYY') AS EXP_RELIV_DATE,
            TO_CHAR(S.RESIGN_RCP_DATE, 'YYYY-MM-DD') AS RESIGN_RCP_ISO,
            TO_CHAR(S.EXP_RELIV_DATE, 'YYYY-MM-DD') AS EXP_RELIV_ISO,
            TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' ')) AS EMP_NAME,
            TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ,
            TO_CHAR(E.DOJ, 'YYYY-MM-DD') AS DOJ_ISO,
            O.DIVISION, O.DEPARTMENT, O.DESIGNATION
        FROM HR_EMP_SEPARATION S
        INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = S.EMP_CODE
        LEFT JOIN (
            SELECT EMP_CODE, GET_DIVISION_NAME(DIVSN_ID) AS DIVISION, GET_DEPT_NAME(DEPT_ID) AS DEPARTMENT,
                   GET_DESIGN_NAME(DESI_ID) AS DESIGNATION,
                   ROW_NUMBER() OVER (PARTITION BY EMP_CODE ORDER BY ID DESC) AS RN
            FROM HR_EMP_OFFICE_DET
        ) O ON O.EMP_CODE = S.EMP_CODE AND O.RN = 1
        WHERE EXISTS (SELECT 1 FROM HR_EMP_OFFICE_DET C WHERE C.EMP_CODE = S.EMP_CODE AND {$scope})
        ORDER BY S.STATUS DESC, S.ID DESC") ?: [];

    // Active employees in scope who can have a separation raised (legacy employee dropdown).
    $employees = multiRec("SELECT DISTINCT A.EMP_CODE,
            TRIM(REGEXP_REPLACE(A.FNAME || ' ' || A.MNAME || ' ' || A.LNAME, ' +', ' ')) || ' - ' || A.EMP_CODE AS LABEL
        FROM HR_EMPLOYEE_INFO A
        INNER JOIN HR_EMP_OFFICE_DET C ON C.EMP_CODE = A.EMP_CODE
        WHERE A.STATUS = 'A' AND SYSDATE BETWEEN C.EFFEC_FROM AND NVL(C.EFFEC_TO, DATE '3000-03-01')
          AND " . exitScopeSql('C', 'A') . "
        ORDER BY 2") ?: [];

    apiResponse(true, 'Separation requests fetched successfully.', [
        'requests' => array_map(static fn($row) => [
            'id' => $row['ID'],
            'empCode' => $row['EMP_CODE'],
            'empName' => $row['EMP_NAME'],
            'doj' => $row['DOJ'],
            'dojIso' => $row['DOJ_ISO'],
            'resignationDateIso' => $row['RESIGN_RCP_ISO'],
            'expectedRelievingDateIso' => $row['EXP_RELIV_ISO'],
            'divisionDepartmentDesignation' => implode(' - ', array_filter([$row['DIVISION'], $row['DEPARTMENT'], $row['DESIGNATION']])),
            'resignationDate' => $row['RESIGN_RCP_DATE'],
            'expectedRelievingDate' => $row['EXP_RELIV_DATE'],
            'status' => $row['STATUS'],
        ], $requests),
        'employees' => array_map(static fn($row) => [
            'value' => $row['EMP_CODE'],
            'label' => $row['LABEL'],
        ], $employees),
    ], 200);
} catch (ExitRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getSeparationRequests.php');
    apiResponse(false, 'Unable to load separation requests.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
