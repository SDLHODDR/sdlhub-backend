<?php
/*
 * Opens the document attached to one of the logged-in employee's timesheet tasks.
 * Request: GET ?id=<taskId>
 *
 * The file path comes from the database, never from the request. It opens
 * for the employee who owns the task, and for the reporting manager the
 * task was submitted to.
 */

require_once __DIR__ . "/tsCommon.php";

try {
    $taskId = (int)($_GET['id'] ?? 0);

    $rows = $taskId > 0 ? multiRec(
        "SELECT T.DOC_NAME, T.DOC_PATH
           FROM EPT_TIMESHEET_TASK T
           JOIN EPT_TIMESHEET H ON H.ID = T.TS_ID
          WHERE T.ID = :ID
            AND (H.EMP_CODE = :EMP_CODE
                 OR (T.REPORT_TO = :EMP_CODE AND H.STATUS IN ('S', 'A', 'R')))",
        [':ID' => $taskId, ':EMP_CODE' => $empCode]
    ) : [];

    $doc = $rows[0] ?? null;
    $path = (string)($doc['DOC_PATH'] ?? '');

    if (!$doc || $path === '' || strpos($path, TS_DOC_DIR . '/') !== 0 || strpos($path, '..') !== false) {
        apiResponse(false, "Document not found.", null, 404);
    }

    $fullPath = documentRootPath($path);
    if (!is_file($fullPath)) {
        apiResponse(false, "Document not found.", null, 404);
    }

    $name = $doc['DOC_NAME'] ?: basename($fullPath);
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

    header_remove('Content-Type');
    header('Content-Type: ' . (mime_content_type($fullPath) ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($fullPath));
    header("Content-Disposition: inline; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
} catch (Throwable $e) {
    tsFail($e, "Unable to open the document.");
}
