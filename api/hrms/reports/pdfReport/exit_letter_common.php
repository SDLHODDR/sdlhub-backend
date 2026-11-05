<?php
/*
 * Shared data + rendering for the exit letters (relieving_letter.php,
 * experience_letter.php). GET ?REF_ID=<HR_EMP_SEPARATION.ID>.
 */
ob_start();
require_once __DIR__ . '/../../authorization/exit/exitCommon.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

class ExitLetterPdf extends TCPDF
{
    public function Header() {}
    public function Footer() {}
}

function exitLetterFail($message, $status = 400)
{
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/**
 * Load everything a letter needs; only for employees within the user's profile scope.
 */
function exitLetterData($separationId, $docId, $sessionEmp)
{
    $separation = exitRecord("SELECT ID, EMP_CODE, RESIGN_MODE, HANDOVER_TO,
            TO_CHAR(RESIGN_RCP_DATE, 'DD-Mon-YYYY') AS RESIGN_DATE,
            TO_CHAR(RELEIVING_DATE, 'DD-Mon-YYYY') AS RELIEVING_DATE,
            TO_CHAR(ENTER_ON_EMP, 'YYYY-MM-DD') AS ENTER_ON_ISO
        FROM HR_EMP_SEPARATION WHERE ID = :id", [':id' => (string)(int)$separationId]);
    if (empty($separation['ID'])) {
        exitLetterFail('Separation request not found.', 404);
    }
    $employeeCode = $separation['EMP_CODE'];

    loadHrmsAccess($sessionEmp);
    $inScope = singRec("SELECT COUNT(*) AS CNT FROM HR_EMP_OFFICE_DET C
        INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = C.EMP_CODE
        WHERE C.EMP_CODE = :emp_code AND " . exitScopeSql('C', 'E'), [':emp_code' => $employeeCode]);
    if (!hrmsHasScope() || (int)($inScope['CNT'] ?? 0) === 0) {
        exitLetterFail('You are not authorized to view this letter.', 403);
    }

    $employee = exitRecord("SELECT ID, FNAME, ADDRESS, CITY, PINCODE, COM_EMAIL, CELL, COMP_ID, GENDER,
            TO_CHAR(DOJ, 'DD-Mon-YYYY') AS DOJ, TO_CHAR(DOL, 'DD-Mon-YYYY') AS DOL,
            GET_COMP_NAME(COMP_ID) AS COMPANY
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employeeCode]);
    $office = exitRecord("SELECT * FROM (SELECT GET_DESIGN_NAME(DESI_ID) AS DESIG, DESI_ID, DEPT_ID, DIVSN_ID
        FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :emp_code ORDER BY ID DESC) WHERE ROWNUM = 1",
        [':emp_code' => $employeeCode]);

    // Signatory configured for this document, company, division, department and designation.
    $signatory = singRec("SELECT D.ORG_LOC_ID FROM HR_DIVSN_DOC D
        WHERE D.DOC_ID = :doc_id AND D.COMP_ID = :comp_id AND D.DIVSN_ID = :divsn_id
          AND D.DEPT_ID = :dept_id AND D.DESI_ID = :desi_id AND ROWNUM = 1", [
        ':doc_id' => (string)$docId,
        ':comp_id' => (string)($employee['COMP_ID'] ?? ''),
        ':divsn_id' => (string)($office['DIVSN_ID'] ?? ''),
        ':dept_id' => (string)($office['DEPT_ID'] ?? ''),
        ':desi_id' => (string)($office['DESI_ID'] ?? ''),
    ]);
    $signing = exitRecord("SELECT * FROM (SELECT A.EMP_CODE, GET_DESIGN_NAME(A.DESI_ID) AS DESIG, GET_DEPT_NAME(A.DEPT_ID) AS DEPT
        FROM HR_EMP_OFFICE_DET A
        WHERE A.ORG_LOC_ID = :org_loc_id AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
        ORDER BY A.EFFEC_FROM DESC) WHERE ROWNUM = 1", [':org_loc_id' => (string)($signatory['ORG_LOC_ID'] ?? '')]);

    $doc = exitLetterDocs($employeeCode, $separation['ENTER_ON_ISO'])[$docId] ?? null;

    return [
        'separation' => $separation,
        'employee' => $employee,
        'employeeName' => ucwords(strtolower(getEmpInfoByCodeHR($employeeCode))),
        'office' => $office,
        'signing' => $signing,
        'signingName' => !empty($signing['EMP_CODE']) ? ucwords(strtolower(getEmpInfoByCodeHR($signing['EMP_CODE']))) : '',
        'doc' => $doc,
        'docId' => $docId,
    ];
}

function exitLetterEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Ref / Date line: the issued reference and its date, or blanks until generated. */
function exitLetterRefHtml(array $data)
{
    $ref = $data['doc']['DOC_REF'] ?? '';
    $date = $data['doc']['DOC_DATE'] ?? date('d-M-Y');
    return '<table><tr>
        <td align="left">Ref  : ' . ($ref !== '' ? exitLetterEscape($ref) : '________________') . '</td>
        <td align="right">Date  : ' . exitLetterEscape($date) . '</td>
    </tr></table><br><br>';
}

function exitLetterSignatureHtml(array $data)
{
    return 'For <b>' . exitLetterEscape(strtoupper($data['employee']['COMPANY'] ?? '')) . '</b><br><br><br><br><br><br>
        <table><tr><td align="left"><p><b>' . exitLetterEscape($data['signingName']) . ' <br>'
        . exitLetterEscape(trim(($data['signing']['DESIG'] ?? '') . ' - ' . ($data['signing']['DEPT'] ?? ''), ' -'))
        . '</b></p></td></tr></table><br><br>';
}

/**
 * Render the letter. Once a reference exists, also keep a copy at the
 * document's HR_EMP_DOCS path (as the legacy letters did).
 */
function exitLetterOutput(array $data, $html, $downloadName, $topMargin = 25)
{
    $pdf = new ExitLetterPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(20, $topMargin, 18);
    $pdf->SetFooterMargin(5);
    $pdf->SetAutoPageBreak(true, 5);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->setCellHeightRatio(1.3);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, true);
    $content = $pdf->Output($downloadName, 'S');

    $docPath = (string)($data['doc']['DOC_PATH'] ?? '');
    if ($docPath !== '' && preg_match('#^input/emp_docs/[A-Za-z0-9_]+\.pdf$#', $docPath)) {
        @file_put_contents(__DIR__ . '/../../../../' . $docPath, $content);
    }

    ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $content;
    exit;
}
