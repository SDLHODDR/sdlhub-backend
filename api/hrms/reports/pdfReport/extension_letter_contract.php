<?php

// Prevent any PHP warnings or whitespace from corrupting the PDF binary stream
ob_start();
error_reporting(1);
ini_set('display_errors', 1);

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";
require_once __DIR__ . "/../../../config/env.php";
require_once __DIR__ . "/../../../config/hr_func.php";
require_once __DIR__ . '/../../../../vendor/autoload.php';

// Session normalization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['EmpCode']) && !empty($_SESSION['emp_code'])) {
    $_SESSION['EmpCode'] = $_SESSION['emp_code'];
}

$empCode = $empCode = trim((string)($_REQUEST['EMP_CODE'] ?? $_GET['EMP_CODE'] ?? '')); //'05199';

if (empty($empCode)) {
    die("Error: EMP_CODE is required.");
}

class MYPDF extends \TCPDF
{
    public function Header() {}
    public function Footer() {}
} 

$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->SetFont('Helvetica', '', 9);
$pdf->setCellHeightRatio(1.2);

$currentDate = date('d-M-Y');

// 1. Fetch Employee Core Details
$OFFER_INFO = singRec("SELECT a.*, get_comp_name(a.COMP_ID) CMPNM, b.retire_age 
                       FROM hr_employee_info a
                       INNER JOIN hr_emp_basic_info b ON a.emp_code = b.emp_code 
                       WHERE a.emp_code = '" . $empCode . "'");

$EXTN_PRD = singRec("SELECT * FROM (
                        SELECT * FROM HR_EMP_TENURE_CHANGE 
                        WHERE emp_code = '" . $empCode . "' 
                        ORDER BY id DESC
                     ) WHERE ROWNUM = 1");

$DOC_INFO_APPT = singRec("SELECT * FROM hr_emp_docs WHERE emp_id = " . ($OFFER_INFO['ID'] ?: 0) . " AND doc_id = 2");
$DOC_INFO_EXTN = singRec("SELECT * FROM hr_emp_docs WHERE emp_id = " . ($OFFER_INFO['ID'] ?: 0) . " AND doc_id = 16 ORDER BY id DESC");

$empres = singRec("SELECT get_design_name(desi_id) ds, dept_id, divsn_id, GET_DIVISION_NAME(divsn_id) DVSN, org_id 
                   FROM hr_emp_office_det 
                   WHERE emp_code = '" . $empCode . "' 
                     AND SYSDATE BETWEEN effec_from AND NVL(effec_to, '03-Mar-3000')");

$empoff = getEmpOffice($empCode);

$signatories = singRec("SELECT DOCTYP_ID, DOCTYP_DESC, ORG_LOC_ID 
                        FROM HR_DIVSN_DOC hjd 
                        RIGHT JOIN hr_doc_types hdt ON hjd.doc_id = hdt.DOCTYP_ID AND DOC_TYPE = 'D' 
                          AND COMP_ID = '" . $OFFER_INFO['COMP_ID'] . "'
                          AND DIVSN_ID = '" . $empres['DIVSN_ID'] . "' 
                          AND DEPT_ID = '" . $empres['DEPT_ID'] . "' 
                        WHERE DOC_TYPE = 'D' AND DOCTYP_ID = '16'");


$appoint_sign = singRec("SELECT b.TITLE, a.EMP_CODE, get_design_name(a.desi_id) desig 
                         FROM hr_emp_office_det a 
                         INNER JOIN hr_employee_info b ON a.emp_code = b.emp_code
                         WHERE org_loc_id = '" . ($signatories['ORG_LOC_ID'] ?? 0) . "'
                           AND SYSDATE BETWEEN effec_from AND NVL(effec_to, '03-Mar-3000')");

$COMPANY = singRec("SELECT * FROM hr_company WHERE comp_id = '" . $OFFER_INFO['COMP_ID'] . "'");

$session_empCode = singRec("SELECT work_site, dept_code FROM epplive.bcs_employee WHERE emp_code = '" . $_SESSION['EmpCode'] . "'");

$finData = singRec("SELECT to_char(fr_date,'dd-Mon-rrrr') frdt,
                           to_char(to_date,'dd-Mon-rrrr') trdt,
                           to_char(fr_date,'rrrr-mm-dd') calfrdt,
                           to_char(to_date,'rrrr-mm-dd') caltodt, doc_prefix
                    FROM epplive.bcs_acct_period
                    WHERE code = (SELECT acct_prd FROM epplive.bcs_period WHERE SYSDATE BETWEEN fr_date AND to_date)");

$refNo = $DOC_INFO_EXTN['DOC_REF'] ?? '';



// 2. Reference Code Generation
if (!empty($_REQUEST['generateRef']) && empty($DOC_INFO_EXTN['DOC_REF'])) {
    if (!empty($session_empCode['WORK_SITE']) && !empty($finData['DOC_PREFIX'])) {
        $dept = singRec("SELECT SHORT_CODE FROM HR_DEPARTMENT WHERE DEPT_CODE = '" . $session_empCode['DEPT_CODE'] . "' ");
        $tranSeq = singRec("SELECT epplive.TRAN_OR_" . $session_empCode['WORK_SITE'] . "_" . $finData['DOC_PREFIX'] . "_SEQ.nextval SEQNO FROM dual");
        $refNo = date('my', strtotime($currentDate)) . '/' . $session_empCode['WORK_SITE'] . '/' . $dept['SHORT_CODE'] . '/LTTR/' . $tranSeq['SEQNO'];

        startQry();
        executeQry("INSERT INTO epplive.BCS_OUTWARD_REF(ID, OUTWARD_DATE, OUTWARD_TYPE, DOC_REF, DEPT_CODE, OUTWARD_FROM, OUTWARD_TO, PLACE, REMARKS, CHG_ON, CHG_BY, SITE_CODE)
                    VALUES(NULL, trunc(sysdate), 'LTTR', '" . $refNo . "', '" . $session_empCode['DEPT_CODE'] . "', '" . $_SESSION['EmpCode'] . "',
                    '" . getEmpInfoByCode($empCode) . "', '" . $OFFER_INFO['CITY'] . "',
                    'EXTENSION LETTER - " . strtoupper(getEmpInfoByCode($empCode)) . "',
                    SYSDATE, '" . trim(strtoupper($_SESSION['EmpCode'])) . "', '" . trim(strtoupper($session_empCode['WORK_SITE'])) . "') returning id into :newId ", 'newId');

        executeQry("INSERT INTO hr_emp_docs (id, emp_id, emp_code, doc_id, doc_path, doc_ref, cc_to, chg_on, chg_by) 
                    VALUES (NULL, '" . $OFFER_INFO['ID'] . "', '" . $empCode . "', 16,
                    'input/emp_docs/Extension_Letter_" . $OFFER_INFO['ID'] . ".pdf',
                    '" . $refNo . "', '', SYSDATE, '" . $_SESSION['EmpCode'] . "') returning id into :newId ", 'newId');
        endQry();
    }
}

// 3. Set Margins
$top_margin = 32;
$bot_margin = 25;
if (($COMPANY['COMP_ID'] ?? 0) == 2) {
    $top_margin = 32;
    $bot_margin = 40;
} elseif (($COMPANY['COMP_ID'] ?? 0) == 6) {
    $top_margin = 60;
    $bot_margin = 30;
}

$pdf->SetMargins(18, $top_margin, 12);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin($bot_margin);
$pdf->SetAutoPageBreak(TRUE, $bot_margin);
$pdf->AddPage();
$pdf->setListIndentWidth(4);

// 4. Calculations
$r = !empty($EXTN_PRD['ETYPE_PERIOD']) ? (int)$EXTN_PRD['ETYPE_PERIOD'] : 0;
$effFrom = !empty($EXTN_PRD['EFF_FROM']) ? $EXTN_PRD['EFF_FROM'] : date('d-M-Y');
$dt = strtotime($effFrom);
$extDate = date("d-M-Y", strtotime("+" . $r . " months", $dt));

$CUR_SAL_TOTAL = singRec("SELECT sum(a.amount) amt FROM epplive.bcs_employee_ad a, epplive.bcs_allw_dedn b 
                          WHERE a.emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' AND b.cost_company = 'Y'
                            AND a.ad_code = b.ad_code AND SYSDATE BETWEEN a.eff_date AND a.exp_date");

$CUR_MBAS = singRec("SELECT ID, AMOUNT, TYPE FROM epplive.BCS_EMPLOYEE_AD WHERE SYSDATE BETWEEN EFF_DATE AND EXP_DATE AND emp_code='" . $OFFER_INFO['EMP_CODE'] . "' AND CONFIRMED='Y' AND AD_CODE='MBAS'");
$CUR_MCTC = singRec("SELECT ID, AMOUNT, TYPE FROM epplive.BCS_EMPLOYEE_AD WHERE SYSDATE BETWEEN EFF_DATE AND EXP_DATE AND emp_code='" . $OFFER_INFO['EMP_CODE'] . "' AND CONFIRMED='Y' AND AD_CODE='MCTC'");

$CUR_CTCVAL = 0.0;
if (!empty($CUR_MCTC['ID'])) {
    $mbasAmt = (float)($CUR_MBAS['AMOUNT'] ?? 0);
    $mctcAmt = (float)($CUR_MCTC['AMOUNT'] ?? 0);

    if (($CUR_MCTC['TYPE'] ?? '') === 'P') {
        $CUR_CTCVAL = (float)round(($mbasAmt * $mctcAmt) / 100, 0);
    } else {
        $CUR_CTCVAL = $mctcAmt;
    }
}

$salAmt = (float)($CUR_SAL_TOTAL['AMT'] ?? 0);
$cur_ctc_total_m = $salAmt + $CUR_CTCVAL;

// 5. Letter Content
$result = '
<br><br><br><br><br>
<table width="100%">
<tr>
    <td align="left">Ref  : ' . ($refNo ? $refNo : '________________') . '</td>
    <td align="right">Date : ' . ($refNo == '' ? $currentDate : date("d-M-Y", strtotime($DOC_INFO_EXTN['CHG_ON'] ?? $currentDate))) . '</td>
</tr>
</table><br><br>
To,<br>
' . getEmpInfoByCode($empCode) . '.<br>
' . ucwords(strtolower(wordwrap($OFFER_INFO['ADDRESS'] ?? '', 40, "<br>\n"))) . ', <br/>
' . ucwords(strtolower($OFFER_INFO['CITY'] ?? '')) . ' - ' . ($OFFER_INFO['PINCODE'] ?? '') . ' <br/>
Email : ' . ($OFFER_INFO['COM_EMAIL'] ?? '') . ' <br/>
Mobile : ' . ($OFFER_INFO['CELL'] ?? '') . ' 
<p style="text-align: justify; line-height: 16px;">Dear ' . ucwords(strtolower($OFFER_INFO['TITE'] ?? '')) . ' ' . ucwords(strtolower($OFFER_INFO['FNAME'] ?? '')) . ',</p>
<p style="text-align: justify; line-height: 16px;">
Further to our letter No. ' . ($DOC_INFO_APPT['DOC_REF'] ?? '') . ' dated ' . (!empty($DOC_INFO_APPT['CHG_ON']) ? date("d-M-Y", strtotime($DOC_INFO_APPT['CHG_ON'])) : '') . ', the Management is pleased to extend your Contractual period from ' . date("d-M-Y", strtotime($effFrom)) . ' to ' . date("d-M-Y", strtotime($extDate)) . ' (i.e. ' . $r . ' months).<br><br>
Your above contractual period will automatically come to an end on the closing hours of ' . date("d-M-Y", strtotime($extDate)) . ', for which no separate letter will be issued to you.<br><br>
You will be paid Consolidated Salary of Rs. ' . number_format($cur_ctc_total_m) . '/- per month.<br><br>
All the remaining terms &amp; conditions in your original Appointment letter mentioned above shall remain unchanged.<br><br>
We trust that this will motivate you to higher and better results in the years to come.
</p>';

$pdf->writeHTML($result, true, 0, true, true);

$signHtml = '
<br><br><br>
<p>Thanking you,<br><br>
Yours faithfully,<br>
For <b>' . strtoupper($COMPANY['COMP_DESC'] ?? '') . '</b><br><br><br><br><br>
<table width="100%">
<tr>
    <td align="left">
        <b>' . ucwords(strtolower(getEmpInfoByCode($appoint_sign['EMP_CODE'] ?? ''))) . '</b><br>
        ' . ($appoint_sign['DESIG'] ?? '') . '
    </td>    
    <td align="right">
        (Signature of ' . ucwords(strtolower(($OFFER_INFO['FNAME'] ?? '') . ' ' . ($OFFER_INFO['LNAME'] ?? ''))) . ')
    </td>
</tr>
</table>';

$pdf->writeHTML($signHtml, true, 0, true, true);

// 6. Clean and Output
if (ob_get_length()) {
    ob_end_clean();
}
$pdf->Output('extension_letter_contract.pdf', 'I');
exit;