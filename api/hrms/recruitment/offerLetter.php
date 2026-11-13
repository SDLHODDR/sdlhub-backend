<?php
/*
 * GET ?user_task_id= (an open Print Offer Letter task, 22) -> the offer letter PDF.
 * Legacy reports/pdfReport/offer_letter_revised.php. The first print takes an outward
 * reference number (epplive.BCS_OUTWARD_REF) and stores it on HR_OFFERS.OFFR_DOC_REF;
 * later prints reuse it. The PDF is generated on request and not saved to disk.
 */
ob_start();
require_once __DIR__ . '/recruitmentFlowCommon.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

const OFFER_LETTER_EMP_TYPE_TRAINEE = '6';
const OFFER_LETTER_EMP_TYPE_PROBATION = '5';
const OFFER_LETTER_EMP_TYPE_CONTRACT = '2';

function offerLetterFail($message, $status)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    apiResponse(false, $message, null, $status);
    exit;
}

function offerLetterTitle($value)
{
    return ucwords(strtolower(trim((string)$value)));
}

/** Outward reference of the letter: the stored one, or a new one taken now (legacy refNo). */
function offerLetterReference(array $offer, array $candidate, $empCode)
{
    if (trim((string)($offer['OFFR_DOC_REF'] ?? '')) !== '') {
        return [$offer['OFFR_DOC_REF'], $offer['CHG_ON_TEXT']];
    }
    $site = recruitmentRow("SELECT WORK_SITE FROM EPPLIVE.BCS_EMPLOYEE WHERE EMP_CODE = :code", [':code' => $empCode]);
    $office = recruitmentRow("SELECT D.SHORT_CODE, D.DEPT_CODE FROM HR_EMP_OFFICE_DET O
        INNER JOIN HR_DEPARTMENT D ON D.DEPT_ID = O.DEPT_ID
        WHERE O.EMP_CODE = :code AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
        ORDER BY O.ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => $empCode]);
    $period = recruitmentRow("SELECT DOC_PREFIX FROM EPPLIVE.BCS_ACCT_PERIOD
        WHERE CODE = (SELECT ACCT_PRD FROM EPPLIVE.BCS_PERIOD WHERE TRUNC(SYSDATE) BETWEEN FR_DATE AND TO_DATE)");
    $workSite = strtoupper(trim((string)($site['WORK_SITE'] ?? '')));
    $prefix = strtoupper(trim((string)($period['DOC_PREFIX'] ?? '')));
    if (!preg_match('/^[A-Z0-9_]+$/', $workSite) || !preg_match('/^[A-Z0-9_]+$/', $prefix)) {
        throw new RecruitmentRequestException('Your EPP work site or the current accounting period is not set up, so the letter reference cannot be generated.', 409);
    }
    $sequence = recruitmentRow("SELECT EPPLIVE.TRAN_OR_{$workSite}_{$prefix}_SEQ.NEXTVAL AS SEQNO FROM DUAL");
    if (empty($sequence['SEQNO'])) {
        throw new RuntimeException("Outward sequence TRAN_OR_{$workSite}_{$prefix}_SEQ is missing.");
    }
    $reference = date('my') . '/' . $workSite . '/' . ($office['SHORT_CODE'] ?? '') . '/LTTR/' . $sequence['SEQNO'];
    $name = offerLetterTitle($candidate['CND_NAME'] ?? '');

    recruitmentExecute("INSERT INTO EPPLIVE.BCS_OUTWARD_REF (OUTWARD_DATE, OUTWARD_TYPE, DOC_REF, DEPT_CODE, OUTWARD_FROM, OUTWARD_TO,
            PLACE, REMARKS, CHG_ON, CHG_BY, SITE_CODE)
        VALUES (TRUNC(SYSDATE), 'LTTR', :ref, :dept, :from_emp, :to_name, :place, :remarks, SYSDATE, :chg_by, :site)", [
        ':ref' => $reference, ':dept' => (string)($office['DEPT_CODE'] ?? ''), ':from_emp' => $empCode, ':to_name' => $name,
        ':place' => (string)($candidate['CND_CITY'] ?? ''), ':remarks' => 'OFFER LETTER - ' . $name,
        ':chg_by' => strtoupper($empCode), ':site' => $workSite,
    ], 'Could not record the letter reference.');
    recruitmentExecute("UPDATE HR_OFFERS SET OFFR_DOC_REF = :ref WHERE ID = :id",
        [':ref' => $reference, ':id' => (string)$offer['ID']], 'Could not save the letter reference.');
    global $sql___func___con;
    if (!oci_commit($sql___func___con)) {
        throw new RuntimeException('Could not commit the letter reference.');
    }
    return [$reference, date('d-M-Y')];
}

/** Field coverage allowances ('A') or reimbursements ('R') of the position (legacy sqlalloworg / sqlreiorg). */
function offerLetterAllowances(array $request, $expenseType)
{
    $rows = multiRec("SELECT DISTINCT HJD.ALLOW_ID, INITCAP(HA.ALLOW_DESC) AS ALLOW_DESC, HJD.ALLOW_AMOUNT, HJD.ADD_INFO
        FROM HR_ORG_LOC_ALLOWANCES HOL
        INNER JOIN HR_ALLOWANCES HA ON HOL.ALLOW_ID = HA.ALLOW_ID
        INNER JOIN HR_ORGANOGRAM ORG ON ORG.ID = HOL.ORG_ID
        INNER JOIN HR_JD_ALLOWANCES HJD ON ORG.JD_ID = HJD.JD_ID AND HJD.ALLOW_ID = HOL.ALLOW_ID
        INNER JOIN HR_ORGANOGRAM_LOC LOC ON LOC.ID = HOL.ORG_LOC_ID
        WHERE HOL.ORG_LOC_ID = :loc_id AND HOL.ORG_ID = :org_id AND HJD.EXP_TYPE = :exp_type AND HOL.EFFEC_TO IS NULL
          AND (SYSDATE BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
               OR LOC.EFFEC_FROM BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01'))
        ORDER BY HJD.ALLOW_ID", [
        ':loc_id' => (string)$request['ORG_LOC_ID'], ':org_id' => (string)$request['ORG_ID'], ':exp_type' => $expenseType,
    ]) ?: [];
    $seen = [];
    $list = [];
    foreach ($rows as $row) {
        $name = offerLetterTitle($row['ALLOW_DESC']);
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $list[] = ['name' => $name, 'amount' => trim('Rs.' . $row['ALLOW_AMOUNT'] . ' ' . offerLetterTitle($row['ADD_INFO']))];
    }
    return $list;
}

function offerLetterAllowanceTable(array $rows)
{
    $e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $html = '<table width="100%" border="0" cellpadding="2"><thead><tr><th width="5%"><b>No</b></th>'
        . '<th width="60%"><b>Allowance Type</b></th><th width="35%"><b>Allowance Amount</b></th></tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        $html .= '<tr><td width="5%">' . ($index + 1) . '</td><td width="60%">' . $e($row['name']) . '</td><td width="35%">'
            . $e($row['amount']) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

try {
    [, $context] = recruitmentFlowLoadTask(RECRUITMENT_TASK_PRINT_OFFER, $_GET['user_task_id'] ?? 0, $empCode);
    session_write_close();
    $request = $context['request'];
    $interview = $context['interview'];

    $offer = recruitmentRow("SELECT ID, OFFR_DOC_REF, TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS CHG_ON_TEXT FROM HR_OFFERS
        WHERE INTRV_DATE_ID = :id ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", [':id' => (string)$interview['ID']]);
    if (empty($offer['ID'])) {
        throw new RecruitmentRequestException('No offer has been generated for this candidate.', 409);
    }
    $candidate = recruitmentRow("SELECT C.*, GET_EMPTITLE(C.TITLE_ID) AS TITLE_TEXT, S.GSTAT_DESC
        FROM HR_CANDIDATE_INFO C
        LEFT JOIN EPPLIVE.BCS_GST_STATES S ON S.GSTAT_CODE = C.CND_STATE
        WHERE C.ID = :id", [':id' => (string)$context['application']['CAN_ID']]);
    $designation = recruitmentRow("SELECT GET_DESIGN_NAME(DESIG_ID) AS DESIG FROM HR_CAN_APPLICATION_DETAILS WHERE ID = :id",
        [':id' => (string)$context['application']['ID']]);
    $organogram = recruitmentRow("SELECT COMPANY, GET_COMP_NAME(COMPANY) AS CMPNM, DESI_ID FROM HR_ORGANOGRAM WHERE ID = :id",
        [':id' => (string)$request['ORG_ID']]);
    $location = recruitmentRow("SELECT GEO_DESC FROM HR_ORGANOGRAM_LOC WHERE ID = :id", [':id' => (string)$request['ORG_LOC_ID']]);

    $signatory = recruitmentRow("SELECT HJD.ORG_LOC_ID FROM HR_DIVSN_DOC HJD
        INNER JOIN HR_DOC_TYPES HDT ON HJD.DOC_ID = HDT.DOCTYP_ID AND HDT.DOC_TYPE = 'D'
        WHERE HJD.COMP_ID = :comp AND HJD.DIVSN_ID = :divsn AND HJD.DESI_ID = :desi AND HJD.DEPT_ID = :dept AND HDT.DOCTYP_ID = '1'
        FETCH FIRST 1 ROWS ONLY", [
        ':comp' => (string)($organogram['COMPANY'] ?? ''), ':divsn' => (string)$request['DIVSN_ID'],
        ':desi' => (string)($organogram['DESI_ID'] ?? ''), ':dept' => (string)$request['DEPT_ID'],
    ]);
    $signing = empty($signatory['ORG_LOC_ID']) ? [] : recruitmentRow("SELECT TRIM(B.FNAME || ' ' || B.LNAME) AS NM,
            GET_DESIGN_NAME(A.DESI_ID) AS DESIG, GET_DEPT_NAME(A.DEPT_ID) AS DEPT
        FROM HR_EMP_OFFICE_DET A INNER JOIN HR_EMPLOYEE_INFO B ON A.EMP_CODE = B.EMP_CODE
        WHERE A.ORG_LOC_ID = :loc AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
        FETCH FIRST 1 ROWS ONLY", [':loc' => (string)$signatory['ORG_LOC_ID']]);

    $reportsTo = recruitmentRow("SELECT P.REP, TRIM(E.FNAME || ' ' || E.LNAME) AS NM,
            (SELECT GET_DESIGN_NAME(O.DESI_ID) FROM HR_EMP_OFFICE_DET O WHERE O.EMP_CODE = P.REP
                AND SYSDATE BETWEEN O.EFFEC_FROM AND NVL(O.EFFEC_TO, DATE '3000-03-01')
                ORDER BY O.ID DESC FETCH FIRST 1 ROWS ONLY) AS DESIG
        FROM (SELECT GET_ORG_LOC_EMP_CODE(PL.PARENT_LOCID, SYSDATE) AS REP FROM HR_ORG_LOC_PARENT PL
              INNER JOIN HR_ORGANOGRAM_LOC LOC ON LOC.ID = PL.ORG_LOC_ID
              WHERE PL.ORG_LOC_ID = :loc
                AND (SYSDATE BETWEEN PL.EFFEC_FROM AND NVL(PL.EFFEC_TO, DATE '3000-03-01')
                     OR LOC.EFFEC_FROM BETWEEN PL.EFFEC_FROM AND NVL(PL.EFFEC_TO, DATE '3000-03-01'))
              ORDER BY PL.ID DESC FETCH FIRST 1 ROWS ONLY) P
        LEFT JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = P.REP", [':loc' => (string)$request['ORG_LOC_ID']]);

    [$reference, $letterDate] = offerLetterReference($offer, $candidate, $empCode);

    $e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $type = (string)($interview['ETYPE_ID'] ?? '');
    $period = $e($interview['ETYPE_PERIOD'] ?? '');
    $statement = [
        OFFER_LETTER_EMP_TYPE_TRAINEE => "You will be under training period of not less than {$period} months from the date of joining. ",
        OFFER_LETTER_EMP_TYPE_PROBATION => "You will be under probation period of not less than {$period} months from the date of joining. ",
        OFFER_LETTER_EMP_TYPE_CONTRACT => 'Your appointment shall be purely of contractual nature. You will be under probation period of not less than 6 months from the date of joining. ',
    ][$type] ?? '';
    $division = (string)($request['DIVSN'] ?? '');
    $post = ($type === OFFER_LETTER_EMP_TYPE_TRAINEE && $division !== 'SHL_India' ? 'Trainee ' : '') . ($designation['DESIG'] ?? '');
    $name = $e(trim(($candidate['TITLE_TEXT'] ?? '') . ' ' . offerLetterTitle($candidate['CND_NAME'] ?? '')));
    $joining = !empty($interview['TNT_JOIN_DAY']) ? date('F d Y', strtotime($interview['TNT_JOIN_DAY'])) : '';
    $allowances = offerLetterAllowances($request, 'A');
    $reimbursements = offerLetterAllowances($request, 'R');

    $html = '<style>p { text-align: justify; font-size: 10pt; } td, th { font-size: 10pt; }</style>'
        . '<table width="100%"><tr><td align="left">Ref : ' . $e($reference) . '</td><td align="right">Date : ' . $e($letterDate) . '</td></tr></table><br>'
        . 'To,<br>' . $name . '.<br>'
        . $e(offerLetterTitle($candidate['CND_ADDR_LINE1'] ?? '')) . ',<br>'
        . $e(offerLetterTitle($candidate['CND_ADDR_LINE2'] ?? '')) . ',<br>'
        . $e(offerLetterTitle($candidate['CND_LOCALITY'] ?? '')) . ',<br>'
        . $e(offerLetterTitle($candidate['CND_CITY'] ?? '')) . ', ' . $e(offerLetterTitle($candidate['GSTAT_DESC'] ?? '')) . ' - ' . $e($candidate['CND_PINCODE'] ?? '') . '<br>'
        . 'Email : ' . $e($candidate['CND_EMAIL'] ?? '') . '<br>'
        . 'Mobile no. : ' . $e($candidate['CND_MOBILE'] ?? '')
        . '<p>Dear ' . $name . ',</p>'
        . '<p align="center">Sub: <u>Letter of offer for the post of ' . $e($post) . '</u></p>'
        . '<p>With reference to your application and subsequent interviews you had with us, we are pleased to offer you the post of "'
        . $e($post) . '" - ' . $e($division) . ' based at ' . $e(strtoupper((string)($location['GEO_DESC'] ?? ''))) . ' w.e.f. ' . $e($joining)
        . '. We offer you the remuneration of Rs ' . $e($interview['CTC_OFFER'] ?? '') . ' LPA (C.T.C.)</p>'
        . $statement
        . 'You will be reporting to ' . $e(offerLetterTitle($reportsTo['NM'] ?? '')) . ' - ' . $e(offerLetterTitle($reportsTo['DESIG'] ?? '')) . '.<br><br>'
        . ($allowances ? 'In addition to the above, you will be eligible for the following field coverage allowances:<br><br>' . offerLetterAllowanceTable($allowances) . '<br>' : '')
        . ($reimbursements ? 'Besides above mentioned allowances you may also claim the following:<br><br>' . offerLetterAllowanceTable($reimbursements) . '<br><br>'
            . 'To get reimbursement of Mobile &amp; Internet expenses, you are requested to procure &amp; provide us relevant bills ( duly sealed &amp; signed by the vendor ) along with your expense statement:' : '')
        . '<p>If the above terms and conditions are acceptable to you, please sign and return immediately the duplicate copy of this Letter of Offer as a token of your acceptance. Please let us know the date of your joining. '
        . 'Your appointment letter with terms and conditions will be sent to you on your joining for work. Any dispute arising out of this arrangement will be subject to jurisdiction of courts in Mumbai to the exclusion '
        . 'of other courts. This offer is valid for 20 days from the date of this letter.</p>'
        . '<p>You are requested to submit a copy each of the following documents on or before joining duty.<br>'
        . '1. Acknowledged copy of the resignation letter from your earlier employer.<br>'
        . '2. Relieving letter from the previous employer.<br>'
        . '3. PF, FPF &amp; ESI numbers of the previous employer.<br>'
        . '4. Degree Certificates</p>'
        . 'Yours faithfully,<br>For <b>' . $e(strtoupper((string)($organogram['CMPNM'] ?? ''))) . '</b><br><br><br><br>'
        . '<table width="100%"><tr><td align="left"><b>' . $e(offerLetterTitle($signing['NM'] ?? '')) . '<br>'
        . $e(($signing['DESIG'] ?? '') . (!empty($signing['DEPT']) ? ' - ' . $signing['DEPT'] : '')) . '</b></td></tr>'
        . '<tr><td align="right">(Signature of ' . $e(offerLetterTitle(($candidate['CND_FNAME'] ?? '') . ' ' . ($candidate['CND_LNAME'] ?? ''))) . ')</td></tr></table>';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('HRMS');
    $pdf->SetAuthor('HR Department');
    $pdf->SetTitle('Offer Letter');
    $pdf->SetMargins(18, 40, 12);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');
    $output = $pdf->Output('', 'S');

    oci_close($sql___func___con);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Offer_Letter_' . (int)$candidate['ID'] . '.pdf"');
    header('Content-Length: ' . strlen($output));
    header('Cache-Control: private, no-store');
    echo $output;
    exit;
} catch (RecruitmentRequestException $e) {
    offerLetterFail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    global $sql___func___con;
    if (!empty($sql___func___con)) oci_rollback($sql___func___con);
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'offerLetter.php');
    offerLetterFail('Unable to generate the offer letter.', 500);
}
