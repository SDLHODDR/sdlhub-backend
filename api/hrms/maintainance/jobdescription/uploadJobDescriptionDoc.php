<?php
require_once __DIR__ . '/../../../config/path.php';

/*
 * Uploads the JD document (Basic Details "Upload Document") into input/jd_docs,
 * as legacy jd_sv.php did, with a random name; opened through
 * viewJobDescriptionDoc.php (login required).
 */

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

const JD_DOC_MAX_BYTES = 2 * 1024 * 1024;
const JD_DOC_TYPES = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

try {
    $empCode = $_SESSION['emp_code'] ?? '';
    if ($empCode === '') {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }
    if (!$conn) {
        apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    $jobId = trim((string)($_POST['id'] ?? ''));
    if ($jobId === '' || !ctype_digit($jobId)) {
        apiResponse(false, "Job description ID is required.", null, 400);
    }

    $file = $_FILES['doc'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        apiResponse(false, "Please choose a document to upload.", null, 422);
    }
    if ((int)$file['size'] > JD_DOC_MAX_BYTES) {
        apiResponse(false, "Maximum upload size is 2 MB.", null, 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(JD_DOC_TYPES[$mime])) {
        apiResponse(false, "Only PDF, DOC/DOCX, JPG or PNG files are allowed.", null, 422);
    }

    $stmt = oci_parse($conn, "SELECT ID, JD_DOC_PATH FROM HR_JD WHERE ID = :jd_id");
    oci_bind_by_name($stmt, ':jd_id', $jobId);
    oci_execute($stmt, OCI_NO_AUTO_COMMIT);
    $job = oci_fetch_assoc($stmt) ?: [];
    oci_free_statement($stmt);
    if (empty($job['ID'])) {
        apiResponse(false, "Job description not found.", null, 404);
    }

    $uploadDir = hrmsDocPath(JD_DOCS_DIR) . '/';
    if ((!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) || !is_writable($uploadDir)) {
        logOracleError(['message' => "JD document folder not writable: {$uploadDir}"], 'uploadJobDescriptionDoc.php');
        apiResponse(false, "Unable to store the document: the server cannot write to " . JD_DOCS_DIR . ". Please contact IT.", null, 500);
    }
    $fileName = 'jd_' . $jobId . '_' . bin2hex(random_bytes(8)) . '.' . JD_DOC_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        apiResponse(false, "Unable to store uploaded document.", null, 500);
    }
    $docPath = JD_DOCS_DIR . '/' . $fileName;

    $stmt = oci_parse($conn, "UPDATE HR_JD SET JD_DOC_PATH = :doc_path WHERE ID = :jd_id");
    oci_bind_by_name($stmt, ':doc_path', $docPath);
    oci_bind_by_name($stmt, ':jd_id', $jobId);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        oci_rollback($conn);
        @unlink($uploadDir . $fileName);
        apiResponse(false, "Unable to save the document reference.", null, 500);
    }
    oci_free_statement($stmt);
    oci_commit($conn);

    // Remove the document this upload replaced.
    $oldPath = (string)($job['JD_DOC_PATH'] ?? '');
    if (preg_match('#^' . preg_quote(JD_DOCS_DIR, '#') . '/([A-Za-z0-9_.-]+)$#', $oldPath, $oldMatch) && $oldPath !== $docPath) {
        @unlink($uploadDir . $oldMatch[1]);
    }

    apiResponse(true, "Document uploaded successfully.", ['JD_DOC_PATH' => $docPath], 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to upload the document.", null, 500);
}
