<?php
/*
 * Candidate CV / photo storage, shared by the careers portal and HR's recruitment
 * pages. No session or output side effects.
 *
 * DOC_PATH / IMAGE_PATH values:
 *   "sdlhub/cv/<file>", "sdlhub/photo/<file>"  -> stored by SDL Hub under $_ENV['CAREERS_UPLOAD_ROOT']
 *   anything else (e.g. "cv/<file>")            -> legacy file on the old careers server
 */

const CAREERS_FILE_PREFIX = 'sdlhub/';
const CAREERS_LEGACY_FILES_URL = 'https://hrms.sdlindia.com/hradmin/careers/';
const CAREERS_FILE_TYPES = [
    'cv' => [
        'column' => 'DOC_PATH', 'folder' => 'cv', 'maxBytes' => 5 * 1024 * 1024,
        'mimes' => [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ],
    ],
    'photo' => [
        'column' => 'IMAGE_PATH', 'folder' => 'photo', 'maxBytes' => 2 * 1024 * 1024,
        'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png'],
    ],
];

function careersUploadRoot()
{
    $root = rtrim((string)($_ENV['CAREERS_UPLOAD_ROOT'] ?? ''), '/');
    return $root !== '' ? $root : null;
}

function careersIsStoredFile($path)
{
    return strpos((string)$path, CAREERS_FILE_PREFIX) === 0;
}

/** Absolute path of an SDL Hub stored file, or null when missing / outside the upload root. */
function careersStoredFilePath($path)
{
    $root = careersUploadRoot();
    if ($root === null || !careersIsStoredFile($path)) {
        return null;
    }
    $relative = substr((string)$path, strlen(CAREERS_FILE_PREFIX));
    if (!preg_match('#^(cv|photo)/[A-Za-z0-9_.-]+$#', $relative)) {
        return null;
    }
    $full = realpath($root . '/' . $relative);
    $realRoot = realpath($root);
    if ($full === false || $realRoot === false || strpos($full, $realRoot . '/') !== 0 || !is_file($full)) {
        return null;
    }
    return $full;
}

function careersLegacyFileUrl($path)
{
    $path = ltrim(trim((string)$path), '/');
    return $path === '' ? '' : CAREERS_LEGACY_FILES_URL . str_replace('%2F', '/', rawurlencode($path));
}

/** Stream a stored file (inline) and exit. */
function careersStreamFile($fullPath, $downloadName)
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fullPath) ?: 'application/octet-stream';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($fullPath);
    exit;
}
