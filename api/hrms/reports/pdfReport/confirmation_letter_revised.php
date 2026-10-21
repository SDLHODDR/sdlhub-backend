<?php
// Prevent PHP warnings/notices from corrupting the PDF binary stream
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

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../../../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Map session keys if different
if (empty($_SESSION['EmpCode']) && !empty($_SESSION['emp_code'])) {
    $_SESSION['EmpCode'] = $_SESSION['emp_code'];
}


$empCode =  $_REQUEST['EMP_CODE'] ?? $_GET['EMP_CODE'] ?? '';

if (empty($empCode)) {
    die("Error: EMP_CODE is required.");
}

class MYPDF extends TCPDF
{
    public function Header() {}
    public function Footer() {}
}

$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(20, 25, 18);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(5);
$pdf->SetAutoPageBreak(TRUE, 5);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->SetFont('Helvetica', '', 9);
$pdf->setCellHeightRatio(1.3);
$pdf->AddPage();

$currentDate = date('d-M-Y');

// 1. Employee Details
$OFFER_INFO = singRec("SELECT a.*, get_emp_nm(a.id) emp_nm FROM hr_employee_info a WHERE a.emp_code='" . $empCode . "'");
$dept = singRec("SELECT SHORT_CODE FROM HR_DEPARTMENT WHERE DEPT_CODE='" . $OFFER_INFO['DEPT_CODE'] . "' ");
$session_empCode = singRec("SELECT work_site, dept_code FROM epplive.bcs_employee WHERE emp_code='" . $_SESSION['EmpCode'] . "'");

// Fetch tenure record (fallback to 'A' if 'T' not found)
$tenure = singRec("SELECT * FROM hr_emp_tenure_change WHERE emp_code = '" . $empCode . "' AND status = 'T'");
if (empty($tenure['ID'])) {
    $tenure = singRec("SELECT * FROM hr_emp_tenure_change WHERE emp_code = '" . $empCode . "' AND status = 'A' ORDER BY id DESC");
}

$ctcEffFrom = !empty($tenure['CTC_EFF_FROM']) ? $tenure['CTC_EFF_FROM'] : date('d-M-Y');

$empres = singRec("SELECT desi_id, get_design_name(desi_id) ds, dept_id, divsn_id, GET_DIVISION_NAME(divsn_id) DVSN, org_id 
                   FROM hr_emp_office_det 
                   WHERE emp_code='" . $empCode . "' 
                     AND '" . $ctcEffFrom . "' BETWEEN effec_from AND NVL(effec_to , '03-Mar-3000')");

$empoff = getEmpOffice($empCode);
$DOC_INFO_CONF = singRec("SELECT * FROM hr_emp_docs WHERE emp_id=" . ($OFFER_INFO['ID'] ?: 0) . " AND doc_id = 14 ORDER BY id DESC");
$DOC_INFO_APPT = singRec("SELECT * FROM hr_emp_docs WHERE emp_id=" . ($OFFER_INFO['ID'] ?: 0) . " AND doc_id = 2 ORDER BY id DESC");

$signatories = singRec("SELECT DOCTYP_ID, DOCTYP_DESC, ORG_LOC_ID FROM HR_DIVSN_DOC hjd 
                       RIGHT JOIN hr_doc_types hdt ON hjd.doc_id = hdt.DOCTYP_ID AND DOC_TYPE ='D' 
                       AND COMP_ID='" . $OFFER_INFO['COMP_ID'] . "' 
                       AND DIVSN_ID='" . $empres['DIVSN_ID'] . "' 
                       AND DEPT_ID='" . $empres['DEPT_ID'] . "' 
                       AND DESI_ID='" . $empres['DESI_ID'] . "' 
                       WHERE DOC_TYPE ='D' AND DOCTYP_ID='14'");

$appoint_sign = singRec("SELECT b.TITLE, a.EMP_CODE, get_design_name(a.desi_id) desig, get_dept_name(a.dept_id) dept 
                        FROM hr_emp_office_det a 
                        INNER JOIN hr_employee_info b ON a.emp_code=b.emp_code 
                        WHERE org_loc_id='" . $signatories['ORG_LOC_ID'] . "' 
                          AND SYSDATE BETWEEN effec_from AND NVL(effec_to , '03-Mar-3000')");

$dept_desc = ' - ' . $appoint_sign['DEPT'];
if ($appoint_sign['DEPT'] == 'Management' || $appoint_sign['DESIG'] == 'Head - Marketing & Sales') {
    $dept_desc = '';
}

$finData = singRec("SELECT to_char(fr_date,'dd-Mon-rrrr') frdt, to_char(to_date,'dd-Mon-rrrr') trdt,
                           to_char(fr_date,'rrrr-mm-dd') calfrdt, to_char(to_date,'rrrr-mm-dd') caltodt, doc_prefix 
                    FROM epplive.bcs_acct_period 
                    WHERE code = (SELECT acct_prd FROM epplive.bcs_period WHERE '" . $ctcEffFrom . "' BETWEEN fr_date AND to_date)");

$COMPANY = singRec("SELECT * FROM hr_company WHERE comp_id ='" . $OFFER_INFO['COMP_ID'] . "'");

$refNo = $DOC_INFO_CONF['DOC_REF'] ?? '';

// 2. Reference Number Generation (if generateRef=true)
if (!empty($_REQUEST['generateRef']) && empty($DOC_INFO_CONF['DOC_REF'])) {
    if (!empty($session_empCode['WORK_SITE']) && !empty($finData['DOC_PREFIX'])) {
        $deptShort = singRec("SELECT SHORT_CODE FROM HR_DEPARTMENT WHERE DEPT_CODE='" . $session_empCode['DEPT_CODE'] . "' ");
        $tranSeq = singRec("SELECT epplive.TRAN_OR_" . $session_empCode['WORK_SITE'] . "_" . $finData['DOC_PREFIX'] . "_SEQ.nextval SEQNO FROM dual");
        $refNo = date('my', strtotime($currentDate)) . '/' . $session_empCode['WORK_SITE'] . '/' . $deptShort['SHORT_CODE'] . '/LTTR/' . $tranSeq['SEQNO'];

        startQry();
        executeQry("INSERT INTO epplive.BCS_OUTWARD_REF(ID,OUTWARD_DATE,OUTWARD_TYPE,DOC_REF,DEPT_CODE,OUTWARD_FROM,OUTWARD_TO,PLACE,REMARKS,CHG_ON,CHG_BY,SITE_CODE)
                    VALUES(NULL, trunc(sysdate), 'LTTR', '" . $refNo . "', '" . $session_empCode['DEPT_CODE'] . "', '" . $_SESSION['EmpCode'] . "',
                    '" . getEmpInfoByCode($empCode) . "', '" . $OFFER_INFO['CITY'] . "', 'CONFIRMATION LETTER - " . getEmpInfoByCode($empCode) . "',
                    SYSDATE, '" . trim(strtoupper($_SESSION['EmpCode'])) . "', '" . trim(strtoupper($session_empCode['WORK_SITE'])) . "') returning id into :newId", 'newId');

        executeQry("INSERT INTO hr_emp_docs (id, emp_id, emp_code, doc_id, doc_path, doc_ref, cc_to, chg_on, chg_by) 
                    VALUES (NULL, '" . $OFFER_INFO['ID'] . "', '" . $empCode . "', 14, 'input/emp_docs/Confirmation_Letter_" . $OFFER_INFO['ID'] . ".pdf',
                    '" . $refNo . "', '', SYSDATE, '" . $_SESSION['EmpCode'] . "') returning id into :newId", 'newId');
        endQry();
    }
}

$nxtprd = singRec("SELECT round(MONTHS_BETWEEN('" . ($finData['TRDT'] ?? date('d-M-Y')) . "','" . $ctcEffFrom . "'),0) MON_CNT, to_char(to_date('" . ($finData['TRDT'] ?? date('d-M-Y')) . "'),'yyyy') YR FROM DUAL");

// 3. Render HTML Letter
$result = '
<style>p { text-align: justify; }</style>
<br><br><br><br><br>
<table>
<tr>
    <td align="left">Ref  : ' . ($refNo ?: '________________') . '</td>
    <td align="right">Date  : ' . ($refNo == '' ? $currentDate : date("d-M-Y", strtotime($DOC_INFO_CONF['CHG_ON'] ?? $currentDate))) . '</td>
</tr>
</table><br><br>
To,<br>
' . getEmpInfoByCode($empCode) . '.<br>
' . ucwords(strtolower(wordwrap($OFFER_INFO['ADDRESS'] ?? '', 40, "<br>\n"))) . ', <br/>
' . ucwords(strtolower($OFFER_INFO['CITY'] ?? '')) . ' - ' . ($OFFER_INFO['PINCODE'] ?? '') . ' <br/>
Email : ' . ($OFFER_INFO['COM_EMAIL'] ?? '') . ' <br/>
Mobile : ' . ($OFFER_INFO['CELL'] ?? '') . ' 
<p>Dear ' . ucwords(strtolower($OFFER_INFO['TITE'] ?? '')) . ' ' . ucwords(strtolower($OFFER_INFO['FNAME'] ?? '')) . ',<br><br>
Pursuant to the Clause mentioned in your Appointment Letter having Ref. No. ' . ($DOC_INFO_APPT['DOC_REF'] ?? '') . ' dated ' . (!empty($DOC_INFO_APPT['CHG_ON']) ? date('F d Y', strtotime($DOC_INFO_APPT['CHG_ON'])) : '') . ' , the Management is pleased to confirm your services with effect from ' . date("F d Y", strtotime($ctcEffFrom)) . '.<br><br>
' . ($tenure['CURR_CTC'] == $tenure['PROP_CTC'] ? '' : 'Accordingly, your CTC has been reviewed and revised from Rs. ' . floor(($tenure['CURR_CTC'] ?: 0) / 100000 * 100) / 100 . ' (LPA) to Rs. ' . floor(($tenure['PROP_CTC'] ?: 0) / 100000 * 100) / 100 . ' (LPA).<br><br>') . '
You will be based at ' . ucwords(strtolower($empoff['LOC'] ?? '')) . ' for the time being. However, you will be liable to be transferred to any other department or establishment or branch of the Company as per Business exigencies requirement.<br><br>
The other terms and conditions mentioned in your Appointment Letter shall remain unchanged.<br><br>
We trust you will contribute to a still greater measure in achieving the objectives of the Company. Your next appraisal will be due on Apr-' . (($nxtprd['MON_CNT'] ?? 0) >= 6 ? ($nxtprd['YR'] ?? date('Y')) : (($nxtprd['YR'] ?? date('Y')) + 1)) . ' and in April every year thereafter.
</p>';

$pdf->writeHTML($result, true, 0, true, true, '');

$signHtml = '
<br><br>
<p>Thanking you,<br><br>Yours faithfully,<br>For <b>' . strtoupper($COMPANY['COMP_DESC'] ?? '') . '</b><br><br><br><br><br>
<table>
<tr>
    <td align="left"><p><b>' . ucwords(strtolower(getEmpInfoByCode($appoint_sign['EMP_CODE'] ?? ''))) . ' <br>' . ($appoint_sign['DESIG'] ?? '') . ' ' . $dept_desc . ' </b></p></td>    
    <td align="right"><p>(Signature of ' . ucwords(strtolower(($OFFER_INFO['FNAME'] ?? '') . ' ' . ($OFFER_INFO['LNAME'] ?? ''))) . ')</p></td>
</tr>
</table>';

$pdf->writeHTML($signHtml, true, 0, true, true);

// 4. Annexure-A Salary Breakup (if CTC changed)
if (!empty($tenure['PROP_CTC']) && $tenure['CURR_CTC'] != $tenure['PROP_CTC']) {
    $CTC_table = multiRec("SELECT a.emp_code, a.ad_code, b.seq_no, b.sh_descr, a.salary_type AS type,
                                  a.amount_mnth AS amount, b.cost_company
                           FROM HR_EMP_CTC_HEADS a
                           INNER JOIN HR_BCS_ALLW_DEDN b ON b.ad_code = a.ad_code
                           WHERE a.emp_code = '" . $empCode . "'
                             AND a.ref_id = '" . (int)($tenure['ID'] ?? 0) . "'
                             AND b.cost_company = 'Y'
                           ORDER BY b.seq_no");

    $pdf->AddPage();
    $annexure = '<h3 align="center"><b><u>Annexure - "A"</u></b></h3><h4 align="center"><b><u>Salary Breakup</u></b></h4><br>';
    $annexure .= '<table border="1" cellpadding="5px" width="100%">
    <thead>
        <tr align="center">
            <th width="10%"><b>Sr.</b></th>
            <th width="40%"><b>Particulars</b></th>
            <th width="25%"><b>Monthly (Rs.)</b></th>
            <th width="25%"><b>Annual (Rs.)</b></th>
        </tr>
    </thead>
    <tbody>';
    
    $cnt = 1;
    $totalAmt = 0;
    foreach ($CTC_table as $ds) {
        if ($ds['AMOUNT'] != 0) {
            $annexure .= '<tr>
                <td width="10%" align="center">' . $cnt++ . '</td>
                <td width="40%">' . $ds['SH_DESCR'] . '</td>
                <td width="25%" align="right">' . number_format($ds['AMOUNT']) . '</td>
                <td width="25%" align="right">' . number_format($ds['AMOUNT'] * 12) . '</td>
            </tr>';
            $totalAmt += $ds['AMOUNT'];
        }
    }
    
    $annexure .= '<tr>
        <td colspan="2" align="right"><b>Total Gross CTC:</b></td>
        <td align="right"><b>' . number_format($totalAmt) . '</b></td>
        <td align="right"><b>' . number_format($totalAmt * 12) . '</b></td>
    </tr>';
    $annexure .= '</tbody></table>';

    $pdf->writeHTML($annexure, true, 0, true, true);
}

// 5. Output PDF Binary Stream Cleanly
ob_end_clean();
$pdf->Output('confirmation_letter_revised.pdf', 'I');
exit;