<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json; charset=utf-8');

function referenceResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function referenceQuery(string $sql, array $binds = []): array
{
    global $sql___func___con;

    $stmt = oci_parse($sql___func___con, $sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare database query.');
    }

    foreach ($binds as $key => &$value) {
        oci_bind_by_name($stmt, $key, $value);
    }
    unset($value);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException($error['message'] ?? 'Database query failed.');
    }

    $rows = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $rows[] = $row;
    }

    oci_free_statement($stmt);

    return $rows;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        referenceResponse(405, [
            'status' => false,
            'success' => false,
            'message' => 'Only POST requests are allowed.'
        ]);
    }

    $loggedInEmp = trim((string)(
        $_SESSION['emp_code'] ??
        $_SESSION['EmpCode'] ??
        ''
    ));

    if ($loggedInEmp === '') {
        referenceResponse(401, [
            'status' => false,
            'success' => false,
            'message' => 'Your session has expired. Please log in again.'
        ]);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $empCode = trim((string)($input['EMP_CODE'] ?? ''));
    $tenureId = filter_var($input['TENURE_CHG_ID'] ?? '', FILTER_VALIDATE_INT);

    if ($empCode === '') {
        referenceResponse(400, [
            'status' => false,
            'success' => false,
            'message' => 'Employee code is required.'
        ]);
    }

    /*
     * 1. Retrieve the latest Tenure record if ID is not passed, or lock the specific record
     */
    if ($tenureId) {
        $tenureRows = referenceQuery("
            SELECT ID, EMP_CODE, ETYPE_ID, STATUS
            FROM HR_EMP_TENURE_CHANGE
            WHERE ID = :tenure_id AND EMP_CODE = :emp_code
        ", [
            ':tenure_id' => $tenureId,
            ':emp_code' => $empCode
        ]);
    } else {
        $tenureRows = referenceQuery("
            SELECT ID, EMP_CODE, ETYPE_ID, STATUS
            FROM HR_EMP_TENURE_CHANGE
            WHERE EMP_CODE = :emp_code
            ORDER BY CASE WHEN STATUS = 'A' THEN 1 ELSE 2 END, ID DESC
        ", [
            ':emp_code' => $empCode
        ]);
    }

    if (!$tenureRows) {
        referenceResponse(404, [
            'status' => false,
            'success' => false,
            'message' => 'Tenure change record was not found for this employee.'
        ]);
    }

    $etypeId = (string)$tenureRows[0]['ETYPE_ID'];

    // Map ETYPE_ID: 1 -> Doc 14 (Confirmation), 2, 5, 6 -> Doc 16 (Extension)
    $documentMap = [
        '1' => '14',
        '6' => '16',
        '2' => '16',
        '5' => '16'
    ];

    $documentId = $documentMap[$etypeId] ?? '16';
    $isConfirmation = ($documentId === '14');

    /*
     * 2. Check if a Reference Code already exists to prevent duplicate generation
     */
    $existingRows = referenceQuery("
        SELECT DOC_REF
        FROM (
            SELECT ID, DOC_REF
            FROM HR_EMP_DOCS
            WHERE EMP_CODE = :emp_code
              AND DOC_ID = :doc_id
              AND DOC_REF IS NOT NULL
            ORDER BY ID DESC
        )
        WHERE ROWNUM = 1
    ", [
        ':emp_code' => $empCode,
        ':doc_id' => $documentId
    ]);

    if (!empty($existingRows[0]['DOC_REF'])) {
        referenceResponse(200, [
            'status' => true,
            'success' => true,
            'message' => 'The letter reference already exists.',
            'document_reference' => $existingRows[0]['DOC_REF'],
            'document_id' => $documentId,
            'already_exists' => true
        ]);
    }

    /*
     * 3. Fetch Session Employee Info (Work Site & Dept Code)
     */
    $sessionRows = referenceQuery("
        SELECT work_site, dept_code 
        FROM epplive.bcs_employee 
        WHERE emp_code = :emp_code
    ", [
        ':emp_code' => $loggedInEmp
    ]);

    $workSite = trim((string)($sessionRows[0]['WORK_SITE'] ?? ''));
    $userDeptCode = trim((string)($sessionRows[0]['DEPT_CODE'] ?? ''));

    if ($workSite === '' || $userDeptCode === '') {
        referenceResponse(422, [
            'status' => false,
            'success' => false,
            'message' => 'Your employee account lacks a valid Work Site or Department code in EPP.'
        ]);
    }

    /*
     * 4. Fetch Department Short Code
     */
    $deptRows = referenceQuery("
        SELECT SHORT_CODE 
        FROM HR_DEPARTMENT 
        WHERE DEPT_CODE = :dept_code
    ", [
        ':dept_code' => $userDeptCode
    ]);

    $deptShortCode = trim((string)($deptRows[0]['SHORT_CODE'] ?? ''));

    /*
     * 5. Fetch Accounting Period Prefix
     */
    $finRows = referenceQuery("
        SELECT to_char(fr_date,'dd-Mon-rrrr') AS frdt,
               to_char(to_date,'dd-Mon-rrrr') AS trdt,
               doc_prefix
        FROM hr_bcs_acct_period
        WHERE code = (
            SELECT acct_prd 
            FROM hr_bcs_period 
            WHERE TRUNC(SYSDATE) BETWEEN fr_date AND to_date
        )
    ");

    $docPrefix = trim((string)($finRows[0]['DOC_PREFIX'] ?? ''));

    if ($docPrefix === '') {
        referenceResponse(422, [
            'status' => false,
            'success' => false,
            'message' => 'No active financial accounting period / prefix found.'
        ]);
    }

    /*
     * 6. Fetch Sequence & Build Reference Number (my/SITE/DEPT/LTTR/SEQ)
     */
    $sequenceName = "epplive.TRAN_OR_" . $workSite . "_" . $docPrefix . "_SEQ";
    
    // Validate identifier characters against SQL injection before running nextval
    if (!preg_match('/^[A-Za-z0-9_]+$/', $workSite) || !preg_match('/^[A-Za-z0-9_]+$/', $docPrefix)) {
        throw new RuntimeException('Invalid sequence parameter.');
    }

    $seqRows = referenceQuery("SELECT " . $sequenceName . ".NEXTVAL AS SEQNO FROM DUAL");
    $seqNo = $seqRows[0]['SEQNO'] ?? '';

    if ($seqNo === '') {
        throw new RuntimeException('Failed to retrieve next sequence value from ' . $sequenceName);
    }

    $refNo = date('my') . '/' . $workSite . '/' . $deptShortCode . '/LTTR/' . $seqNo;

    /*
     * 7. Fetch Candidate/Employee Profile Info for Outward Register
     */
    $empInfoRows = referenceQuery("
        SELECT ID, EMP_CODE, CITY 
        FROM HR_EMPLOYEE_INFO 
        WHERE EMP_CODE = :emp_code
    ", [
        ':emp_code' => $empCode
    ]);

    $empId = $empInfoRows[0]['ID'] ?? null;
    $empCity = $empInfoRows[0]['CITY'] ?? '';
    
    // Use HR helper function if available, otherwise blank
    $empFullName = function_exists('getEmpInfoByCode') ? getEmpInfoByCode($empCode) : $empCode;

    $letterDescription = $isConfirmation
        ? 'CONFIRMATION LETTER - ' . strtoupper($empFullName)
        : 'EXTENSION LETTER - ' . strtoupper($empFullName);

    $docPath = $isConfirmation
        ? 'input/emp_docs/Confirmation_Letter_' . $empId . '.pdf'
        : 'input/emp_docs/Extension_Letter_' . $empId . '.pdf';

    /*
     * 8. Insert into BCS_OUTWARD_REF (Outward Dispatch Register)
     */
    $insertOutwardSql = "
        INSERT INTO epplive.BCS_OUTWARD_REF (
            ID, OUTWARD_DATE, OUTWARD_TYPE, DOC_REF, DEPT_CODE, 
            OUTWARD_FROM, OUTWARD_TO, PLACE, REMARKS, 
            CHG_ON, CHG_BY, SITE_CODE
        ) VALUES (
            NULL, TRUNC(SYSDATE), 'LTTR', :doc_ref, :dept_code,
            :outward_from, :outward_to, :place, :remarks,
            SYSDATE, :chg_by, :site_code
        )
    ";

    $stmtOutward = oci_parse($sql___func___con, $insertOutwardSql);
    if (!$stmtOutward) {
        throw new RuntimeException('Failed to prepare Outward Reference statement.');
    }

    $upperLoggedIn = trim(strtoupper($loggedInEmp));
    $upperWorkSite = trim(strtoupper($workSite));

    oci_bind_by_name($stmtOutward, ':doc_ref', $refNo);
    oci_bind_by_name($stmtOutward, ':dept_code', $userDeptCode);
    oci_bind_by_name($stmtOutward, ':outward_from', $loggedInEmp);
    oci_bind_by_name($stmtOutward, ':outward_to', $empFullName);
    oci_bind_by_name($stmtOutward, ':place', $empCity);
    oci_bind_by_name($stmtOutward, ':remarks', $letterDescription);
    oci_bind_by_name($stmtOutward, ':chg_by', $upperLoggedIn);
    oci_bind_by_name($stmtOutward, ':site_code', $upperWorkSite);

    if (!oci_execute($stmtOutward, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtOutward);
        oci_free_statement($stmtOutward);
        throw new RuntimeException($err['message'] ?? 'Failed to insert into BCS_OUTWARD_REF');
    }
    oci_free_statement($stmtOutward);

    /*
     * 9. Insert into HR_EMP_DOCS
     */
    $insertDocSql = "
        INSERT INTO HR_EMP_DOCS (
            ID, EMP_ID, EMP_CODE, DOC_ID, DOC_PATH, 
            DOC_REF, CC_TO, CHG_ON, CHG_BY
        ) VALUES (
            NULL, :emp_id, :emp_code, :doc_id, :doc_path,
            :doc_ref, '', SYSDATE, :chg_by
        )
    ";

    $stmtDoc = oci_parse($sql___func___con, $insertDocSql);
    if (!$stmtDoc) {
        throw new RuntimeException('Failed to prepare HR Employee Document statement.');
    }

    oci_bind_by_name($stmtDoc, ':emp_id', $empId);
    oci_bind_by_name($stmtDoc, ':emp_code', $empCode);
    oci_bind_by_name($stmtDoc, ':doc_id', $documentId);
    oci_bind_by_name($stmtDoc, ':doc_path', $docPath);
    oci_bind_by_name($stmtDoc, ':doc_ref', $refNo);
    oci_bind_by_name($stmtDoc, ':chg_by', $loggedInEmp);

    if (!oci_execute($stmtDoc, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtDoc);
        oci_free_statement($stmtDoc);
        throw new RuntimeException($err['message'] ?? 'Failed to insert into HR_EMP_DOCS');
    }
    oci_free_statement($stmtDoc);

    // Commit transaction
    oci_commit($sql___func___con);

    /*
     * 10. Success Response
     */
    referenceResponse(200, [
        'status' => true,
        'success' => true,
        'message' => 'Reference Code generated successfully.',
        'data' => [
            'document_reference' => $refNo,
            'document_id' => $documentId,
            'emp_code' => $empCode,
            'doc_path' => $docPath
        ]
    ]);

} catch (Throwable $e) {
    if (isset($sql___func___con) && $sql___func___con) {
        @oci_rollback($sql___func___con);
    }

    if (function_exists('logOracleError')) {
        logOracleError($e->getMessage());
    }

    referenceResponse(500, [
        'status' => false,
        'success' => false,
        'message' => 'Unable to generate reference code: ' . $e->getMessage()
    ]);
}