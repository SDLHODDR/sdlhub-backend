<?php
require_once __DIR__ . '/../../../config/path.php';

/*
 * GET ?id=POLI_ID -> the policy's document (HR_POLICY.DOC_PATH) for a logged-in user.
 * Streamed when the file is on this server (SDL Hub uploads); otherwise it is an older
 * document on the legacy HRMS server, which serves the same relative path.
 */

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

if (empty($_SESSION['emp_code'])) {
    apiResponse(false, "Session expired. Please login again.", null, 401);
}
session_write_close();

$policyId = trim((string)($_GET['id'] ?? ''));
if ($policyId === '' || !ctype_digit($policyId) || !$conn) {
    apiResponse(false, "Document not found.", null, 404);
}

$stmt = oci_parse($conn, "SELECT DOC_PATH FROM HR_POLICY WHERE POLI_ID = :poli_id");
oci_bind_by_name($stmt, ':poli_id', $policyId);
oci_execute($stmt, OCI_NO_AUTO_COMMIT);
$row = oci_fetch_assoc($stmt) ?: [];
oci_free_statement($stmt);
oci_close($conn);

// Only a plain file name directly under the policy folder (legacy names have spaces).
$docPath = (string)($row['DOC_PATH'] ?? '');
if (!preg_match('#^' . preg_quote(POLICY_DOCS_DIR, '#') . '/([^/\\\\]+)$#', $docPath, $match)
    || $match[1] === '.' || $match[1] === '..') {
    apiResponse(false, "Document not found.", null, 404);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header_remove('Content-Type');

$fullPath = hrmsDocPath(POLICY_DOCS_DIR . '/' . $match[1]);
if (!is_file($fullPath)) {
    header('Location: ' . HRMS_LEGACY_DOC_URL . str_replace('%2F', '/', rawurlencode($docPath)), true, 302);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
$inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $match[1]) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($fullPath);
exit;
