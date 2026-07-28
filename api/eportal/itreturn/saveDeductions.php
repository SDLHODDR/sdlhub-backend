<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/functions.php";

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") { 
    apiResponse(false, "Invalid request method", null, 400);
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["emp_code"])) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

$empCode = $_SESSION["emp_code"] ?? "";

/*
|--------------------------------------------------------------------------
| GET EMPLOYEE ID
|--------------------------------------------------------------------------
*/

$empData = singRec("
    SELECT ID
    FROM EPT_BCS_EMPLOYEE
    WHERE EMP_CODE = '".$empCode."'
");

$empId = $empData["ID"] ?? null;

if (!$empId) {   
    apiResponse(false, "Employee not found.", null, 400);
}

/*
|--------------------------------------------------------------------------
| CURRENT FINANCIAL YEAR
|--------------------------------------------------------------------------
*/

$acctPeriod = singRec("
   SELECT CODE
    FROM EPT_BCS_ACCT_PERIOD
    WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
");

$financialYear = $acctPeriod["CODE"] ?? "";

if (empty($financialYear)) {   
    apiResponse(false, "Financial year not found.", null, 400);
}

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (empty($_POST["DEDN"]) || !is_array($_POST["DEDN"])) {
    apiResponse(false, "No deduction data received.");
}

/*
|--------------------------------------------------------------------------
| FILE UPLOAD DIRECTORY
|--------------------------------------------------------------------------
*/
$publicPath = realpath(__DIR__ . "/../../../../public");

if ($publicPath === false) {
    apiResponse(false, "Public directory not found.");
}

$uploadDir = $publicPath .
    "/assets/incometax/" .
    $financialYear .
    "/" .
    $empCode .
    "/";

/*if (!$uploadDir) {
    echo json_encode([
        "status" => false,
        "message" => "Public directory not found"
    ]);
    exit;
}*/

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true)) {        
        apiResponse(false, "Failed to create upload directory.");
    }
}

try {

    if (!is_writable($uploadDir)) {
        throw new Exception("Upload directory is not writable.");
    }
    /*
    |--------------------------------------------------------------------------
    | LOOP THROUGH DEDUCTIONS
    |--------------------------------------------------------------------------
    */
    foreach ($_POST["DEDN"] as $headId => $amount) {

        $headId = trim($headId);

        if (!ctype_digit((string)$headId)) {
            throw new Exception("Invalid deduction head.");
        }

        $amount = is_numeric($amount) ? (float)$amount : 0;

        if ($amount < 0) {
            throw new Exception("Amount cannot be negative.");
        }

        if ($amount > 999999999) {
            throw new Exception("Invalid deduction amount.");
        }

        if ($headId === "") {
            continue;
        }

        $existingRecord = singRec("
            SELECT ATTACHMENTS
            FROM EPT_BCS_ITAX_DEDUCTIONS
            WHERE EMP_ID = '{$empId}'
            AND FY = '{$financialYear}'
            AND HEAD_ID = '{$headId}'
        ");

        $recordExists = !empty($existingRecord);

        $previousFile = $existingRecord["ATTACHMENTS"] ?? "";

        if ($recordExists) {

            $sql = "
                UPDATE EPT_BCS_ITAX_DEDUCTIONS
                SET
                    AMOUNT = :amount,
                    CHG_ON = SYSDATE,
                    CHG_BY = :chg_by
                WHERE EMP_ID = :emp_id
                AND FY = :fy
                AND HEAD_ID = :head_id
            ";

        } else {

            $sql = "
                INSERT INTO EPT_BCS_ITAX_DEDUCTIONS
                (
                    ID,
                    EMP_ID,
                    HEAD_ID,
                    ATTACHMENTS,
                    AMOUNT,
                    CHG_ON,
                    CHG_BY,
                    FY
                )
                VALUES
                (
                    NULL,
                    :emp_id,
                    :head_id,
                    NULL,
                    :amount,
                    SYSDATE,
                    :chg_by,
                    :fy
                )
            ";
        }

        $stmt = oci_parse($sql___func___con, $sql);

        try {

            if (!$stmt) {
                $e = oci_error($sql___func___con);
                throw new Exception($e["message"]);
            }

            oci_bind_by_name($stmt, ":emp_id", $empId);
            oci_bind_by_name($stmt, ":head_id", $headId);
            oci_bind_by_name($stmt, ":amount", $amount);
            oci_bind_by_name($stmt, ":chg_by", $empCode);
            oci_bind_by_name($stmt, ":fy", $financialYear);

            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $e = oci_error($stmt);
                throw new Exception($e["message"]);
            }

            uploadDeductionDocument(
                $headId,
                $previousFile,
                $empCode,
                $empId,
                $financialYear,
                $uploadDir,
                $sql___func___con
            );

        } finally {

            if ($stmt) {
                oci_free_statement($stmt);
            }
        }
    }
    /*
    |--------------------------------------------------------------------------
    | COMMIT TRANSACTION
    |--------------------------------------------------------------------------
    */

    oci_commit($sql___func___con);
    apiResponse(true, "Deductions saved successfully.");

} catch (Throwable $e) {

    oci_rollback($sql___func___con);

    logOracleError($e);

    apiResponse(false, "Unable to save deductions.", null, 500);
}

function uploadDeductionDocument(
    $headId,
    $previousFile,
    $empCode,
    $empId,
    $financialYear,
    $uploadDir,
    $conn
) {

    /*
    |--------------------------------------------------------------------------
    | CHECK FILE EXISTS
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_FILES["DEDN_DOC"]["name"][$headId]) ||
        empty($_FILES["DEDN_DOC"]["name"][$headId])
    ) {
        return;
    }

    $originalName = $_FILES["DEDN_DOC"]["name"][$headId];
    $tmpName      = $_FILES["DEDN_DOC"]["tmp_name"][$headId];
    $error        = $_FILES["DEDN_DOC"]["error"][$headId];
    $fileSize     = $_FILES["DEDN_DOC"]["size"][$headId];

    /*
    |--------------------------------------------------------------------------
    | CHECK UPLOAD ERROR
    |--------------------------------------------------------------------------
    */

    if ($error !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed.");
    }

    /*
    |--------------------------------------------------------------------------
    | FILE SIZE VALIDATION (5 MB)
    |--------------------------------------------------------------------------
    */

    $maxSize = 5 * 1024 * 1024;

    if ($fileSize > $maxSize) {
        throw new Exception("Maximum file size allowed is 5 MB.");
    }

    /*
    |--------------------------------------------------------------------------
    | EXTENSION VALIDATION
    |--------------------------------------------------------------------------
    */

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext !== "pdf") {
        throw new Exception("Only PDF files are allowed.");
    }

    /*
    |--------------------------------------------------------------------------
    | MIME TYPE VALIDATION
    |--------------------------------------------------------------------------
    */

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        throw new Exception("Unable to validate uploaded file.");
    }
   
    if (!file_exists($tmpName)) {
        throw new Exception("Uploaded file not found.");
    }

   try {
        $mime = finfo_file($finfo, $tmpName);
    } finally {
        finfo_close($finfo);
    }

    $allowed = [
    "application/pdf",
    "application/x-pdf",
    "application/acrobat",
    "applications/vnd.pdf"
];

    if (!in_array($mime, $allowed, true)) {
        throw new Exception("Uploaded file is not a valid PDF.");
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE FILE NAME
    |--------------------------------------------------------------------------
    */

    $fileName = $empCode . "_" . $headId . ".pdf";

    $targetPath = $uploadDir . $fileName;

    /*
    |--------------------------------------------------------------------------
    | MOVE FILE
    |--------------------------------------------------------------------------
    */
    if (!is_uploaded_file($tmpName)) {
        throw new Exception("Invalid uploaded file.");
    }

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new Exception("Unable to upload attachment.");
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PREVIOUS FILE (AFTER SUCCESSFUL UPLOAD)
    |--------------------------------------------------------------------------
    */

    if (!empty($previousFile)) {

        $oldPath = $uploadDir . $previousFile;

        if (
        $previousFile !== $fileName &&
            file_exists($oldPath) &&
            !unlink($oldPath)
        ) {
            throw new Exception("Unable to delete previous attachment.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE ATTACHMENT NAME IN DATABASE
    |--------------------------------------------------------------------------
    */

    $sql = "
        UPDATE EPT_BCS_ITAX_DEDUCTIONS
        SET ATTACHMENTS = :attachment
        WHERE EMP_ID = :emp_id
        AND FY = :fy
        AND HEAD_ID = :head_id
    ";

    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {
        $e = oci_error($conn);
        throw new Exception($e["message"]);
    }

    oci_bind_by_name($stmt, ":attachment", $fileName);
    oci_bind_by_name($stmt, ":emp_id", $empId);
    oci_bind_by_name($stmt, ":fy", $financialYear);
    oci_bind_by_name($stmt, ":head_id", $headId);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {

        $e = oci_error($stmt);
        oci_free_statement($stmt);
        throw new Exception($e["message"]);
    }

    oci_free_statement($stmt);
}