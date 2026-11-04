<?php
/*
 * Relieving Letter (doc 3). GET ?REF_ID=<separation id>&flag=H|F
 * H = HO wording, F = Field wording (last daily report + handover). Legacy relieving_letter.php.
 */
require_once __DIR__ . '/exit_letter_common.php';

$data = exitLetterData($_GET['REF_ID'] ?? 0, 3, $empCode);
$flag = strtoupper((string)($_GET['flag'] ?? 'H')) === 'F' ? 'F' : 'H';
$separation = $data['separation'];
$employee = $data['employee'];
$esc = 'exitLetterEscape';
$modes = ['E' => 'Email', 'T' => 'Telephonic', 'O' => 'Other'];

$addressLines = array_map($esc, explode("\n", wordwrap(ucwords(strtolower((string)($employee['ADDRESS'] ?? ''))), 35, "\n")));
$intro = 'We are in receipt of your Resignation letter dated ' . $esc($separation['RESIGN_DATE'])
    . ' through ' . $esc($modes[$separation['RESIGN_MODE']] ?? $separation['RESIGN_MODE'])
    . ', expressing your desire to resign from the services of the Company as ' . $esc($data['office']['DESIG'] ?? '') . '.';

if ($flag === 'H') {
    $body = $intro . ' Please note that the same is accepted and you will be relieved from the closing hours of '
        . $esc($separation['RELIEVING_DATE']) . '.';
} else {
    $lastDay = singRec("SELECT TO_CHAR(MAX(DAY_DATE), 'DD-Mon-YYYY') AS LAST_DAY FROM HR_SFM_DAY_PLANS
        WHERE STATUS = 'A' AND EMP_ID = (SELECT ID FROM HR_SFM_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code)",
        [':emp_code' => $separation['EMP_CODE']]);
    $handover = $separation['HANDOVER_TO'] ? getEmpInfoByCodeHR($separation['HANDOVER_TO']) : '';
    $body = $intro . ' Please note that your last daily report received by us is of ' . $esc($lastDay['LAST_DAY'] ?: '-')
        . '. Therefore, your relieving letter and dues if any, will be cleared by us only on receipt of Company properties'
        . ' alongwith Original Copy of Resignation letter duly signed by you with NOC from the Stockist and Superstockist'
        . ' within two weeks from date of resignation to ' . $esc($handover) . '.';
}

$html = '<style>p { text-align: justify; }</style>
<br><br><br><br><br>' . exitLetterRefHtml($data) . '
To,<br>
' . $esc($data['employeeName']) . '.<br>
' . implode('<br>', $addressLines) . '<br>
' . $esc(ucwords(strtolower((string)($employee['CITY'] ?? '')))) . ' - ' . $esc($employee['PINCODE'] ?? '') . '<br>
Email : ' . $esc($employee['COM_EMAIL'] ?? '') . '<br>
Mobile : ' . $esc($employee['CELL'] ?? '') . '
<p>Dear ' . $esc(ucwords(strtolower((string)($employee['FNAME'] ?? '')))) . ',<br><br>
<span style="text-align:justify; line-height: 20px;">' . $body . '</span></p>
<p>Thanking you,<br><br>Yours faithfully,<br>' . exitLetterSignatureHtml($data) . '</p>';

exitLetterOutput($data, $html, 'relieving_letter.pdf');
