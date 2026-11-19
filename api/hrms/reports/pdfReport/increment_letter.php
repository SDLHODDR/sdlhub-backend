<?php
/*
 * Print Increment Letter (task 52): the revised CTC from the increment date with the salary breakup.
 * Same layout as the promotion letter (the legacy increment letters were a one-off FY 22-23 bulk script).
 * GET ?EMP_CODE=&user_task_id=
 */
require_once __DIR__ . '/appraisal_letter_common.php';

$data = appraisalLetterLoad(52, $sessionEmp);
$esc = 'appraisalLetterEsc';

$pdf = appraisalLetterNewPdf();
$letter = '<style>p { text-align: justify }</style><br><br><br><br><br>' . appraisalLetterAddress($data)
    . '<p>In recognition of your performance and contribution to the organization, we are pleased to inform you that your Annual CTC '
    . 'has been revised to <b>Rs. ' . appraisalLetterLakhs($data) . ' Lac</b> w.e.f. <b>' . appraisalLetterDate($data['incrFrom']) . '</b>, '
    . 'in your present designation of <b>' . $esc($data['office']['DESIG'] ?? $data['oldDesig']) . '</b>.<br><br>'
    . 'The detailed salary break-up is enclosed herewith as <b>Annexure \'A\'</b>.<br><br>'
    . 'All other terms and conditions of your Appointment Letter'
    . (!empty($data['appointment']['DOC_REF']) ? ' dated ' . $esc($data['appointment']['CHG_ON']) . ', Ref.No. ' . $esc($data['appointment']['DOC_REF']) : '')
    . ' and subsequent amendments shall remain unchanged.'
    . ($data['nextDue'] ? ' Your next appraisal shall be in ' . $esc($data['nextDue']) . ' and every April there after.' : '') . '<br><br>'
    . 'We look forward to your continued contribution to the growth of the organization.</p>';
$pdf->writeHTML($letter, true, false, true, true);
$pdf->writeHTML(appraisalLetterSignature($data), true, false, true, true);

$pdf->SetMargins(18, 32, 12);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();
$pdf->writeHTMLCell(0, 0, '', '', '<h3 align="center"><u>Annexure - "A"</u></h3><h3 align="center"><u>Salary Breakup</u></h3>'
    . appraisalLetterSalaryTable($data) . appraisalLetterSignature($data), '', 1, 0, true, 'L', true);

appraisalLetterFinish($pdf, $data, 'increment_letter.pdf');
