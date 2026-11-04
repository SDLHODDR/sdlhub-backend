<?php
/*
 * Experience Letter (doc 4). GET ?REF_ID=<separation id>. Legacy experience_letter.php.
 */
require_once __DIR__ . '/exit_letter_common.php';

$data = exitLetterData($_GET['REF_ID'] ?? 0, 4, $empCode);
$employee = $data['employee'];
$esc = 'exitLetterEscape';
$pronoun = (string)($employee['GENDER'] ?? '') === '2' ? 'her' : 'him';
$dateOfLeaving = $employee['DOL'] ?: $data['separation']['RELIEVING_DATE'];

$html = '<style>p { text-align: justify; } h3 { text-align: center; }</style>
<br><br><br><br><br>' . exitLetterRefHtml($data) . '
<h3><b>TO WHOMSOEVER IT MAY CONCERN</b></h3><br><br>
<p><span style="text-align:justify; line-height: 20px;">This is to certify that ' . $esc($data['employeeName'])
    . ' was working with us as "' . $esc(ucwords(strtolower((string)($data['office']['DESIG'] ?? '')))) . '" from '
    . $esc($employee['DOJ'] ?? '') . ' to ' . $esc($dateOfLeaving) . '.<br><br>
During this tenure with us, we found ' . $pronoun . ' to have a good moral character.<br><br></span></p>
<br>' . exitLetterSignatureHtml($data);

exitLetterOutput($data, $html, 'experience_letter.pdf', 35);
