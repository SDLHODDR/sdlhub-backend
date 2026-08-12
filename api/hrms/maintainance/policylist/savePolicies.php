<?php

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $data;
}

// ---------------------------------------------------------
// Collect + validate input (server-side; client validation
// in usePolicyHandler.validateForm() can be bypassed)
// ---------------------------------------------------------
$policyId   = trim($data['id'] ?? '');
$compName   = trim($data['COMP_NAME'] ?? '');
$policyName = trim($data['POLICY_NAME'] ?? '');
$startDate  = trim($data['START_DATE'] ?? '');
$endDate    = trim($data['END_DATE'] ?? '');
$policyDesc = trim($data['POLICY_DESC'] ?? '');
$isMandat   = isset($data['IS_MANDAT']) && $data['IS_MANDAT'] === 'Y' ? 'Y' : 'N';

// DEPT_ID[] / DIVISION_ID[] are optional (PHP form allowed empty selections)
$deptIds     = isset($data['DEPT_ID']) && is_array($data['DEPT_ID']) ? array_filter($data['DEPT_ID']) : [];
$divisionIds = isset($data['DIVISION_ID']) && is_array($data['DIVISION_ID']) ? array_filter($data['DIVISION_ID']) : [];

$errors = [];
if ($compName === '') $errors[] = "Company is required.";
if ($policyName === '') $errors[] = "Policy name is required.";
if ($startDate === '') $errors[] = "Start date is required.";
if ($endDate === '') $errors[] = "End date is required.";
if ($policyDesc === '') $errors[] = "Policy description is required.";
if ($startDate !== '' && $endDate !== '' && strtotime($endDate) < strtotime($startDate)) {
    $errors[] = "End date cannot be before start date.";
}

if (!empty($errors)) {
    apiResponse(false, implode(" ", $errors), null, 422);
}

// ---------------------------------------------------------
// File upload (optional on update — required on create,
// mirrors the frontend's validateDocFile rule)
// ---------------------------------------------------------
$docPath = null;
$hasUpload = isset($_FILES['doc']) && is_uploaded_file($_FILES['doc']['tmp_name'] ?? '');

if (!$hasUpload && $policyId === '') {
    apiResponse(false, "Document upload is required.", null, 422);
}

if ($hasUpload) {
    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/jpg',
        'image/png',
    ];
    $maxSizeBytes = 1048576; // 1 MB — keep in sync with validateDocFile() on the frontend

    $fileType = $_FILES['doc']['type'] ?? '';
    $fileSize = $_FILES['doc']['size'] ?? 0;
    $fileTmp  = $_FILES['doc']['tmp_name'];
    $fileName = $_FILES['doc']['name'] ?? '';

    if (!in_array($fileType, $allowedMimes, true)) {
        apiResponse(false, "Only PDF, DOC/DOCX, or image files are allowed.", null, 422);
    }
    if ($fileSize > $maxSizeBytes) {
        apiResponse(false, "Maximum upload size is 1 MB.", null, 422);
    }

    // Generate a safe, collision-free filename instead of trusting
    // the client-supplied name (path traversal / overwrite risk).
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);
    $generatedName = 'policy_' . bin2hex(random_bytes(8)) . ($safeExt ? '.' . $safeExt : '');

    $uploadDir = __DIR__ . '/../../../input/policy_doc/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $destPath = $uploadDir . $generatedName;
    if (!move_uploaded_file($fileTmp, $destPath)) {
        apiResponse(false, "Unable to store uploaded document.", null, 500);
    }

    // Web-relative path saved to DB / returned to frontend
    $docPath = 'input/policy_doc/' . $generatedName;
}

// ---------------------------------------------------------
// Save (transaction: HR_POLICY + HR_POLICY_DEPT + HR_POLICY_DIVSN)
// ---------------------------------------------------------
try {
    oci_execute(oci_parse($sql___func___con, "SET TRANSACTION READ WRITE"), OCI_NO_AUTO_COMMIT);

    if ($policyId !== '') {
        // ---- UPDATE ----
        $sql = "UPDATE HR_POLICY
                   SET COMP_NAME   = :comp_name,
                       POLICY_NAME = :policy_name,
                       START_DATE  = :start_date,
                       END_DATE    = :end_date,
                       POLICY_DESC = :policy_desc,
                       IS_MANDAT   = :is_mandat,
                       CHG_ON      = SYSDATE,
                       CHG_BY      = :chg_by"
                . ($docPath !== null ? ", DOC_PATH = :doc_path" : "") . "
                 WHERE POLI_ID = :poli_id";

        $stmt = oci_parse($sql___func___con, $sql);
        oci_bind_by_name($stmt, ':comp_name', $compName);
        oci_bind_by_name($stmt, ':policy_name', $policyName);
        oci_bind_by_name($stmt, ':start_date', $startDate);
        oci_bind_by_name($stmt, ':end_date', $endDate);
        oci_bind_by_name($stmt, ':policy_desc', $policyDesc);
        oci_bind_by_name($stmt, ':is_mandat', $isMandat);
        oci_bind_by_name($stmt, ':chg_by', $empCode);
        if ($docPath !== null) {
            oci_bind_by_name($stmt, ':doc_path', $docPath);
        }
        oci_bind_by_name($stmt, ':poli_id', $policyId);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            throw new Exception("Update failed.");
        }

        $newId = $policyId;
    } else {
        // ---- INSERT ----
        // (Legacy version had a stray "C" placeholder here where
        // POLICY_DESC should have been — fixed below.)
        $sql = "INSERT INTO HR_POLICY
                    (COMP_NAME, POLICY_NAME, START_DATE, END_DATE, POLICY_DESC, STATUS, IS_MANDAT, CHG_ON, CHG_BY)
                VALUES
                    (:comp_name, :policy_name, :start_date, :end_date, :policy_desc, 'N', :is_mandat, SYSDATE, :chg_by)
                RETURNING POLI_ID INTO :new_id";

        $stmt = oci_parse($sql___func___con, $sql);
        oci_bind_by_name($stmt, ':comp_name', $compName);
        oci_bind_by_name($stmt, ':policy_name', $policyName);
        oci_bind_by_name($stmt, ':start_date', $startDate);
        oci_bind_by_name($stmt, ':end_date', $endDate);
        oci_bind_by_name($stmt, ':policy_desc', $policyDesc);
        oci_bind_by_name($stmt, ':is_mandat', $isMandat);
        oci_bind_by_name($stmt, ':chg_by', $empCode);
        oci_bind_by_name($stmt, ':new_id', $newId, 20);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            throw new Exception("Insert failed.");
        }

        if ($docPath !== null) {
            $updDocStmt = oci_parse($sql___func___con, "UPDATE HR_POLICY SET DOC_PATH = :doc_path WHERE POLI_ID = :poli_id");
            oci_bind_by_name($updDocStmt, ':doc_path', $docPath);
            oci_bind_by_name($updDocStmt, ':poli_id', $newId);
            if (!oci_execute($updDocStmt, OCI_NO_AUTO_COMMIT)) {
                throw new Exception("Failed to attach uploaded document.");
            }
        }
    }

    // Replace department/division associations
    $delDivStmt = oci_parse($sql___func___con, "DELETE FROM HR_POLICY_DIVSN WHERE POLICY_ID = :poli_id");
    oci_bind_by_name($delDivStmt, ':poli_id', $newId);
    oci_execute($delDivStmt, OCI_NO_AUTO_COMMIT);

    $delDeptStmt = oci_parse($sql___func___con, "DELETE FROM HR_POLICY_DEPT WHERE POLICY_ID = :poli_id");
    oci_bind_by_name($delDeptStmt, ':poli_id', $newId);
    oci_execute($delDeptStmt, OCI_NO_AUTO_COMMIT);

    foreach ($divisionIds as $div) {
        $insDivStmt = oci_parse($sql___func___con,
            "INSERT INTO HR_POLICY_DIVSN (POLICY_ID, DIVSN_ID, CHG_ON, CHG_BY)
             VALUES (:poli_id, :divsn_id, SYSDATE, :chg_by)");
        oci_bind_by_name($insDivStmt, ':poli_id', $newId);
        oci_bind_by_name($insDivStmt, ':divsn_id', $div);
        oci_bind_by_name($insDivStmt, ':chg_by', $empCode);
        if (!oci_execute($insDivStmt, OCI_NO_AUTO_COMMIT)) {
            throw new Exception("Failed to save division association.");
        }
    }

    foreach ($deptIds as $dept) {
        $insDeptStmt = oci_parse($sql___func___con,
            "INSERT INTO HR_POLICY_DEPT (POLICY_ID, DEPT_ID, CHG_ON, CHG_BY)
             VALUES (:poli_id, :dept_id, SYSDATE, :chg_by)");
        oci_bind_by_name($insDeptStmt, ':poli_id', $newId);
        oci_bind_by_name($insDeptStmt, ':dept_id', $dept);
        oci_bind_by_name($insDeptStmt, ':chg_by', $empCode);
        if (!oci_execute($insDeptStmt, OCI_NO_AUTO_COMMIT)) {
            throw new Exception("Failed to save department association.");
        }
    }

    oci_commit($sql___func___con);

    apiResponse(true, $policyId !== '' ? "Policy updated successfully." : "Policy created successfully.", [
        "ID" => $newId,
        "DOC_PATH" => $docPath,
    ]);
} catch (Throwable $e) {
    oci_rollback($sql___func___con);

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "savePolicy.php"
    );

    apiResponse(false, "Unable to save policy.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}