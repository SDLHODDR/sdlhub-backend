<?php
/*
 * The logged-in candidate's CV and profile photo (legacy application_form_sv.php?saveCV).
 *   GET  ?type=cv|photo                     -> the file (old-server files redirect there)
 *   POST multipart: type=cv|photo, file     -> store under CAREERS_UPLOAD_ROOT, update DOC_PATH / IMAGE_PATH
 */
require_once __DIR__ . '/careersCommon.php';
require_once __DIR__ . '/careersFiles.php';

$type = (string)($_REQUEST['type'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        if (!isset(CAREERS_FILE_TYPES[$type])) {
            throw new CareersException('Invalid document type.', 422);
        }
        $candidate = careersCandidate();
        $column = CAREERS_FILE_TYPES[$type]['column'];
        $row = careersRow("SELECT {$column} AS PATH FROM HR_CANDIDATE_INFO WHERE ID = :id", [':id' => (string)$candidate['ID']]);
        $path = trim((string)($row['PATH'] ?? ''));
        if ($path === '') {
            throw new CareersException('No document uploaded yet.', 404);
        }
        if (!careersIsStoredFile($path)) {
            oci_close($sql___func___con);
            header('Location: ' . careersLegacyFileUrl($path), true, 302);
            exit;
        }
        $full = careersStoredFilePath($path);
        if ($full === null) {
            throw new CareersException('The document could not be found.', 404);
        }
        oci_close($sql___func___con);
        careersStreamFile($full, basename($full));
    } catch (CareersException $e) {
        apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
    } catch (Throwable $e) {
        logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'careers/document.php');
        apiResponse(false, 'Something went wrong. Please try again.', null, 500);
    }
    exit;
}

careersRunAction(static function () use ($type) {
    $candidate = careersCandidate();
    if (!isset(CAREERS_FILE_TYPES[$type])) {
        throw new CareersException('Invalid document type.', 422);
    }
    $spec = CAREERS_FILE_TYPES[$type];
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $tooBig = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        throw new CareersException($tooBig ? 'The file is too large.' : 'Please choose a file to upload.', 422);
    }
    if ($file['size'] > $spec['maxBytes']) {
        throw new CareersException('The file is too large (maximum ' . ($spec['maxBytes'] / 1024 / 1024) . ' MB).', 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($spec['mimes'][$mime])) {
        throw new CareersException($type === 'cv' ? 'Please upload a PDF or Word document.' : 'Please upload a JPG or PNG image.', 422);
    }

    $root = careersUploadRoot();
    $folder = $root === null ? null : $root . '/' . $spec['folder'];
    if ($folder === null || (!is_dir($folder) && !@mkdir($folder, 0750, true)) || !is_writable($folder)) {
        logOracleError(['message' => "Careers upload folder not writable: {$folder}"], 'careers/document.php');
        throw new CareersException('Uploads are not available right now. Please try again later.', 503);
    }
    $name = $candidate['ID'] . '_' . bin2hex(random_bytes(8)) . '.' . $spec['mimes'][$mime];
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name)) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    $stored = CAREERS_FILE_PREFIX . $spec['folder'] . '/' . $name;

    $previous = careersRow("SELECT {$spec['column']} AS PATH FROM HR_CANDIDATE_INFO WHERE ID = :id", [':id' => (string)$candidate['ID']]);
    try {
        careersExecute("UPDATE HR_CANDIDATE_INFO SET {$spec['column']} = :path, CHG_ON = SYSDATE, CHG_BY = 'ONLINE' WHERE ID = :id",
            [':path' => $stored, ':id' => (string)$candidate['ID']], 'Could not save the document.');
    } catch (Throwable $e) {
        @unlink($folder . '/' . $name);
        throw $e;
    }
    $old = careersStoredFilePath($previous['PATH'] ?? '');
    return [$type === 'cv' ? 'CV uploaded.' : 'Profile picture uploaded.', ['name' => $name], static function () use ($old) {
        if ($old !== null) @unlink($old);
        return '';
    }];
}, 'careers/document.php');
