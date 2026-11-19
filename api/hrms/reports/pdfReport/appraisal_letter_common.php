<?php
/*
 * Shared setup for the appraisal letters:
 *   promotion_letter.php  - Print Promotion Letter (task 51, legacy reports/pdfReport/promotion_letter.php)
 *   increment_letter.php  - Print Increment Letter (task 52; the legacy increment letters were a one-off
 *                           FY 22-23 bulk script on HR_FIELD_INCR, so this one follows the promotion letter)
 * GET ?EMP_CODE=&user_task_id= renders the letter for whoever can act on the open task. The reference number
 * comes from appraisal/generateAppraisalLetterReference.php; once it exists each render keeps a copy at the
 * HR_EMP_DOCS path. Salary and office are read as on the increment / promotion date (usually ahead).
 */

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
require_once __DIR__ . '/../../../config/path.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

if (empty($_SESSION['EmpCode']) && !empty($_SESSION['emp_code'])) {
    $_SESSION['EmpCode'] = $_SESSION['emp_code'];
}
$sessionEmp = trim((string)($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? ''));

function appraisalLetterFail($status, $message)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/** First row (not HTML-encoded) or []. */
function appraisalLetterRow($sql, $binds = [])
{
    $rows = multiRec($sql, $binds);
    return $rows[0] ?? [];
}

/**
 * Load everything a letter needs for the task ($taskGroupId 51 / 52), or stop with an error.
 * Returns the data array used by the templates.
 */
function appraisalLetterLoad($taskGroupId, $sessionEmp)
{
    if ($sessionEmp === '') {
        appraisalLetterFail(401, 'Session expired. Please login again.');
    }
    $empCode = trim((string)($_GET['EMP_CODE'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $empCode)) {
        appraisalLetterFail(422, 'A valid employee code is required.');
    }
    loadHrmsAccess($sessionEmp);
    $userTaskId = filter_var($_GET['user_task_id'] ?? '', FILTER_VALIDATE_INT);
    if (!hrmsFindActionableTask($taskGroupId, $empCode, $sessionEmp, $userTaskId ?: null)) {
        appraisalLetterFail(403, 'You are not authorized to open this letter, or the print task is no longer open.');
    }
    $bind = [':code' => $empCode];

    $manager = appraisalLetterRow("SELECT ID, APPR_ID, RECO_PROMO, TO_CHAR(NXT_APPR_DUE, 'DD-Mon-YYYY') AS NXT_DUE FROM HR_APPR_EMP_MGR
        WHERE EMP_CODE = :code AND STATUS = 'A' ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", $bind);
    $appr = appraisalLetterRow("SELECT ID, ORG_ID, ORG_LOC_ID, NEW_ORG_ID, NEW_ORG_LOC_ID, NEW_CTC,
            TO_CHAR(INCR_EFFEC_FROM, 'YYYY-MM-DD') AS INCR_FROM, TO_CHAR(PROMO_EFFEC_FROM, 'YYYY-MM-DD') AS PROMO_FROM
        FROM HR_APPR_ORG_EMP WHERE EMP_CODE = :code AND STATUS IN ('A', 'C') AND APPR_ID = :appr
        ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", $bind + [':appr' => (string)($manager['APPR_ID'] ?? '')]);
    if (empty($appr['ID'])) {
        appraisalLetterFail(404, 'The approved appraisal of this employee was not found.');
    }
    $incrFrom = $appr['INCR_FROM'] ?: date('Y-m-d');
    $promoFrom = $appr['PROMO_FROM'] ?: $incrFrom;

    $employee = appraisalLetterRow("SELECT A.*, T.TITLE_DESC, C.COMP_DESC FROM HR_EMPLOYEE_INFO A
        LEFT JOIN HR_TITLES T ON T.TITLE_ID = A.TITLE
        LEFT JOIN HR_COMPANY C ON C.COMP_ID = A.COMP_ID
        WHERE A.EMP_CODE = :code", $bind);
    // Office as on the promotion date (the new position once authorized).
    $office = appraisalLetterRow("SELECT * FROM (SELECT O.ORG_ID, O.ORG_LOC_ID, O.DIVSN_ID, O.DEPT_ID, O.DESI_ID,
            GET_DESIGN_NAME(O.DESI_ID) AS DESIG, GET_DIVISION_NAME(O.DIVSN_ID) AS DIVSN, GET_ORG_LOC_NAME(O.ORG_LOC_ID) AS LOC
        FROM HR_EMP_OFFICE_DET O WHERE O.EMP_CODE = :code
        ORDER BY CASE WHEN TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01') THEN 0 ELSE 1 END,
            O.EFFEC_FROM DESC) WHERE ROWNUM = 1", $bind + [':dt' => $promoFrom]);

    $designation = static fn($orgId) => appraisalLetterRow("SELECT J.ID AS JD_ID, GET_DESIGN_NAME(J.DESIG_ID) AS SH_DESC
        FROM HR_ORGANOGRAM G INNER JOIN HR_JD J ON J.ID = G.JD_ID WHERE G.ID = :org", [':org' => (string)$orgId]);
    $oldDesig = $designation($appr['ORG_ID']);
    $newDesig = $designation($appr['NEW_ORG_ID'] ?: $appr['ORG_ID']);

    // Reporting to: the person above the employee's (new) location.
    $reportsTo = appraisalLetterRow("SELECT GET_ORG_LOC_EMP_CODE(PARENT_LOCID, TO_DATE(:dt, 'YYYY-MM-DD')) AS REP FROM HR_ORG_LOC_PARENT
        WHERE ORG_LOC_ID = :loc AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, DATE '3000-03-01')
        ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':dt' => $promoFrom, ':loc' => (string)($appr['NEW_ORG_LOC_ID'] ?: $appr['ORG_LOC_ID'])]);
    $boss = [];
    if (!empty($reportsTo['REP']) && strcasecmp($reportsTo['REP'], 'vacant') !== 0) {
        $boss = appraisalLetterRow("SELECT INITCAP(TRIM(E.FNAME || ' ' || E.LNAME)) AS NAME, T.TITLE_DESC, GET_DESIGN_NAME(O.DESI_ID) AS DESIG
            FROM HR_EMPLOYEE_INFO E LEFT JOIN HR_TITLES T ON T.TITLE_ID = E.TITLE
            LEFT JOIN HR_EMP_OFFICE_DET O ON O.EMP_CODE = E.EMP_CODE AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
            WHERE E.EMP_CODE = :rep ORDER BY O.ID DESC FETCH FIRST 1 ROWS ONLY", [':rep' => $reportsTo['REP']]);
    }

    // Signatory: the division document authority for document type 3 (legacy).
    $signatory = appraisalLetterRow("SELECT HJD.ORG_LOC_ID FROM HR_DIVSN_DOC HJD
        WHERE HJD.DOC_ID = 3 AND HJD.COMP_ID = :comp AND HJD.DIVSN_ID = :divsn AND HJD.DEPT_ID = :dept AND HJD.DESI_ID = :desi
        FETCH FIRST 1 ROWS ONLY", [':comp' => (string)$employee['COMP_ID'], ':divsn' => (string)($office['DIVSN_ID'] ?? ''),
        ':dept' => (string)($office['DEPT_ID'] ?? ''), ':desi' => (string)($office['DESI_ID'] ?? '')]);
    $signer = [];
    if (!empty($signatory['ORG_LOC_ID'])) {
        $signer = appraisalLetterRow("SELECT INITCAP(TRIM(B.FNAME || ' ' || B.LNAME)) AS NAME, GET_DESIGN_NAME(A.DESI_ID) AS DESIG
            FROM HR_EMP_OFFICE_DET A INNER JOIN HR_EMPLOYEE_INFO B ON B.EMP_CODE = A.EMP_CODE
            WHERE A.ORG_LOC_ID = :loc AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
            FETCH FIRST 1 ROWS ONLY", [':loc' => $signatory['ORG_LOC_ID']]);
    }

    // Salary as on the increment date (cost-to-company heads), plus PF / bonus / gratuity / ESI.
    $salaryAt = [':code' => $empCode, ':dt' => $incrFrom];
    $heads = multiRec("SELECT A.AD_CODE, B.SH_DESCR, A.TYPE, A.AMOUNT FROM HR_BCS_EMPLOYEE_AD A
        INNER JOIN HR_BCS_ALLW_DEDN B ON B.AD_CODE = A.AD_CODE AND B.COST_COMPANY = 'Y'
        WHERE A.EMP_CODE = :code AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN A.EFF_DATE AND A.EXP_DATE
        ORDER BY B.SEQ_NO", $salaryAt) ?: [];
    $rate = static fn($ad) => appraisalLetterRow("SELECT TYPE, AMOUNT FROM HR_BCS_EMPLOYEE_AD WHERE EMP_CODE = :code AND AD_CODE = :ad
        AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN EFF_DATE AND EXP_DATE FETCH FIRST 1 ROWS ONLY", $salaryAt + [':ad' => $ad]);
    $gross = 0.0;
    $basic = 0.0;
    foreach ($heads as $head) {
        $gross += (float)$head['AMOUNT'];
        if ($head['AD_CODE'] === 'MBAS') {
            $basic = (float)$head['AMOUNT'];
        }
    }
    $add = [];
    foreach (['CPF' => ['PF (Employer\'s part)', 12], 'CBON' => ['Bonus', 8.33], 'CGRA' => ['Gratuity', 5], 'CESI' => ['ESI (Employer\'s part)', null]]
        as $ad => [$label, $defaultRate]) {
        $row = $rate($ad);
        if (empty($row)) {
            continue;
        }
        $monthly = $row['TYPE'] === 'P' ? round($basic * (float)$row['AMOUNT'] / 100) : round((float)$row['AMOUNT'] * 12 / 100);
        if ($ad !== 'CPF' && $row['TYPE'] !== 'P') {
            $monthly = round((float)$row['AMOUNT'] / 12);
        }
        $add[] = ['label' => $label, 'rate' => $row['TYPE'] === 'P' ? number_format((float)$row['AMOUNT'], 2) . '%' : '', 'monthly' => $monthly];
    }
    $monthlyCtc = $gross + array_sum(array_column($add, 'monthly'));

    $allowances = static fn($type) => multiRec("SELECT DISTINCT HJD.ALLOW_ID, INITCAP(HA.ALLOW_DESC) AS ALLOW_DESC, HJD.ALLOW_AMOUNT, HJD.ADD_INFO
        FROM HR_ORG_LOC_ALLOWANCES HOL
        INNER JOIN HR_ALLOWANCES HA ON HA.ALLOW_ID = HOL.ALLOW_ID
        INNER JOIN HR_ORGANOGRAM ORG ON ORG.ID = HOL.ORG_ID
        INNER JOIN HR_JD_ALLOWANCES HJD ON HJD.JD_ID = ORG.JD_ID AND HJD.ALLOW_ID = HOL.ALLOW_ID
            AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN HJD.FROMDT AND NVL(HJD.TODT, DATE '3000-03-01')
        WHERE HOL.ORG_LOC_ID = :loc AND HOL.ORG_ID = :org AND HJD.EXP_TYPE = :type
          AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
        ORDER BY HJD.ALLOW_ID", [':dt' => $promoFrom, ':loc' => (string)($appr['NEW_ORG_LOC_ID'] ?: $appr['ORG_LOC_ID']),
        ':org' => (string)($appr['NEW_ORG_ID'] ?: $appr['ORG_ID']), ':type' => $type]) ?: [];

    $appointment = appraisalLetterRow("SELECT TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS CHG_ON, DOC_REF FROM HR_EMP_DOCS
        WHERE EMP_CODE = :code AND DOC_ID = 2 ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", $bind);
    $docId = $taskGroupId === 51 ? 101 : 15;
    $docPath = EMP_DOCS_DIR . '/' . ($taskGroupId === 51 ? 'Promotion' : 'Increment') . '_Letter_' . $employee['ID'] . '_' . $appr['ID'] . '.pdf';
    $doc = appraisalLetterRow("SELECT DOC_REF, DOC_PATH, TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS DOC_DATE FROM HR_EMP_DOCS
        WHERE EMP_CODE = :code AND DOC_ID = :doc AND DOC_PATH = :path ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        $bind + [':doc' => (string)$docId, ':path' => $docPath]);

    $jdId = $newDesig['JD_ID'] ?? '';
    return [
        'empCode' => $empCode,
        'employee' => $employee,
        'office' => $office,
        'appr' => $appr,
        'nextDue' => (string)($manager['NXT_DUE'] ?? ''),
        'incrFrom' => $incrFrom,
        'promoFrom' => $promoFrom,
        'oldDesig' => (string)($oldDesig['SH_DESC'] ?? ''),
        'newDesig' => (string)($newDesig['SH_DESC'] ?? ''),
        'boss' => $boss,
        'signer' => $signer,
        'heads' => $heads,
        'gross' => $gross,
        'add' => $add,
        'monthlyCtc' => $monthlyCtc,
        'allow' => $allowances('A'),
        'reimburse' => $allowances('R'),
        'appointment' => $appointment,
        'doc' => $doc + ['DOC_PATH' => $docPath],
        'jdResponsibilities' => (string)(appraisalLetterRow("SELECT DESCR FROM HR_JD_DESCRIPTION WHERE JD_ID = :jd FETCH FIRST 1 ROWS ONLY",
            [':jd' => (string)$jdId])['DESCR'] ?? ''),
        'jdKra' => array_column(multiRec("SELECT HKM.KRA_DESC FROM HR_JD_KRA HJK INNER JOIN HR_KRA_MASTER HKM ON HKM.KRA_ID = HJK.KRA_ID
            WHERE HJK.JD_ID = :jd", [':jd' => (string)$jdId]) ?: [], 'KRA_DESC'),
    ];
}

class AppraisalLetterPdf extends TCPDF
{
    public function Header() {}
    public function Footer() {}
}

/** A new letter PDF with the legacy promotion letter settings. */
function appraisalLetterNewPdf()
{
    $pdf = new AppraisalLetterPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(20, 25, 18);
    $pdf->SetFooterMargin(5);
    $pdf->SetAutoPageBreak(true, 5);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->setCellHeightRatio(1.3);
    $pdf->AddPage();
    return $pdf;
}

function appraisalLetterEsc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function appraisalLetterDate($iso)
{
    return $iso ? date('d-M-Y', strtotime($iso)) : '';
}

/** "To" block, salutation name and signature block shared by both letters. */
function appraisalLetterAddress(array $data)
{
    $e = $data['employee'];
    $name = ucwords(strtolower(trim($e['FNAME'] . ' ' . $e['LNAME'])));
    $title = ucwords(strtolower((string)($e['TITLE_DESC'] ?? '')));
    $ref = $data['doc']['DOC_REF'] ?? '';
    return '<table><tr><td align="left">Ref : ' . appraisalLetterEsc($ref) . '</td>'
        . '<td align="right">Date : ' . appraisalLetterEsc($data['doc']['DOC_DATE'] ?? date('d-M-Y')) . '</td></tr></table><br><br>'
        . 'To,<br>' . appraisalLetterEsc($title . ' ' . $name) . '<br>'
        . nl2br(appraisalLetterEsc(ucwords(strtolower(wordwrap((string)$e['ADDRESS'], 40))))) . ',<br>'
        . appraisalLetterEsc(ucwords(strtolower((string)$e['CITY'])) . ' - ' . $e['PINCODE']) . '<br>'
        . 'Email : ' . appraisalLetterEsc($e['COM_EMAIL'] ?: $e['PER_EMAIL']) . '<br>'
        . 'Mobile : ' . appraisalLetterEsc($e['CELL']) . '<br><br>'
        . '<p>Dear ' . appraisalLetterEsc($title . ' ' . ucwords(strtolower((string)$e['FNAME']))) . ',</p>';
}

function appraisalLetterSignature(array $data, $enclosure = '')
{
    $e = $data['employee'];
    return '<br><p>Yours faithfully,<br>For <b>' . appraisalLetterEsc(strtoupper((string)$e['COMP_DESC'])) . '</b><br><br><br><br><br>'
        . '<table><tr><td align="left"><b>' . appraisalLetterEsc($data['signer']['NAME'] ?? '') . '<br>'
        . appraisalLetterEsc($data['signer']['DESIG'] ?? '') . '</b></td></tr>'
        . '<tr><td align="right">(Signature of ' . appraisalLetterEsc(ucwords(strtolower(trim(($e['TITLE_DESC'] ?? '') . ' ' . $e['FNAME'] . ' ' . $e['LNAME'])))) . ')</td></tr></table>'
        . ($enclosure !== '' ? appraisalLetterEsc($enclosure) : '') . '</p>';
}

/** Allowance table (legacy all_html / rei_html). */
function appraisalLetterAllowances(array $rows)
{
    $html = '<table width="100%" border="0"><tr><th width="5%"><b>No</b></th><th width="60%"><b>Allowance Type</b></th>'
        . '<th width="35%"><b>Allowance Amount</b></th></tr>';
    foreach ($rows as $index => $row) {
        $html .= '<tr><td width="5%">' . ($index + 1) . '</td><td width="60%">' . appraisalLetterEsc($row['ALLOW_DESC']) . '</td>'
            . '<td width="35%">Rs.' . appraisalLetterEsc($row['ALLOW_AMOUNT'] . ' ' . ucwords(strtolower((string)$row['ADD_INFO']))) . '</td></tr>';
    }
    return $html . '</table>';
}

/** Salary breakup annexure (legacy Annexure B). */
function appraisalLetterSalaryTable(array $data)
{
    $html = '<table border="1" cellpadding="6" width="100%"><tr><td rowspan="2" width="45%" align="center"><b>Particulars</b></td>'
        . '<td rowspan="2" width="15%" align="center"><b>Rate</b></td><td colspan="2" width="40%" align="center"><b>Amount (Rs.)</b></td></tr>'
        . '<tr><td width="20%" align="center"><b>Monthly</b></td><td width="20%" align="center"><b>Annual</b></td></tr>';
    foreach ($data['heads'] as $index => $head) {
        $html .= '<tr><td width="5%" align="center">' . ($index + 1) . '</td><td width="40%">' . appraisalLetterEsc($head['SH_DESCR']) . '</td>'
            . '<td width="15%"></td><td width="20%" align="right">' . number_format((float)$head['AMOUNT'], 0, '.', '') . '</td>'
            . '<td width="20%" align="right">' . number_format((float)$head['AMOUNT'] * 12, 0, '.', '') . '</td></tr>';
    }
    $html .= '<tr><td width="5%"></td><td width="40%"><b>Gross Salary</b></td><td width="15%"></td>'
        . '<td width="20%" align="right"><b>' . number_format($data['gross'], 0, '.', '') . '</b></td>'
        . '<td width="20%" align="right"><b>' . number_format($data['gross'] * 12, 0, '.', '') . '</b></td></tr>'
        . '<tr><td width="5%"></td><td width="40%"><b><u>Add :-</u></b></td><td width="15%"></td><td width="20%"></td><td width="20%"></td></tr>';
    foreach ($data['add'] as $row) {
        $html .= '<tr><td width="5%"></td><td width="40%">' . appraisalLetterEsc($row['label']) . '</td>'
            . '<td width="15%" align="center">' . appraisalLetterEsc($row['rate']) . '</td>'
            . '<td width="20%" align="right">' . number_format($row['monthly'], 0, '.', '') . '</td>'
            . '<td width="20%" align="right">' . number_format($row['monthly'] * 12, 0, '.', '') . '</td></tr>';
    }
    return $html . '<tr><td width="5%"></td><td width="40%" align="right"><b>Total CTC</b></td><td width="15%"></td>'
        . '<td width="20%" align="right"><b>' . number_format($data['monthlyCtc'], 0, '.', '') . '</b></td>'
        . '<td width="20%" align="right"><b>' . number_format($data['monthlyCtc'] * 12, 0, '.', '') . '</b></td></tr></table>';
}

/** Annual CTC in lakhs as the letters print it ("Rs. 4.85 Lac"). */
function appraisalLetterLakhs(array $data)
{
    return number_format(floor($data['monthlyCtc'] * 12 / 100000 * 100) / 100, 2);
}

/** Keep a copy at the HR_EMP_DOCS path once the reference exists, then send the PDF inline. */
function appraisalLetterFinish($pdf, array $data, $downloadName)
{
    if (!empty($data['doc']['DOC_REF'])) {
        $target = hrmsDocPath($data['doc']['DOC_PATH']);
        if (is_dir(dirname($target)) || @mkdir(dirname($target), 0775, true)) {
            @file_put_contents($target, $pdf->Output('', 'S'));
        }
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $pdf->Output($downloadName, 'I');
    exit;
}
