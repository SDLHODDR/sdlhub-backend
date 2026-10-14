<?php
//ini_set('display_errors', 0);
//error_reporting(E_ALL);
ob_start();

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/hr_func.php";

header("Content-Type: application/json; charset=UTF-8");

/* ===========================================
   HANDLE PREFLIGHT (CORS)
=========================================== */
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

try {
    /* ===========================================
       VALIDATE INPUTS & SESSION
    =========================================== */
    $empCode = '05625'; //trim($_REQUEST['REC_ID'] ?? $_REQUEST['emp_code'] ?? '');
    $taskId  = '35149'; //trim($_REQUEST['TASK_ID'] ?? $_REQUEST['task_id'] ?? '');

    if (empty($empCode)) {
        apiResponse(false, "Employee code (REC_ID) is required.", null, 400);
        exit;
    }

    /* ===========================================
       1. EMPLOYEE OFFICE & PROFILE DETAILS
    =========================================== */
    $res_emp = function_exists('getEmpOffice') ? getEmpOffice($empCode) : [];
    $empName = function_exists('getEmpInfoByCode') ? getEmpInfoByCode($empCode) : '';

    // Location description via Oracle function
    $locSql = "
        SELECT get_org_loc_name(ORG_LOC_ID) AS LOC 
        FROM HR_EMP_OFFICE_DET 
        WHERE EMP_CODE = :emp_code 
          AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, TO_DATE('01-MAR-3000', 'DD-MON-YYYY'))
    ";
    $res_req = singRec($locSql, [':emp_code' => $empCode]);
    $locationName = $res_req['LOC'] ?? '-';

    /* ===========================================
       2. LATEST APPROVED TENURE CHANGE RECORD (STATUS = 'A')
    =========================================== */
    $tenureChgSql = "
        SELECT 
            A.ID,
            DDMONYYYY(A.EFF_FROM) AS EFF_FROM,
            TO_CHAR(A.EFF_FROM, 'YYYY-MM-DD') AS EFF_FROM_ISO,
            A.ETYPE_ID,
            A.ETYPE_PERIOD,
            A.CURR_CTC,
            A.PROP_CTC,
            A.CTC_EFF_FROM,
            A.REMARKS,
            A.EMP_CODE,
            B.EMP_TYPE
        FROM HR_EMP_TENURE_CHANGE A 
        INNER JOIN HR_EMP_TYPE B ON A.ETYPE_ID = B.ETYPE_ID 
         WHERE A.EMP_CODE = '04942'
    "; 

    // WHERE A.EMP_CODE = :emp_code 
    //  AND A.STATUS = 'A'
    $tenure_chg = singRec($tenureChgSql, [':emp_code' => $empCode]);

    if (!$tenure_chg) {
        apiResponse(false, "No approved tenure change record found for this employee.", null, 404);
        exit;
    }

    /* ===========================================
       3. SALARY APPROVAL CHECK (EPP SYSTEM VERIFICATION)
    =========================================== */
    $salSql = "
        SELECT DISTINCT get_emp_name(EMP_CODE) AS EMP_NAME 
        FROM HR_BCS_EMPLOYEE_AD 
        WHERE EMP_CODE = :emp_code 
          AND CONFIRMED IN ('N', 'T')
    ";
    $isSalDone = multiRec($salSql, [':emp_code' => $empCode]);
    $pendingApprovers = !empty($isSalDone) ? array_filter(array_column($isSalDone, 'EMP_NAME')) : [];

    // If pending records exist, salary is NOT approved yet
    $salaryApproved = empty($pendingApprovers);

    /* ===========================================
       4. DOCUMENT & REFERENCE CODE VERIFICATION
    =========================================== */
    $docSql = "
        SELECT DOC_ID, DOC_REF, DOC_DATE, DOC_NAME 
        FROM HR_EMP_DOCS 
        WHERE EMP_CODE = :emp_code 
          AND DOC_ID IN ('14', '16')
    ";
    $docInfo = singRec($docSql, [':emp_code' => $empCode]);
    $hasDocReference = !empty(trim((string)($docInfo['DOC_REF'] ?? '')));

    /* ===========================================
       5. STAFF TYPE & PDF TEMPLATE MAPPING
    =========================================== */
    $staffTypeModes = [
        ['value' => 'P',    'label' => 'Permanent'],
        ['value' => 'E',    'label' => 'Trainee Extension'],
        ['value' => 'C',    'label' => 'Contract Extension'],
        ['value' => 'Pro',  'label' => 'Probation Extension'],
        ['value' => 'Prob', 'label' => 'Probation'],
    ];

    // Determine the default preview URL based on approved ETYPE_ID
    $etypeId = (string)($tenure_chg['ETYPE_ID'] ?? '');
    $defaultReportScript = 'reports/pdfReport/confirmation_letter_revised.php';

    switch ($etypeId) {
        case '6': // Trainee Extension
            $defaultReportScript = 'reports/pdfReport/extension_letter_trainee.php';
            break;
        case '2': // Contract Extension
            $defaultReportScript = 'reports/pdfReport/extension_letter_contract.php';
            break;
        case '5': // Probation Extension
            $defaultReportScript = 'reports/pdfReport/extension_letter_probation.php';
            break;
        case '1': // Permanent
            $defaultReportScript = 'reports/pdfReport/confirmation_letter_revised.php';
            break;
        default:
            $defaultReportScript = 'reports/pdfReport/probation_letter.php';
            break;
    }

    $defaultReportUrl = "{$defaultReportScript}?EMP_CODE=" . urlencode($empCode);

    /* ===========================================
       6. CONSTRUCT JSON RESPONSE PAYLOAD
    =========================================== */
    $response = [
        'employee' => [
            'emp_code'         => $empCode,
            'name'             => $empName,
            'doj'              => $res_emp['DOJ'] ?? '-',
            'designation'      => $res_emp['DESIG'] ?? '-',
            'department'       => $res_emp['DEPT'] ?? '-',
            'location'         => $locationName,
            'current_emp_type' => $tenure_chg['EMP_TYPE'] ?? '-',
        ],
        'tenure_details' => [
            'tenure_change_id' => (int)$tenure_chg['ID'],
            'task_id'          => $taskId,
            'effective_from'   => $tenure_chg['EFF_FROM'] ?? '-',
            'etype_id'         => (int)$tenure_chg['ETYPE_ID'],
            'employee_type'    => $tenure_chg['EMP_TYPE'],
            'period'           => ($tenure_chg['ETYPE_PERIOD'] !== null && $tenure_chg['ETYPE_PERIOD'] !== '') 
                                    ? (int)$tenure_chg['ETYPE_PERIOD'] 
                                    : 0,
            'current_ctc'      => (float)($tenure_chg['CURR_CTC'] ?? 0),
            'proposed_ctc'     => (float)($tenure_chg['PROP_CTC'] ?? 0),
            'ctc_eff_from'     => $tenure_chg['CTC_EFF_FROM'] ?? '-',
            'remarks'          => $tenure_chg['REMARKS'] ?? '',
        ],
        'compliance' => [
            'salary_approved'        => $salaryApproved,
            'pending_salary_holders' => implode(', ', $pendingApprovers),
            'salary_approval_msg'    => $salaryApproved 
                ? 'Salary authorized.' 
                : 'Salary Approval pending at EPP system. Please get it approved to print tenure change letter.',
            'has_doc_reference'      => $hasDocReference,
            'doc_ref'                => $docInfo['DOC_REF'] ?? null,
        ],
        'options' => [
            'staff_types' => $staffTypeModes,
        ],
        'report' => [
            'default_report_url' => $defaultReportUrl,
            'can_close_task'     => $hasDocReference && $salaryApproved,
        ]
    ];

    apiResponse(true, "Tenure letter details loaded successfully.", $response, 200);

} catch (Throwable $e) {
    if (function_exists('logOracleError')) {
        logOracleError([
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ], "get_tenure_letter_details.php");
    }

    apiResponse(false, "Server error while fetching tenure letter details: " . $e->getMessage(), null, 500);
}