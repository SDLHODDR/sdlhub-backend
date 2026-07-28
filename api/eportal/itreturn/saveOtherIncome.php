<?php

// Uncomment during development
// error_reporting(E_ALL);
// ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/path.php";

$sql___func___con = db_eportal();

header("Content-Type: application/json");

try {

    /*
    |--------------------------------------------------------------------------
    | REQUEST METHOD
    |--------------------------------------------------------------------------
    */

   /* if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method.", null, 405);
    }*/

    /*
    |--------------------------------------------------------------------------
    | SESSION VALIDATION
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access.", null, 401);
    }

    $empCode = trim($_SESSION["emp_code"]);

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE ID
    |--------------------------------------------------------------------------
    */
    $empData = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '{$empCode}'
    ");

    $empId = $empData["ID"] ?? null;

    if (!$empId) {
        apiResponse(false, "Employee not found.", null, 404);
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
        throw new Exception("Financial year not found.");
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE INPUT
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_POST["OTH_INCOME"]) ||
        !is_array($_POST["OTH_INCOME"])
    ) {
        apiResponse(false, "Other Income data is missing.");
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $publicPath = DOCUMENT_ROOT;

    if ($publicPath === false) {
        throw new Exception("Public directory not found.");
    }

    $uploadDir =
        $publicPath .
        "/assets/incometax/" .
        $financialYear .
        "/" .
        $empCode .
        "/";

    if (!is_dir($uploadDir)) {

        if (!mkdir($uploadDir, 0775, true)) {
            throw new Exception("Unable to create upload directory.");
        }
    }

    if (!is_writable($uploadDir)) {
        throw new Exception("Upload directory is not writable.");
    }

    /*
    |--------------------------------------------------------------------------
    | START PROCESSING
    |--------------------------------------------------------------------------
    */

    foreach ($_POST["OTH_INCOME"] as $headId => $amount) {

        $headId = trim($headId);
        $amount = trim($amount);

        if (!ctype_digit($headId)) {
            throw new Exception("Invalid income head.");
        }

        // Skip empty amount
        if ($amount === "") {
            continue;
        }

        if (!is_numeric($amount)) {
            throw new Exception("Invalid amount.");
        }

        $amount = (float) $amount;

        if ($amount < 0 || $amount > 999999999) {
            throw new Exception("Invalid amount.");
        }

        /*
        |--------------------------------------------------------------------------
        | CHECK EXISTING RECORD
        |--------------------------------------------------------------------------
        */

        $checkSql = "
            SELECT ID,
                   AGREEMENT_ATTACH
            FROM EPT_BCS_ITAX_OTHER_INCOME
            WHERE
                EMP_ID = :emp_id
                AND FY = :fy
                AND HEAD_ID = :head_id
        ";

        $checkStmt = oci_parse($sql___func___con, $checkSql);

        if (!$checkStmt) {
            $e = oci_error($sql___func___con);
            throw new Exception($e["message"]);
        }

        oci_bind_by_name($checkStmt, ":emp_id", $empId);
        oci_bind_by_name($checkStmt, ":fy", $financialYear);
        oci_bind_by_name($checkStmt, ":head_id", $headId);

        if (!oci_execute($checkStmt)) {
            $e = oci_error($checkStmt);
            throw new Exception($e["message"]);
        }

        $existing = oci_fetch_assoc($checkStmt);

        oci_free_statement($checkStmt);

                /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        $previousAttachment = $existing["AGREEMENT_ATTACH"] ?? "";

        if (!empty($existing)) {

            $sql = "
                UPDATE EPT_BCS_ITAX_OTHER_INCOME
                SET
                    AMOUNT = :amount,
                    CHG_ON = SYSDATE,
                    CHG_BY = :chg_by
                WHERE
                    ID = :id
                    AND EMP_ID = :emp_id
                    AND FY = :fy
            ";

            $stmt = oci_parse($sql___func___con, $sql);

            if (!$stmt) {
                $e = oci_error($sql___func___con);
                throw new Exception($e["message"]);
            }

            $recordId = $existing["ID"];

            oci_bind_by_name($stmt, ":amount", $amount);
            oci_bind_by_name($stmt, ":chg_by", $empCode);
            oci_bind_by_name($stmt, ":id", $recordId);
            oci_bind_by_name($stmt, ":emp_id", $empId);
            oci_bind_by_name($stmt, ":fy", $financialYear);

        }

        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */

        else {

            $sql = "
                INSERT INTO EPT_BCS_ITAX_OTHER_INCOME
                (
                    EMP_ID,
                    HEAD_ID,
                    AMOUNT,
                    AGREEMENT_ATTACH,
                    CHG_ON,
                    CHG_BY,
                    FY
                )
                VALUES
                (
                    :emp_id,
                    :head_id,
                    :amount,
                    NULL,
                    SYSDATE,
                    :chg_by,
                    :fy
                )
            ";

            $stmt = oci_parse($sql___func___con, $sql);

            if (!$stmt) {
                $e = oci_error($sql___func___con);
                throw new Exception($e["message"]);
            }

            oci_bind_by_name($stmt, ":emp_id", $empId);
            oci_bind_by_name($stmt, ":head_id", $headId);
            oci_bind_by_name($stmt, ":amount", $amount);
            oci_bind_by_name($stmt, ":chg_by", $empCode);
            oci_bind_by_name($stmt, ":fy", $financialYear);

        }

        /*
        |--------------------------------------------------------------------------
        | EXECUTE INSERT / UPDATE
        |--------------------------------------------------------------------------
        */

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {

            $e = oci_error($stmt);

            throw new Exception($e["message"]);
        }

        oci_free_statement($stmt);

                /*
        |--------------------------------------------------------------------------
        | FILE UPLOAD
        |--------------------------------------------------------------------------
        */

        if (
            isset($_FILES["OTH_INCOME_DOC"]["name"][$headId]) &&
            !empty($_FILES["OTH_INCOME_DOC"]["name"][$headId])
        ) {

            if ($_FILES["OTH_INCOME_DOC"]["error"][$headId] !== UPLOAD_ERR_OK) {
                throw new Exception("File upload failed for Head ID {$headId}.");
            }

            /*
            |--------------------------------------------------------------------------
            | FILE VALIDATION
            |--------------------------------------------------------------------------
            */

            $allowedExtensions = [
                "pdf",
                "jpg",
                "jpeg",
                "png"
            ];

            $originalName = basename(
                $_FILES["OTH_INCOME_DOC"]["name"][$headId]
            );

            $extension = strtolower(
                pathinfo($originalName, PATHINFO_EXTENSION)
            );

            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception(
                    "Invalid file type for Head ID {$headId}."
                );
            }

            $fileSize = $_FILES["OTH_INCOME_DOC"]["size"][$headId];

            if ($fileSize > (5 * 1024 * 1024)) {
                throw new Exception(
                    "Maximum upload size is 5 MB."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | REMOVE OLD FILE
            |--------------------------------------------------------------------------
            */

            if (
                !empty($previousAttachment) &&
                file_exists($uploadDir . $previousAttachment)
            ) {
                unlink($uploadDir . $previousAttachment);
            }

            /*
            |--------------------------------------------------------------------------
            | GENERATE FILE NAME
            |--------------------------------------------------------------------------
            */

            $random = strtoupper(substr(md5(uniqid()), 0, 6));

            $newFileName =
                $empCode .
                "_OI_" .
                $headId .
                "_" .
                $random .
                "." .
                $extension;

            $targetFile = $uploadDir . $newFileName;

            if (
                !move_uploaded_file(
                    $_FILES["OTH_INCOME_DOC"]["tmp_name"][$headId],
                    $targetFile
                )
            ) {
                throw new Exception(
                    "Unable to upload file for Head ID {$headId}."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE ATTACHMENT
            |--------------------------------------------------------------------------
            */

            $attachmentSql = "
                UPDATE EPT_BCS_ITAX_OTHER_INCOME
                SET AGREEMENT_ATTACH = :attachment
                WHERE
                    EMP_ID = :emp_id
                    AND FY = :fy
                    AND HEAD_ID = :head_id
            ";

            $attachmentStmt = oci_parse(
                $sql___func___con,
                $attachmentSql
            );

            if (!$attachmentStmt) {
                $e = oci_error($sql___func___con);
                throw new Exception($e["message"]);
            }

            oci_bind_by_name(
                $attachmentStmt,
                ":attachment",
                $newFileName
            );

            oci_bind_by_name(
                $attachmentStmt,
                ":emp_id",
                $empId
            );

            oci_bind_by_name(
                $attachmentStmt,
                ":fy",
                $financialYear
            );

            oci_bind_by_name(
                $attachmentStmt,
                ":head_id",
                $headId
            );

            if (
                !oci_execute(
                    $attachmentStmt,
                    OCI_NO_AUTO_COMMIT
                )
            ) {
                $e = oci_error($attachmentStmt);
                throw new Exception($e["message"]);
            }

            oci_free_statement($attachmentStmt);
        }

    } // End foreach()

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    oci_commit($sql___func___con);

    apiResponse(
        true,
        "Other Income saved successfully."
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    oci_rollback($sql___func___con);

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    logOracleError($e);

    apiResponse(false, "Unable to save Other Income.", null, 500 );

} finally {

    /*
    |--------------------------------------------------------------------------
    | FREE OCI STATEMENTS
    |--------------------------------------------------------------------------
    */

    if (isset($checkStmt) && $checkStmt) {
        oci_free_statement($checkStmt);
    }

    if (isset($stmt) && $stmt) {
        oci_free_statement($stmt);
    }

    if (isset($attachmentStmt) && $attachmentStmt) {
        oci_free_statement($attachmentStmt);
    }
}