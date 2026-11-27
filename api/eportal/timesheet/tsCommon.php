<?php
/*
 * Shared start-up and helpers for the Daily Timesheet APIs.
 * Tables: EPT_TIMESHEET (one row per employee per day), EPT_TIMESHEET_TASK
 * (tasks of that day), EPT_TS_CATEGORY (task categories).
 * See database/eportal/timesheet.sql.
 *
 * Every write uses bind variables, so text such as "Client's review" is
 * stored as typed and can't change the SQL.
 */

require_once __DIR__ . "/../common/moduleHead.php";   // session, CSRF, $data, $empCode
require_once __DIR__ . "/../../config/path.php";       // DOCUMENT_ROOT, documentRootPath()

/* Limits: the column sizes in timesheet.sql (characters). */
const TS_TITLE_MAX = 200;
const TS_DESC_MAX = 1000;
const TS_REMARKS_MAX = 500;

/*
 * A day's timesheet can be added to, changed and submitted only on that day,
 * until TS_CUTOFF (India time). Changing a submitted day puts it back to Draft,
 * so the employee submits it again; past days are read-only.
 */
const TS_TIMEZONE = 'Asia/Kolkata';   // PHP on this server runs in UTC
const TS_CUTOFF_HOUR = 18;
const TS_CUTOFF_LABEL = '6:00 PM';

const TS_DAY_STATUS = [
    'D' => 'Draft',
    'S' => 'Submitted',
    'A' => 'Approved',
    'R' => 'Rejected',
];

const TS_TASK_STATUS = [
    'C' => 'Completed',
    'P' => 'In Progress',
    'H' => 'On Hold',
];

/* Formatted text (description, remarks) is stored as cleaned HTML, at most this many bytes (column size). */
const TS_HTML_MAX_BYTES = 4000;

/* Fonts the editor's Font dropdown offers; <font face> is kept only for these. Sizes 1-7 (<font size>). */
const TS_FONT_FACES = ['Nunito', 'Arial', 'Georgia', 'Times New Roman', 'Courier New', 'Verdana', 'Tahoma'];

/* Tags the editor may produce and that are kept; everything else is removed (its text kept). */
const TS_HTML_TAGS = ['p', 'br', 'div', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u'];

/*
 * Cleans formatted text from the editor: keeps only TS_HTML_TAGS, removes
 * every attribute (no style, links or event handlers), drops script/style
 * content. Plain text (older tasks, or typed without formatting) is escaped
 * and its line breaks kept. Returns "" for empty content.
 */
function tsCleanHtml($html)
{
    $html = trim((string)$html);
    if ($html === '') {
        return '';
    }
    if (strpos($html, '<') === false) {
        return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'), false);
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="ts-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('ts-root');
    if (!$root) {
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'), false);
    }

    // Pasted web content: headings / blocks become paragraphs, styled spans become b / i / u,
    // so the formatting survives; anything else keeps only its text.
    $headings = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    $blocks = ['blockquote', 'pre', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure',
               'figcaption', 'address', 'dl', 'dt', 'dd', 'form', 'fieldset', 'legend', 'table', 'thead', 'tbody',
               'tfoot', 'tr', 'center', 'details', 'summary', 'caption'];
    $spaced = ['td', 'th', 'label', 'button', 'option'];          // keep a space after their text
    $dropped = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'canvas',
                'input', 'select', 'textarea', 'img', 'video', 'audio', 'picture', 'source', 'link', 'meta', 'head', 'title'];

    $rename = function (DOMElement $el, string $tag) use ($doc) {
        $new = $doc->createElement($tag);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
        return $new;
    };

    $clean = function (DOMNode $node) use (&$clean, $doc, $rename, $headings, $blocks, $spaced, $dropped) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!($child instanceof DOMElement)) {
                if (!($child instanceof DOMText)) {
                    $node->removeChild($child);   // comments, processing instructions
                }
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, $dropped, true)) {
                if (in_array($tag, ['input', 'select', 'textarea', 'img'], true)) {
                    $node->insertBefore($doc->createTextNode(' '), $child);
                }
                $node->removeChild($child);
                continue;
            }

            // Formatting given by inline style (common in pasted content).
            $style = strtolower((string)$child->getAttribute('style'));
            $wrapTags = [];
            if ($style !== '') {
                if (preg_match('/font-weight\s*:\s*(bold|bolder|[6-9]00)/', $style)) $wrapTags[] = 'b';
                if (preg_match('/font-style\s*:\s*italic/', $style)) $wrapTags[] = 'i';
                if (preg_match('/text-decoration[^;]*underline/', $style)) $wrapTags[] = 'u';
            }

            $clean($child);

            // Font size / font from the editor: keep only a valid size (1-7) and a listed face.
            if ($tag === 'font') {
                $size = trim((string)$child->getAttribute('size'));
                $face = trim((string)$child->getAttribute('face'), " '\"");
                while ($child->attributes->length) {
                    $child->removeAttribute($child->attributes->item(0)->nodeName);
                }
                if (preg_match('/^[1-7]$/', $size)) {
                    $child->setAttribute('size', $size);
                }
                $knownFace = array_values(array_filter(TS_FONT_FACES, fn($f) => strcasecmp($f, $face) === 0));
                if ($knownFace) {
                    $child->setAttribute('face', $knownFace[0]);
                }
                if ($child->attributes->length) {
                    continue;   // kept as <font size / face>
                }
                // nothing valid left: falls through and is unwrapped below
            }

            if (in_array($tag, $headings, true)) {
                $p = $doc->createElement('p');
                $b = $doc->createElement('b');
                while ($child->firstChild) {
                    $b->appendChild($child->firstChild);
                }
                $p->appendChild($b);
                $node->replaceChild($p, $child);
                continue;
            }
            if (in_array($tag, $blocks, true)) {
                $child = $rename($child, 'div');
                $tag = 'div';
            }

            if (in_array($tag, TS_HTML_TAGS, true)) {
                while ($child->attributes->length) {
                    $child->removeAttribute($child->attributes->item(0)->nodeName);
                }
                // Styled block (e.g. <div style="font-weight:bold">): wrap its content.
                foreach ($wrapTags as $w) {
                    $wrap = $doc->createElement($w);
                    while ($child->firstChild) {
                        $wrap->appendChild($child->firstChild);
                    }
                    $child->appendChild($wrap);
                }
                continue;
            }

            // Not allowed (span, a, font, ...): keep its content, in b / i / u when it was styled.
            $target = $node;
            $before = $child;
            if ($wrapTags) {
                $outer = $doc->createElement($wrapTags[0]);
                $inner = $outer;
                foreach (array_slice($wrapTags, 1) as $w) {
                    $inner = $inner->appendChild($doc->createElement($w));
                }
                $node->insertBefore($outer, $child);
                $target = $inner;
                $before = null;
            }
            while ($child->firstChild) {
                $target->insertBefore($child->firstChild, $before);
            }
            if (in_array($tag, $spaced, true)) {
                $node->insertBefore($doc->createTextNode(' '), $child);
            }
            $node->removeChild($child);
        }
    };
    $clean($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    // Nothing visible left (e.g. "<p><br></p>") counts as empty.
    return tsHtmlText($out) === '' ? '' : trim($out);
}

/* Plain text of formatted text: one line per paragraph, "• " / "1. " for list items. */
function tsHtmlText($html)
{
    $html = (string)$html;
    if ($html === '') {
        return '';
    }
    if (strpos($html, '<') === false) {
        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="ts-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();

    $walk = function (DOMNode $node, $prefix = '') use (&$walk) {
        $text = '';
        $number = 0;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->nodeValue;
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $text .= "\n";
            } elseif ($tag === 'li') {
                $number++;
                $marker = $node->nodeName === 'ol' ? "{$number}. " : '• ';
                $text .= "\n" . $prefix . $marker . trim($walk($child, $prefix . '   ')) . "\n";
            } elseif (in_array($tag, ['p', 'div', 'ul', 'ol'], true)) {
                $text .= "\n" . $walk($child, $tag === 'ul' || $tag === 'ol' ? $prefix : $prefix) . "\n";
            } else {
                $text .= $walk($child, $prefix);
            }
        }
        return $text;
    };

    $root = $doc->getElementById('ts-root');
    $text = $root ? $walk($root) : strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/(?<=\S)[ \t]{2,}/u", " ", $text);   // runs of spaces inside a line
    $text = preg_replace("/[ \t]+\n/", "\n", $text);          // trailing spaces
    $text = preg_replace("/\n[ \t]+(?!•|\d+\. )/u", "\n", $text);   // leading spaces, except nested list indent
    $text = preg_replace("/\n+/", "\n", $text);   // one line per paragraph / list item
    return trim($text);
}

/* Active projects for the task form: [{ value, label }]. */
function tsProjectOptions()
{
    return array_map(fn($p) => ['value' => (int)$p['ID'], 'label' => $p['PROJ_DESC']], multiRec(
        "SELECT ID, PROJ_DESC FROM EPT_TS_PROJECT WHERE STATUS = 'A' ORDER BY SEQ, PROJ_DESC"
    ));
}

/* Task documents: stored under DOCUMENT_ROOT/uploads/timesheet, served by viewTimesheetDocument.php. */
const TS_DOC_DIR = "uploads/timesheet";
const TS_DOC_MAX_BYTES = 2 * 1024 * 1024;   // PHP upload_max_filesize on this server is 2M
const TS_DOC_TYPES = [                      // extension => accepted content types
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
];

/*
 * Validates and stores an uploaded task document ($_FILES entry).
 * Returns ['name' => original name, 'path' => path relative to DOCUMENT_ROOT].
 * Ends the request with a message when the file is not acceptable.
 */
function tsStoreDocument($file)
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        apiResponse(false, "The document must not be larger than 2 MB.", null, 400, ['document' => "The document must not be larger than 2 MB."]);
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        apiResponse(false, "The document could not be uploaded. Please try again.", null, 400, ['document' => "The document could not be uploaded."]);
    }

    $originalName = basename((string)($file['name'] ?? 'document'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedMessage = "Only PDF, Word, Excel, JPG and PNG files are allowed.";

    if (!isset(TS_DOC_TYPES[$ext])) {
        apiResponse(false, $allowedMessage, null, 400, ['document' => $allowedMessage]);
    }
    if (($file['size'] ?? 0) > TS_DOC_MAX_BYTES) {
        apiResponse(false, "The document must not be larger than 2 MB.", null, 400, ['document' => "The document must not be larger than 2 MB."]);
    }

    // Check the file's real content, not just its name.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!in_array($mime, TS_DOC_TYPES[$ext], true)) {
        apiResponse(false, $allowedMessage, null, 400, ['document' => $allowedMessage]);
    }

    $relativeDir = TS_DOC_DIR . '/' . date('Y/m');
    $dir = documentRootPath($relativeDir);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create the folder " . $relativeDir);
    }

    // Stored under a random name: the uploaded name can't overwrite or reach other files.
    $relativePath = $relativeDir . '/ts_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], documentRootPath($relativePath))) {
        throw new RuntimeException("Unable to store the uploaded document.");
    }

    return ['name' => mb_substr($originalName, 0, 255), 'path' => $relativePath];
}

/* Deletes a stored task document (only files inside the timesheet folder). */
function tsDeleteDocumentFile($relativePath)
{
    $relativePath = (string)$relativePath;
    if ($relativePath === '' || strpos($relativePath, TS_DOC_DIR . '/') !== 0 || strpos($relativePath, '..') !== false) {
        return;
    }
    $fullPath = documentRootPath($relativePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
 * Validates a "YYYY-MM-DD" date from the request.
 * Returns the date string, or ends the request with a message.
 */
function tsRequestDate($value)
{
    $value = trim((string)($value ?? ''));
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        apiResponse(false, "Please select a valid date.", null, 400);
    }

    return $value;
}

/* Current time in India. */
function tsNow()
{
    return new DateTime('now', new DateTimeZone(TS_TIMEZONE));
}

/*
 * Whether the employee may still change (add / edit / delete / submit) the
 * timesheet of this date: only today, before the cut-off time.
 * Returns "" when allowed, otherwise the reason.
 */
function tsDateLockReason($dateYmd)
{
    $now = tsNow();
    $today = $now->format('Y-m-d');

    if ($dateYmd > $today) {
        return "A timesheet cannot be filled for a future date.";
    }
    if ($dateYmd < $today) {
        return "Past timesheets can't be changed. A day's timesheet can be filled only on that day, until " . TS_CUTOFF_LABEL . ".";
    }
    if ((int)$now->format('G') >= TS_CUTOFF_HOUR) {
        return "Today's timesheet could be changed only until " . TS_CUTOFF_LABEL . ".";
    }

    return "";
}

/* Approved / Rejected days are final; Draft and Submitted can still change before the cut-off. */
function tsIsFinalStatus($status)
{
    return $status === 'A' || $status === 'R';
}

/* A change to a submitted day puts it back to Draft, so it has to be submitted again. */
function tsReopenDay($tsId, $empCode)
{
    tsExec(
        "UPDATE EPT_TIMESHEET
            SET STATUS = 'D', CHG_BY = :EMP_CODE, CHG_ON = SYSDATE
          WHERE ID = :TS_ID AND STATUS = 'S'",
        [':TS_ID' => $tsId, ':EMP_CODE' => $empCode]
    );
}

/* Runs one INSERT / UPDATE / DELETE inside the open transaction. Throws on failure. */
function tsExec($sql, $binds = [])
{
    global $sql___func___con;

    $stmt = oci_parse($sql___func___con, $sql);
    if (!$stmt) {
        throw new RuntimeException(oci_error($sql___func___con)['message'] ?? 'Unable to prepare the query.');
    }

    foreach ($binds as $name => $value) {
        $binds[$name] = $value;
        oci_bind_by_name($stmt, $name, $binds[$name]);
    }

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException($error['message'] ?? 'Unable to save.');
    }

    $rows = oci_num_rows($stmt);
    oci_free_statement($stmt);

    return $rows;
}

function tsCommit()
{
    global $sql___func___con;
    oci_commit($sql___func___con);
}

function tsRollback()
{
    global $sql___func___con;
    oci_rollback($sql___func___con);
}

/* The employee's timesheet day for a date, or null. */
function tsFindDay($empCode, $dateYmd)
{
    $rows = multiRec(
        "SELECT ID, STATUS,
                TO_CHAR(SUBMITTED_ON, 'DD-Mon-YYYY HH24:MI') AS SUBMITTED_ON,
                AUTH_REMARKS
           FROM EPT_TIMESHEET
          WHERE EMP_CODE = :EMP_CODE
            AND TS_DATE = TO_DATE(:TS_DATE, 'YYYY-MM-DD')",
        [':EMP_CODE' => $empCode, ':TS_DATE' => $dateYmd]
    );

    return $rows[0] ?? null;
}

/*
 * Reporting managers an employee may pick for a task: active employees of
 * their own department (EPT_BCS_EMPLOYEE.DEPT_CODE), plus the manager recorded
 * in their REPORT_TO even when that person is in another department.
 * Returns ['deptCode', 'deptName', 'defaultCode', 'options' => [[value, label]]].
 */
function tsManagerOptions($empCode)
{
    $me = multiRec(
        "SELECT E.DEPT_CODE, E.REPORT_TO,
                (SELECT D.DESCR FROM EPT_BCS_DEPARTMENT D WHERE D.DEPT_CODE = E.DEPT_CODE) AS DEPT_NAME
           FROM EPT_BCS_EMPLOYEE E
          WHERE E.EMP_CODE = :EMP_CODE",
        [':EMP_CODE' => $empCode]
    );
    $me = $me[0] ?? [];
    $deptCode = $me['DEPT_CODE'] ?? '';
    $reportTo = $me['REPORT_TO'] ?? '';

    $rows = multiRec(
        "SELECT E.EMP_CODE,
                TRIM(E.EMP_FNAME || ' ' || E.EMP_LNAME) AS EMP_NAME
           FROM EPT_BCS_EMPLOYEE E
          WHERE E.STATUS = 'A'
            AND E.EMP_CODE <> :EMP_CODE
            AND (E.DEPT_CODE = :DEPT_CODE OR E.EMP_CODE = :REPORT_TO)
          ORDER BY EMP_NAME",
        [':EMP_CODE' => $empCode, ':DEPT_CODE' => $deptCode, ':REPORT_TO' => $reportTo]
    );

    $options = array_map(fn($r) => [
        'value' => $r['EMP_CODE'],
        'label' => trim($r['EMP_NAME']) . ' (' . $r['EMP_CODE'] . ')',
    ], $rows);

    $codes = array_column($options, 'value');

    return [
        'deptCode'    => $deptCode,
        'deptName'    => $me['DEPT_NAME'] ?? '',
        'defaultCode' => in_array($reportTo, $codes, true) ? $reportTo : '',
        'options'     => $options,
    ];
}

/* ---------------------------------------------------------------
   Daily Timesheet Report (reporting managers)
--------------------------------------------------------------- */

const TS_REPORT_MAX_DAYS = 92;
const TS_REPORT_ROUTE = 'eportal/timesheet-report';   // EPT_SUBMENU.PROG_URL

/*
 * A reporting manager is someone who is an active employee's REPORT_TO, or
 * who has been picked as reporting manager on a timesheet task.
 * (getMenu.php shows the report menu with the same rule.)
 */
function tsIsManager($empCode)
{
    $rows = multiRec(
        "SELECT 1 AS Y FROM DUAL
          WHERE EXISTS (SELECT 1 FROM EPT_BCS_EMPLOYEE WHERE REPORT_TO = :EMP_CODE AND STATUS = 'A')
             OR EXISTS (SELECT 1 FROM EPT_TIMESHEET_TASK WHERE REPORT_TO = :EMP_CODE)",
        [':EMP_CODE' => $empCode]
    );
    return !empty($rows);
}

/* Ends the request unless the logged-in employee is a reporting manager. */
function tsRequireManager($empCode)
{
    if (!tsIsManager($empCode)) {
        apiResponse(false, "The Daily Timesheet Report is only available to reporting managers.", null, 403);
    }
}

/* Validated report filters from the request: [from, to, empCode]. */
function tsReportFilters($input)
{
    $today = tsNow()->format('Y-m-d');
    $from = !empty($input['from']) ? tsRequestDate($input['from']) : $today;
    $to = !empty($input['to']) ? tsRequestDate($input['to']) : $from;

    if ($from > $to) {
        apiResponse(false, "The From date must be on or before the To date.", null, 400);
    }
    if ((new DateTime($from))->diff(new DateTime($to))->days >= TS_REPORT_MAX_DAYS) {
        apiResponse(false, "Please select at most " . TS_REPORT_MAX_DAYS . " days.", null, 400);
    }

    $emp = trim((string)($input['empCode'] ?? ''));
    return [$from, $to, preg_match('/^[A-Za-z0-9]{1,10}$/', $emp) ? $emp : ''];
}

/*
 * Report rows for a manager: their own tasks (any status) and their team's
 * tasks reported to them on submitted days (Drafts stay private).
 */
function tsReportRows($managerCode, $from, $to, $empCode = '')
{
    return multiRec(
        "SELECT TO_CHAR(H.TS_DATE, 'YYYY-MM-DD') AS TS_DATE,
                H.EMP_CODE,
                TRIM(E.EMP_FNAME || ' ' || E.EMP_LNAME) AS EMP_NAME,
                H.STATUS AS DAY_STATUS,
                TO_CHAR(H.SUBMITTED_ON, 'DD-Mon-YYYY HH24:MI') AS SUBMITTED_ON,
                T.ID, C.CAT_DESC, P.PROJ_DESC, T.TASK_TITLE, T.TASK_DESC, T.TASK_STATUS, T.REMARKS,
                T.DOC_NAME, T.REPORT_TO,
                TRIM(M.EMP_FNAME || ' ' || M.EMP_LNAME) AS REPORT_TO_NAME
           FROM EPT_TIMESHEET_TASK T
           JOIN EPT_TIMESHEET H ON H.ID = T.TS_ID
           JOIN EPT_TS_CATEGORY C ON C.ID = T.CATEGORY_ID
           LEFT JOIN EPT_BCS_EMPLOYEE E ON E.EMP_CODE = H.EMP_CODE
           LEFT JOIN EPT_BCS_EMPLOYEE M ON M.EMP_CODE = T.REPORT_TO
           LEFT JOIN EPT_TS_PROJECT P ON P.ID = T.PROJECT_ID
          WHERE H.TS_DATE BETWEEN TO_DATE(:D_FROM, 'YYYY-MM-DD') AND TO_DATE(:D_TO, 'YYYY-MM-DD')
            AND (H.EMP_CODE = :MANAGER OR (T.REPORT_TO = :MANAGER AND H.STATUS IN ('S', 'A', 'R')))
            AND (:EMP_FILTER IS NULL OR H.EMP_CODE = :EMP_FILTER)
          ORDER BY H.TS_DATE DESC, EMP_NAME, T.ID",
        [':D_FROM' => $from, ':D_TO' => $to, ':MANAGER' => $managerCode, ':EMP_FILTER' => $empCode !== '' ? $empCode : null]
    );
}

/* Employees for the report's filter: the manager, plus everyone who reports to them. */
function tsReportEmployees($managerCode)
{
    return multiRec(
        "SELECT E.EMP_CODE, TRIM(E.EMP_FNAME || ' ' || E.EMP_LNAME) AS EMP_NAME
           FROM EPT_BCS_EMPLOYEE E
          WHERE E.EMP_CODE = :MANAGER
             OR (E.REPORT_TO = :MANAGER AND E.STATUS = 'A')
             OR E.EMP_CODE IN (SELECT H.EMP_CODE FROM EPT_TIMESHEET H
                                 JOIN EPT_TIMESHEET_TASK T ON T.TS_ID = H.ID
                                WHERE T.REPORT_TO = :MANAGER)
          ORDER BY EMP_NAME",
        [':MANAGER' => $managerCode]
    );
}

/* Ends the request with a logged, user-friendly error. */
function tsFail(Throwable $e, $message)
{
    tsRollback();
    logOracleError(
        ["message" => $e->getMessage(), "file" => $e->getFile(), "line" => $e->getLine()],
        "timesheet"
    );
    apiResponse(false, $message, null, 500);
}
