<?php
/*
 * POST multipart { user_task_id, doc_type_id, file }      -> upload an employee document
 * POST           { user_task_id, action: 'delete', id }   -> remove an uploaded document
 * Employee Document Upload task (13, legacy emp_doc_upload_sv.php saveDocDet).
 */
require_once __DIR__ . '/joiningDetailCommon.php';
require_once __DIR__ . '/joiningDocuments.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = array_merge(joiningInput(), $_POST);

joiningRunAction(function () use ($input, $empCode) {
    $task = joiningLoadDetailTask($input['user_task_id'] ?? 0, $empCode);
    if ($task['SECTION'] !== 'documents') {
        throw new JoiningRequestException('This is not a document upload task.', 422);
    }
    $employee = $task['EMPLOYEE'];
    $code = $employee['EMP_CODE'];

    if (($input['action'] ?? '') === 'delete') {
        $doc = singRec("SELECT ID, DOC_PATH FROM HR_EMP_DOCS WHERE ID = :id AND EMP_CODE = :emp_code",
            [':id' => (string)(int)($input['id'] ?? 0), ':emp_code' => $code]);
        if (empty($doc['ID'])) {
            throw new JoiningRequestException('Document not found.', 404);
        }
        if (!joiningDocIsUpload($doc['DOC_PATH'], $code)) {
            throw new JoiningRequestException('Only uploaded documents can be removed here.', 422);
        }
        joiningExecute("DELETE FROM HR_EMP_DOCS WHERE ID = :id", [':id' => (string)$doc['ID']], 'Could not remove the document.');
        $others = singRec("SELECT COUNT(*) AS N FROM HR_EMP_DOCS WHERE DOC_PATH = :path", [':path' => $doc['DOC_PATH']]);
        $full = joiningDocLocalPath($doc['DOC_PATH']);
        if ($full !== null && (int)($others['N'] ?? 0) === 0) {
            @unlink($full);
        }
        return ['Document removed.', null];
    }

    $typeId = (string)(int)($input['doc_type_id'] ?? 0);
    $type = singRec("SELECT DOCTYP_ID, DOCTYP_DESC FROM HR_DOC_TYPES WHERE DOCTYP_ID = :id", [':id' => $typeId]);
    if (empty($type['DOCTYP_ID'])) {
        throw new JoiningRequestException('Please select the document type.', 422);
    }

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $tooBig = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        throw new JoiningRequestException($tooBig ? 'The file is too large (maximum 5 MB).' : 'Please choose a document to upload.', 422);
    }
    if ((int)$file['size'] > JOINING_DOC_MAX_BYTES) {
        throw new JoiningRequestException('The file is too large (maximum 5 MB).', 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(JOINING_DOC_TYPES[$mime])) {
        throw new JoiningRequestException('Please upload a PNG, JPG, JPEG, DOC, DOCX or PDF file.', 422);
    }

    // Legacy name: <emp code>_<document type>.<ext>, in the employee's subfolder.
    $folder = joiningDocBaseDir() . '/' . $code;
    if ((!is_dir($folder) && !@mkdir($folder, 0775, true)) || !is_writable($folder)) {
        logOracleError(['message' => "Employee document folder not writable: {$folder}"], 'uploadJoiningDocument.php');
        throw new JoiningRequestException('Unable to store the document: the server cannot write to ' . JOINING_DOC_DIR . '. Please contact IT.', 500);
    }
    $typeName = trim(preg_replace('/[^A-Za-z0-9 _.()-]+/', '', $type['DOCTYP_DESC']));
    $name = $code . '_' . ($typeName !== '' ? $typeName : 'Document') . '.' . JOINING_DOC_TYPES[$mime];
    $docPath = JOINING_DOC_DIR . '/' . $code . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name)) {
        throw new RuntimeException('Could not store the uploaded document.');
    }

    // Uploading the same document type again replaces the file; keep one row for it.
    $updated = joiningExecute("UPDATE HR_EMP_DOCS SET DOC_ID = :doc_id, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE EMP_CODE = :emp_code AND DOC_PATH = :doc_path", [
        ':doc_id' => $typeId, ':chg_by' => $empCode, ':emp_code' => $code, ':doc_path' => $docPath,
    ], 'Could not save the document.');
    if ($updated === 0) {
        joiningExecute("INSERT INTO HR_EMP_DOCS (ID, EMP_ID, EMP_CODE, DOC_ID, DOC_PATH, CHG_ON, CHG_BY)
            VALUES (0, :emp_id, :emp_code, :doc_id, :doc_path, SYSDATE, :chg_by)", [
            ':emp_id' => (string)$employee['ID'], ':emp_code' => $code, ':doc_id' => $typeId,
            ':doc_path' => $docPath, ':chg_by' => $empCode,
        ], 'Could not save the document.');
    }
    return ['Document uploaded.', ['DOC_PATH' => $docPath]];
}, 'uploadJoiningDocument.php');
