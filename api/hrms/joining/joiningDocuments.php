<?php
require_once __DIR__ . '/../../config/path.php';
/*
 * Employee Document Upload (joining task 13), as legacy emp_doc_upload_sv.php:
 * files go to input/emp_docs/<emp code>/<emp code>_<document type>.<ext> and
 * HR_EMP_DOCS.DOC_PATH holds that relative path. Generated letters sit directly
 * in input/emp_docs/ (tenure / exit letter code).
 */

// Folder and legacy server URL come from config/path.php (config/env.php).
const JOINING_DOC_DIR = EMP_DOCS_DIR;
// Older documents live on the legacy HRMS server under the same relative path.
const JOINING_DOC_LEGACY_URL = HRMS_LEGACY_DOC_URL;
const JOINING_DOC_MAX_BYTES = 5 * 1024 * 1024;
const JOINING_DOC_TYPES = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

/** The backend's input/emp_docs folder (shared with the letter generators). */
function joiningDocBaseDir()
{
    return hrmsDocPath(JOINING_DOC_DIR);
}

/** Whether a DOC_PATH is a safe input/emp_docs path (optionally in an employee subfolder). */
function joiningDocIsSafePath($path)
{
    return (bool)preg_match('#^' . preg_quote(JOINING_DOC_DIR, '#') . '/(?:[A-Za-z0-9]{1,10}/)?[A-Za-z0-9 _.()-]+$#', (string)$path)
        && strpos((string)$path, '..') === false;
}

/** Uploads are in the employee's own subfolder; files at the top level are generated letters. */
function joiningDocIsUpload($path, $employeeCode)
{
    return strpos((string)$path, JOINING_DOC_DIR . '/' . $employeeCode . '/') === 0;
}

/** Absolute path of a document stored on this server, or null. */
function joiningDocLocalPath($path)
{
    if (!joiningDocIsSafePath($path)) {
        return null;
    }
    $full = realpath(joiningDocBaseDir() . '/' . substr((string)$path, strlen(JOINING_DOC_DIR) + 1));
    $base = realpath(joiningDocBaseDir());
    if ($full === false || $base === false || strpos($full, $base . '/') !== 0 || !is_file($full)) {
        return null;
    }
    return $full;
}

function joiningDocTypeOptions()
{
    return joiningRecords("SELECT DOCTYP_ID AS VALUE, DOCTYP_DESC AS LABEL FROM HR_DOC_TYPES ORDER BY DOCTYP_ID");
}

/** An employee's documents, newest first. */
function joiningDocList($employeeCode)
{
    $rows = joiningRecords("SELECT D.ID, D.DOC_ID, T.DOCTYP_DESC, D.DOC_PATH,
            TO_CHAR(D.CHG_ON, 'DD-Mon-YYYY') AS UPLOADED_ON, D.CHG_BY
        FROM HR_EMP_DOCS D LEFT JOIN HR_DOC_TYPES T ON T.DOCTYP_ID = D.DOC_ID
        WHERE D.EMP_CODE = :emp_code ORDER BY D.ID DESC", [':emp_code' => (string)$employeeCode]);
    foreach ($rows as &$row) {
        $row['CAN_DELETE'] = joiningDocIsUpload($row['DOC_PATH'], $employeeCode);
    }
    unset($row);
    return $rows;
}
