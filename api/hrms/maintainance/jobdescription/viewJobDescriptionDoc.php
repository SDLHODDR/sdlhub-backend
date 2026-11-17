<?php
require_once __DIR__ . '/../../../config/path.php';

/*
 * Streams a JD document to a logged-in user (see uploadJobDescriptionDoc.php).
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

$jobId = trim((string)($_GET['id'] ?? ''));
if ($jobId === '' || !ctype_digit($jobId) || !$conn) {
    apiResponse(false, "Document not found.", null, 404);
}

$stmt = oci_parse($conn, "SELECT JD_DOC_PATH FROM HR_JD WHERE ID = :jd_id");
oci_bind_by_name($stmt, ':jd_id', $jobId);
oci_execute($stmt, OCI_NO_AUTO_COMMIT);
$row = oci_fetch_assoc($stmt) ?: [];
oci_free_statement($stmt);

$docPath = (string)($row['JD_DOC_PATH'] ?? '');
$fullPath = preg_match('#^' . preg_quote(JD_DOCS_DIR, '#') . '/([A-Za-z0-9_.-]+)$#', $docPath, $match)
    ? hrmsDocPath(JD_DOCS_DIR . '/' . $match[1])
    : '';
if ($fullPath === '' || !is_file($fullPath)) {
    apiResponse(false, "Document not found.", null, 404);
}

$types = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg' => 'image/jpeg', 'png' => 'image/png'];
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$inline = in_array($ext, ['pdf', 'jpg', 'png'], true);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="JD_' . $jobId . '.' . $ext . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
