<?php
/*
 * Print Promotion Letter (task 51). Legacy reports/pdfReport/promotion_letter.php: the letter (new designation,
 * HQ, reporting manager, revised CTC, allowances), Annexure A (job responsibilities and KRAs of the new JD) and
 * Annexure B (salary breakup). GET ?EMP_CODE=&user_task_id=
 */
require_once __DIR__ . '/appraisal_letter_common.php';

$data = appraisalLetterLoad(51, $sessionEmp);
$e = $data['employee'];
$esc = 'appraisalLetterEsc';
$promoDate = appraisalLetterDate($data['promoFrom']);
$boss = $data['boss'];

$pdf = appraisalLetterNewPdf();
$letter = '<style>p { text-align: justify }</style><br><br><br><br><br>' . appraisalLetterAddress($data)
    . '<p>In recognition of your consistent performance, dedication, and valuable contribution to the organization, '
    . 'we are pleased to inform you that your designation has been revised from <b>' . $esc($data['oldDesig']) . ' to '
    . $esc($data['newDesig']) . '</b> w.e.f. <b>' . $promoDate . '</b>.<br><br>'
    . 'Your HQ shall be <b>' . $esc(ucwords(strtolower((string)($data['office']['LOC'] ?? '')))) . '</b> for the time being, subject to further '
    . 'communication by Management, and you are required to report at your assigned HQ on or before <b>' . $promoDate . '</b>.'
    . ($boss ? ' In your new role, you will report to <b>' . $esc(trim(ucwords(strtolower((string)$boss['TITLE_DESC'])) . ' ' . $boss['NAME']))
        . ' - ' . $esc(ucwords(strtolower((string)$boss['DESIG']))) . '</b>.' : '')
    . ' Your detailed job responsibilities are enclosed herewith as <b>Annexure \'A\'</b> for your reference.<br><br>'
    . 'Consequent to this promotion, your Annual CTC has also been revised to <b>Rs. ' . appraisalLetterLakhs($data) . ' Lac</b> w.e.f. <b>'
    . appraisalLetterDate($data['incrFrom']) . '</b>. The detailed salary break-up applicable to the position of <b>' . $esc($data['newDesig'])
    . '</b> is enclosed herewith as <b>Annexure \'B\'</b>.<br><br>'
    . ($data['allow'] ? 'In addition to the above, you will be eligible for the following allowances:<br><br>' . appraisalLetterAllowances($data['allow']) . '<br>' : '')
    . ($data['reimburse'] ? 'Besides above mentioned allowances you may also claim the following:<br><br>' . appraisalLetterAllowances($data['reimburse']) . '<br>' : '')
    . '<br>All other terms and conditions of your Appointment Letter'
    . (!empty($data['appointment']['DOC_REF']) ? ' dated ' . $esc($data['appointment']['CHG_ON']) . ', Ref.No. ' . $esc($data['appointment']['DOC_REF']) : '')
    . ' and subsequent amendments shall remain unchanged.'
    . ($data['nextDue'] ? ' Your next appraisal shall be in ' . $esc($data['nextDue']) . ' and every April there after.' : '') . '<br><br>'
    . 'We are confident, this enhanced responsibility will provide you with greater exposure and an opportunity to further strengthen your '
    . 'leadership capabilities while contributing meaningfully to the growth of the <b>' . $esc($data['office']['DIVSN'] ?? '') . ' Division</b>.</p>';
$pdf->writeHTML($letter, true, false, true, true);
$pdf->writeHTML(appraisalLetterSignature($data), true, false, true, true);

// Annexure A: job responsibilities.
$pdf->SetMargins(18, 32, 12);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();
$kra = '';
if ($data['jdKra']) {
    $kra = '<br><b>KRA Result Areas:</b><br>';
    foreach ($data['jdKra'] as $index => $item) {
        $kra .= ($index + 1) . '. ' . $esc($item) . '<br>';
    }
}
$pdf->writeHTMLCell(0, 0, '', '', '<h3 align="center"><u>Annexure - "A"</u></h3><h3 align="center"><u>Job Responsibilities</u></h3>'
    . '<style>p { text-align: justify }</style>' . html_entity_decode($data['jdResponsibilities'], ENT_QUOTES) . $kra, '', 1, 0, true, 'L', true);
$pdf->writeHTMLCell(0, 0, '', '', appraisalLetterSignature($data, 'Encl.: Job Description & Responsibilities.'), '', 1, 0, true, 'L', true);

// Annexure B: salary breakup.
$pdf->AddPage();
$pdf->writeHTMLCell(0, 0, '', '', '<h3 align="center"><u>Annexure - "B"</u></h3><h3 align="center"><u>Salary Breakup</u></h3>'
    . appraisalLetterSalaryTable($data) . appraisalLetterSignature($data), '', 1, 0, true, 'L', true);

appraisalLetterFinish($pdf, $data, 'promotion_letter.pdf');
