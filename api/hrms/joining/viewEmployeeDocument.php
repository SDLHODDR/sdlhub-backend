<?php
/*
 * GET ?path=input/emp_docs/... -> an employee document (HR_EMP_DOCS.DOC_PATH).
 * Streamed when the file is on this server; otherwise it is an older document on
 * the legacy HRMS server and the browser is sent there. For HRMS users whose
 * company scope covers the employee.
 */
require_once __DIR__ . '/joiningCommon.php';
require_once __DIR__ . '/joiningDocuments.php';

try {
    loadHrmsAccess($empCode);
    session_write_close();
    $path = trim((string)($_GET['path'] ?? ''));
    $doc = !joiningDocIsSafePath($path) ? [] : singRec("SELECT D.ID, E.COMP_ID FROM HR_EMP_DOCS D
            INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = D.EMP_CODE
        WHERE D.DOC_PATH = :path AND ROWNUM = 1", [':path' => $path]);
    if (empty($doc['ID']) || !in_array((string)$doc['COMP_ID'], array_map('strval', $_SESSION['compId'] ?? []), true)) {
        throw new JoiningRequestException('Document not found.', 404);
    }
    oci_close($sql___func___con);

    $full = joiningDocLocalPath($path);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Type');
    if ($full === null) {
        header('Location: ' . JOINING_DOC_LEGACY_URL . str_replace('%2F', '/', rawurlencode($path)), true, 302);
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($full)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'viewEmployeeDocument.php');
    apiResponse(false, 'Unable to open the document.', null, 500);
}
