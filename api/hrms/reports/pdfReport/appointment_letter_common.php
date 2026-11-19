<?php
require_once __DIR__ . '/../../../config/path.php';
/*
 * Shared setup for the appointment letter templates (joining task 44
 * PRT_APPT_LTR, legacy print_appointment_letter.php):
 *   appointment_letter_ho.php / _field.php / _contract.php / _trainee.php
 *
 * GET ?EMP_CODE=&user_task_id= renders the letter. The reference number is
 * created by joining/generateAppointmentLetterReference.php; once it exists,
 * each render also keeps a copy at the HR_EMP_DOCS path
 * (EMP_DOCS_DIR/Appointment_Letter_<employee id>.pdf), as legacy did.
 */

// Keep warnings and whitespace out of the PDF stream.
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../cors.php';
require_once __DIR__ . '/../../../config/db.php';

$sql___func___con = db_hrms();

require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/utils.php';
require_once __DIR__ . '/../../../config/hr_func.php';
require_once __DIR__ . '/../../../config/hrmsAccess.php';
require_once __DIR__ . '/../../../eportal/leaverequest/numbertoword.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

if (empty($_SESSION['EmpCode']) && !empty($_SESSION['emp_code'])) {
    $_SESSION['EmpCode'] = $_SESSION['emp_code'];
}
$sessionEmp = trim((string)($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? ''));

function appointmentLetterFail($status, $message)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

if ($sessionEmp === '') {
    appointmentLetterFail(401, 'Session expired. Please login again.');
}

// The legacy queries below concatenate the code, so allow only a plain code.
$empCode = trim((string)($_GET['EMP_CODE'] ?? ''));
if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $empCode)) {
    appointmentLetterFail(422, 'A valid employee code is required.');
}

// Only whoever can act on the open Print Appointment Letter task (44) may render it.
loadHrmsAccess($sessionEmp);
$userTaskId = filter_var($_GET['user_task_id'] ?? '', FILTER_VALIDATE_INT);
if (!hrmsFindActionableTask(44, $empCode, $sessionEmp, $userTaskId ?: null)) {
    appointmentLetterFail(403, 'You are not authorized to open this letter, or the print task is no longer open.');
}

// Salary, office and tenure of a new joiner start on the joining date, which is
// usually after the letter is printed: read them as on the later of today and the DOJ.
$asOnRow = singRec("SELECT TO_CHAR(GREATEST(TRUNC(SYSDATE), NVL(TRUNC(DOJ), TRUNC(SYSDATE))), 'YYYY-MM-DD') AS AS_ON
    FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $empCode]);
$asOnDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($asOnRow['AS_ON'] ?? '')) ? $asOnRow['AS_ON'] : date('Y-m-d');
$asOnSql = "TO_DATE('{$asOnDate}', 'YYYY-MM-DD')";

if (!function_exists('arr')) {
    // Legacy debug helper.
    function arr($value = null) {}
}

/**
 * The employee's current office (legacy getEmpOffice): org, location, names.
 * Falls back to the latest office row.
 */
function appointmentEmpOffice($employeeCode)
{
    $employeeCode = trim((string)$employeeCode);
    if ($employeeCode === '') {
        return [];
    }
    $select = "SELECT A.ORG_ID, A.ORG_LOC_ID, A.DEPT_ID, A.DESI_ID, A.DIVSN_ID,
            GET_DEPT_NAME(A.DEPT_ID) AS DEPT, GET_DESIGN_NAME(A.DESI_ID) AS DESIG,
            GET_DIVISION_NAME(A.DIVSN_ID) AS DIV, GET_ORG_LOC_NAME(A.ORG_LOC_ID) AS LOC,
            TO_CHAR(E.DOJ, 'DD-Mon-YYYY') AS DOJ
        FROM HR_EMP_OFFICE_DET A
        INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = A.EMP_CODE
        WHERE A.EMP_CODE = :emp_code";
    $office = singRec("SELECT * FROM ({$select}
            AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
            ORDER BY A.EFFEC_FROM DESC) WHERE ROWNUM = 1", [':emp_code' => $employeeCode]);
    if (empty($office['ORG_ID'])) {
        $office = singRec("SELECT * FROM ({$select} ORDER BY A.EFFEC_FROM DESC) WHERE ROWNUM = 1",
            [':emp_code' => $employeeCode]);
    }
    if (is_array($office)) {
        foreach ($office as $key => $value) {
            $office[$key] = is_string($value) ? html_entity_decode($value, ENT_QUOTES) : $value;
        }
    }
    return $office ?: [];
}

class MYPDF extends TCPDF
{
    public function Header() {}

    public function Footer()
    {
        $this->Cell(110, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
}

/** A new letter PDF with the legacy settings. */
function appointmentNewPdf($cellHeightRatio = 1.1)
{
    $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->setCellHeightRatio($cellHeightRatio);
    return $pdf;
}

/**
 * Keep a copy of the issued letter at its HR_EMP_DOCS path (only once a
 * reference exists), then send the PDF inline.
 */
function appointmentFinishPdf($pdf, $docInfo, $downloadName)
{
    $docPath = (string)($docInfo['DOC_PATH'] ?? '');
    if (!empty($docInfo['DOC_REF']) && preg_match('#^' . preg_quote(EMP_DOCS_DIR, '#') . '/Appointment_Letter_[0-9]+\.pdf$#', $docPath)) {
        @file_put_contents(hrmsDocPath($docPath), $pdf->Output('', 'S'));
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdf->Output($downloadName, 'I');
    exit;
}
