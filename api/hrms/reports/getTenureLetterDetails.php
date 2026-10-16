<?php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/hr_func.php';

header('Content-Type: application/json; charset=utf-8');

function tenureDetailsResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function tenureQuery(string $sql, array $binds = []): array
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        tenureDetailsResponse(405, [
            'status' => false,
            'message' => 'Only GET requests are allowed.'
        ]);
    }

    $empCode = trim((string)(
        $_GET['EMP_CODE'] ??
        $_GET['REC_ID'] ??
        ''
    ));

    $tenureChangeId = filter_var(
        $_GET['TENURE_CHG_ID'] ?? '',
        FILTER_VALIDATE_INT
    );

    if ($empCode === '') {
        tenureDetailsResponse(400, [
            'status' => false,
            'message' => 'Employee code is required.'
        ]);
    }

    /*
     * 1. ALWAYS Fetch Core Employee Details First (DOJ, Designation, Dept, Name)
     */
    $empOffice = function_exists('getEmpOffice') ? getEmpOffice($empCode) : [];
    $empName   = function_exists('getEmpInfoByCode') ? getEmpInfoByCode($empCode) : '';

    $locRows = tenureQuery("
        SELECT get_org_loc_name(ORG_LOC_ID) AS LOC 
        FROM hr_emp_office_det 
        WHERE emp_code = :emp_code 
          AND SYSDATE BETWEEN effec_from AND NVL(effec_to, '01-Mar-3000')
          AND ROWNUM = 1
    ", [':emp_code' => $empCode]);

    $locationName = $locRows[0]['LOC'] ?? ($empOffice['LOC'] ?? '');

    // Current Tenure / Employee Type from HR_EMP_TENURE
    $curTenureRows = tenureQuery("
        SELECT c.emp_type, c.etype_id, a.etype_period, DDMONYYYY(a.eff_to) AS tdate
        FROM hr_emp_tenure a 
        INNER JOIN hr_emp_type c ON c.etype_id = a.etype_id
        WHERE a.emp_code = :emp_code
          AND ROWNUM = 1
    ", [':emp_code' => $empCode]);

    $curTenure = $curTenureRows[0] ?? [];

    // Current CTC calculation from function
    $ctcRow = tenureQuery("SELECT get_total_ctc(:emp_code, SYSDATE) AS VAL FROM dual", [':emp_code' => $empCode]);
    $defaultCtc = $ctcRow[0]['VAL'] ?? 0;

    /*
     * 2. Try Fetching HR_EMP_TENURE_CHANGE (Approved 'A' or Any Status)
     */
    $tenureSql = "
        SELECT
            A.ID AS TENURE_CHANGE_ID,
            A.EMP_CODE,
            A.EFF_FROM,
            DDMONYYYY(A.EFF_FROM) AS EFF_FROM_DISPLAY,
            A.ETYPE_ID,
            B.EMP_TYPE,
            A.ETYPE_PERIOD,
            A.CURR_CTC,
            A.PROP_CTC,
            A.CTC_EFF_FROM,
            DDMONYYYY(A.CTC_EFF_FROM) AS CTC_EFF_FROM_DISPLAY,
            A.REMARKS,
            A.STATUS
        FROM HR_EMP_TENURE_CHANGE A
        LEFT JOIN HR_EMP_TYPE B
            ON B.ETYPE_ID = A.ETYPE_ID
        WHERE A.EMP_CODE = :emp_code
        ORDER BY CASE WHEN A.STATUS = 'A' THEN 1 ELSE 2 END, A.ID DESC
    ";

    $tenureRows = tenureQuery($tenureSql, [':emp_code' => $empCode]);
    $tenure = $tenureRows[0] ?? [];

    /*
     * 3. Fallbacks if NO approved tenure record exists
     */
    $tenureChgId   = $tenure['TENURE_CHANGE_ID'] ?? '';
    $empType       = !empty($tenure['EMP_TYPE']) ? $tenure['EMP_TYPE'] : ($curTenure['EMP_TYPE'] ?? 'N/A');
    $etypeId       = !empty($tenure['ETYPE_ID']) ? (string)$tenure['ETYPE_ID'] : (string)($curTenure['ETYPE_ID'] ?? '1');
    $etypePeriod   = $tenure['ETYPE_PERIOD'] ?? ($curTenure['ETYPE_PERIOD'] ?? '0');
    $effDueDate    = $tenure['EFF_FROM_DISPLAY'] ?? ($curTenure['TDATE'] ?? '');
    $currCtc       = !empty($tenure['CURR_CTC']) ? $tenure['CURR_CTC'] : $defaultCtc;
    $propCtc       = !empty($tenure['PROP_CTC']) ? $tenure['PROP_CTC'] : $currCtc;
    $ctcEffFrom    = $tenure['CTC_EFF_FROM_DISPLAY'] ?? date('d-M-Y');
    $remarks       = $tenure['REMARKS'] ?? '';

    /*
     * 4. Check Document Reference (Confirmation 14 or Extension 16)
     */
    $docRows = tenureQuery("
        SELECT DOC_ID, DOC_REF
        FROM (
            SELECT ID, DOC_ID, DOC_REF
            FROM HR_EMP_DOCS
            WHERE EMP_CODE = :emp_code
              AND DOC_ID IN ('14', '16')
              AND DOC_REF IS NOT NULL
            ORDER BY ID DESC
        )
        WHERE ROWNUM = 1
    ", [':emp_code' => $empCode]);

    $docRef = $docRows[0]['DOC_REF'] ?? null;

    /*
     * 5. Check Pending Salary Approvals
     */
    $salaryRows = tenureQuery("
        SELECT COUNT(*) AS PENDING_COUNT
        FROM HR_BCS_EMPLOYEE_AD
        WHERE EMP_CODE = :emp_code
          AND CONFIRMED IN ('N', 'T')
    ", [':emp_code' => $empCode]);

    $pendingSalaryCount = (int)($salaryRows[0]['PENDING_COUNT'] ?? 0);

    /*
     * 6. Template Dropdown Options
     */
    $modesArr = [
        'P'    => 'Permanent',
        'E'    => 'Trainee Extension',
        'C'    => 'Contract Extension',
        'Pro'  => 'Probation Extension',
        'Prob' => 'Probation'
    ];

    /*
     * 7. Always Return Status 200 with Employee Details
     */
    tenureDetailsResponse(200, [
        'status'  => true,
        'success' => true,
        'message' => 'Employee details loaded.',
        'data'    => [
            'emp_code'         => $empCode,
            'emp_name'         => $empName,
            'doj'              => $empOffice['DOJ'] ?? '',
            'tenure_due_date'  => $effDueDate,
            'emp_type'         => $empType,
            'designation'      => $empOffice['DESIG'] ?? '',
            'department'       => $empOffice['DEPT'] ?? '',
            'emp_period'       => $etypePeriod,
            'curr_ctc'         => $currCtc,
            'ctc_eff_from'     => $ctcEffFrom,
            'prop_ctc'         => $propCtc,
            'location'         => $locationName,
            'remarks'          => $remarks,
            'tenure_chg_id'    => $tenureChgId,
            'etype_id'         => $etypeId,
            'doc_ref'          => $docRef,
            'salary_approved'  => $pendingSalaryCount === 0,
            'template_options' => $modesArr
        ]
    ]);

} catch (Throwable $e) {
    if (function_exists('logOracleError')) {
        logOracleError($e->getMessage());
    }

    tenureDetailsResponse(500, [
        'status'  => false,
        'success' => false,
        'message' => 'Unable to retrieve employee details: ' . $e->getMessage()
    ]);
}