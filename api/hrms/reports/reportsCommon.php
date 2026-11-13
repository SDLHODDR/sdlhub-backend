<?php
/*
|--------------------------------------------------------------------------
| HRMS REPORTS - COMMON
|--------------------------------------------------------------------------
| Reports menu (legacy rept_admin.php + reports/report.php). The filter page
| loads its options as JSON; "Get Data" opens the report as a PDF in a new tab.
| Every endpoint calls reportsRequireMenu(): a report is open only to profiles
| holding its menu entry.
*/

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../config/db.php';

$sql___func___con = db_hrms();
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// HR_MENU_SUB.PROG_URL of each report: the old HRMS page and the SDL Hub path.
const REPORTS_MENU = [
    'followup' => ['rept_admin.php?reptnm=followup', 'reports/follow-up'],
    'jdmaster' => ['rept_admin.php?reptnm=jdmaster', 'reports/jd-master'],
    'orgmaster' => ['rept_admin.php?reptnm=orgmaster', 'reports/organogram-master'],
    'tenure' => ['rept_admin.php?reptnm=tenure', 'reports/tenure-change'],
    'orglochierarchy' => ['rept_admin.php?reptnm=orglochierarchy', 'reports/organogram-hierarchy'],
    'orglochierarchyvisual' => ['rept_admin.php?reptnm=orglochierarchyvisual', 'reports/organogram-hierarchy-visual'],
    'can_track' => ['rept_admin.php?reptnm=can_track', 'reports/candidate-tracker'],
    'openpositions' => ['rept_admin.php?reptnm=openpositions', 'reports/open-positions'],
    'req_track' => ['rept_admin.php?reptnm=req_track', 'reports/requisition-tracker'],
];

// HR_EMP_TENURE_CHANGE.STATUS (legacy tenure report $statusarr).
const REPORTS_TENURE_STATUSES = ['N' => 'New', 'T' => 'In Process', 'A' => 'Approved'];
// HR_RECRUITMENT.STATUS (legacy recruitment_req.php decode).
const REPORTS_REQUEST_STATUSES = ['N' => 'Not Sent for Auth', 'T' => 'HOD Approval Pending', 'A' => 'Approved By HOD',
    'I' => 'In Process', 'C' => 'Closed', 'R' => 'Rejected', 'X' => 'Deleted'];
// HR_CAN_APPLICATION_DETAILS.STATUS.
const REPORTS_APPLICATION_STATUSES = ['N' => 'New', 'A' => 'Shortlisted', 'R' => 'Rejected', 'I' => 'Interview Scheduled',
    'D' => 'Interview Confirmed', 'U' => 'Interview Rescheduled', 'S' => 'Interviewer Assigned', 'P' => 'Pass', 'F' => 'Fail',
    'H' => 'On Hold', 'O' => 'Offer Sent', 'J' => 'Offer Accepted', 'E' => 'Employed', 'C' => 'Offer Denied', 'G' => 'Offer Denied',
    'B' => 'Blocked', 'T' => 'In Transit'];

/** Full label of a recruitment request (legacy get_req_info): company - division - department - designation - location. */
const REPORTS_REQUEST_DESC_SQL = "(SELECT C.SH_DESC || ' - ' || DV.DIVSN_DESC || ' - ' || DP.DEPT_DESC || ' - ' || DS.DESI_DESC || ' - ' || L.GEO_DESC
       FROM HR_RECRUITMENT RQ
       INNER JOIN HR_ORGANOGRAM O ON O.ID = RQ.ORG_ID
       LEFT JOIN HR_COMPANY C ON C.COMP_ID = O.COMPANY
       LEFT JOIN HR_DIVISIONS DV ON DV.DIVSN_ID = RQ.DIVSN_ID
       LEFT JOIN HR_DEPARTMENT DP ON DP.DEPT_ID = RQ.DEPT_ID
       LEFT JOIN HR_DESIGNATION DS ON DS.DESI_ID = RQ.DESIG_ID
       LEFT JOIN HR_ORGANOGRAM_LOC L ON L.ID = RQ.ORG_LOC_ID
      WHERE RQ.ID = %s)";

/** Employee name (first and last) for an employee code column, in title case. */
function reportsEmpNameSql($column)
{
    return "(SELECT INITCAP(TRIM(EN.FNAME || ' ' || EN.LNAME)) FROM HR_EMPLOYEE_INFO EN WHERE EN.EMP_CODE = {$column})";
}

/** Optional YYYY-MM-DD from the request, or null when empty. */
function reportsOptionalDate($name, $label)
{
    return trim((string)($_GET[$name] ?? '')) === '' ? null : reportsDate($name, $label);
}

/** Optional positive id from the request, or null when empty. */
function reportsOptionalId($name, $label)
{
    return trim((string)($_GET[$name] ?? '')) === '' ? null : reportsId($name, $label);
}

/** From / To dates of a period report: both required, To not before From. */
function reportsPeriod()
{
    $from = reportsDate('from_date', 'from date');
    $to = reportsDate('to_date', 'to date');
    if ($to < $from) {
        throw new ReportsException('To date cannot be before the from date.', 422);
    }
    return [$from, $to];
}

/** Organogram locations for the hierarchy reports (legacy rept_admin.php location list). */
function reportsHierarchyLocations()
{
    return reportsOptions("SELECT HOL.ID AS V,
            GET_EMP_NAME(GET_ORG_LOC_EMP_CODE(HOL.ID, SYSDATE)) || ' - ' || GET_SHCOMP_NAME(HO.COMPANY) || ' - '
            || GET_DIVISION_NAME(HO.DIVSN_ID) || ' - ' || GET_DEPT_NAME(HO.DEPT_ID) || ' - ' || GET_DESIGN_NAME(HO.DESI_ID)
            || '(' || HOL.GEO_DESC || ')' AS L
        FROM HR_ORGANOGRAM_LOC HOL
        INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HOL.ORG_ID
        WHERE HOL.GEO_DESC IS NOT NULL
        ORDER BY HOL.ID");
}

/** The parent location of a hierarchy report: [id, "organogram - geo"] or a 404. */
function reportsHierarchyRoot()
{
    $locId = reportsId('org_loc_id', 'location');
    $root = reportsRow("SELECT ID, GET_ORG_NAME(ORG_ID) || ' - ' || GEO_DESC AS NM FROM HR_ORGANOGRAM_LOC WHERE ID = :id", [':id' => $locId]);
    if (empty($root['ID'])) {
        throw new ReportsException('Location not found.', 404);
    }
    return [$locId, (string)$root['NM']];
}

class ReportsException extends RuntimeException
{
}

function reportsEmpCode()
{
    $empCode = trim((string)($_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? ''));
    if ($empCode === '') {
        throw new ReportsException('Session expired. Please login again.', 401);
    }
    return $empCode;
}

/** Stop unless the user's active profile has the report's menu entry. */
function reportsRequireMenu($report)
{
    global $sql___func___con;
    if (!$sql___func___con) {
        throw new RuntimeException('Database connection failed.');
    }
    $empCode = reportsEmpCode();
    $pages = REPORTS_MENU[$report] ?? null;
    if ($pages === null) {
        throw new ReportsException('Unknown report.', 404);
    }
    $row = reportsRow("SELECT COUNT(*) AS CNT
        FROM HR_EMP_PROFILE EP
        INNER JOIN HR_PROFILE_MENU PM ON PM.PROFILE_ID = EP.PROFILE_ID
        INNER JOIN HR_MENU_SUB MS ON MS.ID = PM.SUB_MENU_ID AND MS.STATUS = 'A'
        WHERE EP.EMP_CODE = :emp_code
          AND SYSDATE BETWEEN EP.EFFEC_FROM AND NVL(EP.EFFEC_TO, DATE '3000-03-01')
          AND LOWER(MS.PROG_URL) IN (:page0, :page1)", [':emp_code' => $empCode, ':page0' => $pages[0], ':page1' => $pages[1]]);
    if ((int)($row['CNT'] ?? 0) === 0) {
        throw new ReportsException('You do not have access to this report.', 403);
    }
    return $empCode;
}

/** First row or []. multiRec does not HTML-encode (singRec does). */
function reportsRow($sql, $binds = [])
{
    $rows = multiRec($sql, $binds);
    return $rows[0] ?? [];
}

function reportsOptions($sql, $binds = [])
{
    return array_map(static fn($row) => ['value' => (string)$row['V'], 'label' => (string)$row['L']], multiRec($sql, $binds) ?: []);
}

/** Positive integer id from the request, or an error naming the field. */
function reportsId($name, $label)
{
    $value = trim((string)($_GET[$name] ?? ''));
    if (!ctype_digit($value) || (int)$value <= 0) {
        throw new ReportsException("Please select the {$label}.", 422);
    }
    return $value;
}

/** YYYY-MM-DD from the request. */
function reportsDate($name, $label)
{
    $value = trim((string)($_GET[$name] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new ReportsException("Please select the {$label}.", 422);
    }
    return $date;
}

/** JSON endpoint for the filter pages. */
function reportsRunOptions($report, callable $work)
{
    global $sql___func___con;
    header('Content-Type: application/json; charset=UTF-8');
    try {
        reportsRequireMenu($report);
        session_write_close();
        apiResponse(true, 'Options fetched successfully.', $work(), 200);
    } catch (ReportsException $e) {
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], "reports/{$report}");
        apiResponse(false, 'Unable to load the report options.', null, 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

/**
 * PDF endpoint. $work returns [title, [label => value meta lines], columns, rows, file name]
 * where columns are [header, width %, align] and rows are lists of cell text.
 * Errors are shown as a small page, since the report opens in its own tab.
 */
function reportsRunPdf($report, callable $work)
{
    global $sql___func___con;
    ob_start();
    try {
        reportsRequireMenu($report);
        session_write_close();
        [$title, $meta, $columns, $rows, $fileName] = $work();
        $pdf = reportsBuildPdf($title, $meta, $columns, $rows);
        oci_close($sql___func___con);
        $sql___func___con = null;
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $fileName) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        echo $pdf;
    } catch (ReportsException $e) {
        reportsErrorPage($e->getMessage(), $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], "reports/{$report}");
        reportsErrorPage('Unable to generate the report. Please try again.', 500);
    } finally {
        if (!empty($sql___func___con)) oci_close($sql___func___con);
    }
}

function reportsErrorPage($message, $status)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Report</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;color:#333"><h3>Report</h3><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}

class ReportsPdf extends TCPDF
{
    public $reportTitle = '';

    public function Header()
    {
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(110, 110, 110);
        // The server runs in UTC; the reports are read in India.
        $printed = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('d-M-Y H:i');
        $this->Cell(0, 5, $this->reportTitle . '   |   Printed on ' . $printed, 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

/** A4 landscape table report: title, meta lines, then the table (header repeats on each page). */
function reportsBuildPdf($title, array $meta, array $columns, array $rows)
{
    $e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $pdf = new ReportsPdf('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->reportTitle = $title;
    $pdf->SetCreator('SDL Hub');
    $pdf->SetAuthor('HRMS');
    $pdf->SetTitle($title);
    $pdf->setPrintHeader(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->AddPage();

    $html = '<h3 style="color:#092C4C">Title : ' . $e($title) . '</h3>';
    foreach ($meta as $label => $value) {
        $html .= '<div style="font-size:9pt"><b>' . $e($label) . ':</b> ' . $e($value) . '</div>';
    }
    $html .= '<br>';

    $head = '';
    foreach ($columns as [$header, $width, $align]) {
        $head .= '<th width="' . $width . '%" align="' . $align . '" style="background-color:#092C4C;color:#ffffff;font-weight:bold;font-size:7pt">'
            . $e($header) . '</th>';
    }
    $body = '';
    foreach ($rows as $index => $row) {
        $background = $index % 2 ? ' bgcolor="#F4F6F9"' : '';
        $body .= '<tr nobr="true"' . $background . '>';
        foreach (array_values($row) as $col => $cell) {
            $body .= '<td width="' . $columns[$col][1] . '%" align="' . $columns[$col][2] . '">' . nl2br($e($cell)) . '</td>';
        }
        $body .= '</tr>';
    }
    if (!$rows) {
        $body = '<tr><td width="100%" align="center">No data available for the selected filters.</td></tr>';
    }
    $html .= '<table border="0.3" cellpadding="3" style="border-color:#c8ccd2"><thead><tr>' . $head . '</tr></thead><tbody>'
        . $body . '</tbody></table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    return $pdf->Output('', 'S');
}

/** Display date for the meta lines. */
function reportsShowDate(DateTimeImmutable $date)
{
    return $date->format('d-M-Y');
}
