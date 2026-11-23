<?php
/*
 * Opens a policy document (HR_POLICY.DOC_PATH, e.g. input/policy_doc/policy_ab12.pdf).
 *
 * Policies uploaded through SDL Hub HRMS are stored on this server under
 * HRMS_DOC_ROOT (/mnt/documents/input/policy_doc). Policies uploaded before SDL Hub
 * use the same relative path on the legacy HRMS server, so when the file is not
 * here the request is redirected there.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/path.php';

if (empty($_SESSION['emp_code'])) {
    http_response_code(401);
    exit('Session expired.');
}

$path = trim((string)($_GET['path'] ?? ''));

// Only files directly inside input/policy_doc, with a plain file name.
$prefix = POLICY_DOCS_DIR . '/';
$name = substr($path, strlen($prefix));
if (strpos($path, $prefix) !== 0 || $name === '' || !preg_match('/^[A-Za-z0-9 _.()-]+$/', $name) || strpos($name, '..') !== false) {
    http_response_code(400);
    exit('Invalid document path.');
}

$base = realpath(hrmsDocPath(POLICY_DOCS_DIR));
$full = $base ? realpath($base . '/' . $name) : false;

if ($full !== false && strpos($full, $base . '/') === 0 && is_file($full)) {
    $mime = mime_content_type($full) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: inline; filename="' . basename($full) . '"');
    readfile($full);
    exit;
}

// Not on this server: an older policy kept on the legacy HRMS server.
header('Location: ' . HRMS_LEGACY_DOC_URL . $prefix . rawurlencode($name), true, 302);
exit;
