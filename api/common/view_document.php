<?php
// Base folder on your Linux server/mount
$storageBase = '/mnt/documents/';

// Get the relative path from query parameter (e.g. ?file=uploads/member_document/00575_...jpeg)
$relativePath = isset($_GET['file']) ? $_GET['file'] : '';

if (empty($relativePath)) {
    http_response_code(400);
    exit('File path is required.');
}

// Security: Prevent Directory Traversal attacks (e.g., ../../etc/passwd)
$cleanRelativePath = ltrim(str_replace('..', '', $relativePath), '/');
$fullFilePath = $storageBase . $cleanRelativePath;

// Check if file exists on /mnt/documents
if (!file_exists($fullFilePath)) {
    http_response_code(404);
    exit('File not found.');
}

// Detect MIME type and stream the file directly to the browser
$mimeType = mime_content_type($fullFilePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullFilePath));
header('Content-Disposition: inline; filename="' . basename($fullFilePath) . '"');

readfile($fullFilePath);
exit;