<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

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
    echo json_encode([
        "status" => false,
        "message" => "Invalid request method"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["emp_code"])) {
    apiResponse(false, "Unauthorized Access", null, 401);
    exit;
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
    echo json_encode([
        "status" => false,
        "message" => "Employee not found"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| CURRENT FINANCIAL YEAR
|--------------------------------------------------------------------------
*/

$acctPeriod = singRec("
    SELECT *
    FROM EPT_BCS_ACCT_PERIOD
    WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
");

$financialYear = $acctPeriod["CODE"] ?? "";

if (empty($financialYear)) {
    echo json_encode([
        "status" => false,
        "message" => "Financial year not found"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_POST["DEDN"]) ||
    !is_array($_POST["DEDN"]) ||
    empty($_POST["DEDN"])
) {
    echo json_encode([
        "status" => false,
        "message" => "No deduction data received"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| FILE UPLOAD DIRECTORY
|--------------------------------------------------------------------------
*/
$uploadDir = realpath(__DIR__ . "/../../../../public") .
    "/assets/img/incometax/" .
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
    if (!mkdir($uploadDir, 0777, true)) {
        echo json_encode([
            "status" => false,
            "message" => "Failed to create upload directory"
        ]);
        exit;
    }
}

if (!is_writable($uploadDir)) {
    echo json_encode([
        "status" => false,
        "message" => "Upload directory is not writable"
    ]);
    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | LOOP THROUGH DEDUCTIONS
    |--------------------------------------------------------------------------
    */

    foreach ($_POST["DEDN"] as $headId => $amount) {

        $headId = trim($headId);
        $amount = trim($amount);

        if ($headId === "") {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | CHECK EXISTING RECORD
        |--------------------------------------------------------------------------
        */

        $existingRecord = singRec("
            SELECT ATTACHMENTS
            FROM EPT_BCS_ITAX_DEDUCTIONS
            WHERE EMP_ID = '".$empId."'
            AND FY = '".$financialYear."'
            AND HEAD_ID = '".$headId."'
        ");

        $recordExists = !empty($existingRecord);

        $previousFile = $existingRecord["ATTACHMENTS"] ?? "";

        /*
        |--------------------------------------------------------------------------
        | INSERT OR UPDATE RECORD
        |--------------------------------------------------------------------------
        */

        if ($recordExists) {

            $updateSql = "
                UPDATE EPT_BCS_ITAX_DEDUCTIONS
                SET
                    AMOUNT = :amount,
                    CHG_ON = SYSDATE,
                    CHG_BY = :chg_by
                WHERE EMP_ID = :emp_id
                AND FY = :fy
                AND HEAD_ID = :head_id
            ";

            $stmt = oci_parse($sql___func___con, $updateSql);

            oci_bind_by_name($stmt, ":amount", $amount);
            oci_bind_by_name($stmt, ":chg_by", $empId);
            oci_bind_by_name($stmt, ":emp_id", $empId);
            oci_bind_by_name($stmt, ":fy", $financialYear);
            oci_bind_by_name($stmt, ":head_id", $headId);

        } else {

            $insertSql = "
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

            $stmt = oci_parse($sql___func___con, $insertSql);

            oci_bind_by_name($stmt, ":emp_id", $empId);
            oci_bind_by_name($stmt, ":head_id", $headId);
            oci_bind_by_name($stmt, ":amount", $amount);
            oci_bind_by_name($stmt, ":chg_by", $empId);
            oci_bind_by_name($stmt, ":fy", $financialYear);
        }

        $result = oci_execute($stmt, OCI_NO_AUTO_COMMIT);

        if (!$result) {

            $e = oci_error($stmt);

            oci_rollback($sql___func___con);

            echo json_encode([
                "status" => false,
                "message" => $e["message"]
            ]);
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | FILE UPLOAD
        |--------------------------------------------------------------------------
        */

        if (
            isset($_FILES["DEDN_DOC"]["name"][$headId]) &&
            !empty($_FILES["DEDN_DOC"]["name"][$headId])
        ) {

            $originalName = $_FILES["DEDN_DOC"]["name"][$headId];
            $tmpName = $_FILES["DEDN_DOC"]["tmp_name"][$headId];
            $error = $_FILES["DEDN_DOC"]["error"][$headId];

            if ($error === UPLOAD_ERR_OK) {

                /*
                |--------------------------------------------------------------------------
                | VALIDATE FILE TYPE
                |--------------------------------------------------------------------------
                */

                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                $allowed = ["pdf"];

                if (!in_array($ext, $allowed)) {

                    oci_rollback($sql___func___con);

                    echo json_encode([
                        "status" => false,
                        "message" => "Only PDF files are allowed"
                    ]);
                    exit;
                }

                /*
                |--------------------------------------------------------------------------
                | DELETE OLD FILE IF EXISTS
                |--------------------------------------------------------------------------
                */

                if (!empty($previousFile)) {

                    $oldPath = $uploadDir . $previousFile;

                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE NEW FILE NAME
                |--------------------------------------------------------------------------
                */

                $random = substr(
                    str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"),
                    0,
                    4
                );

                //$fileName = $empCode . "_" . $headId . "_" . $random . "." . $ext;
                $fileName = $empCode . "_" . $headId . $ext;

                $targetPath = $uploadDir . $fileName;

                /*
                |--------------------------------------------------------------------------
                | MOVE FILE
                |--------------------------------------------------------------------------
                */

                if (move_uploaded_file($tmpName, $targetPath)) {

                    $updateAttachmentSql = "
                        UPDATE EPT_BCS_ITAX_DEDUCTIONS
                        SET ATTACHMENTS = :attachments
                        WHERE EMP_ID = :emp_id
                        AND FY = :fy
                        AND HEAD_ID = :head_id
                    ";

                    $attachStmt = oci_parse($sql___func___con, $updateAttachmentSql);

                    oci_bind_by_name($attachStmt, ":attachments", $fileName);
                    oci_bind_by_name($attachStmt, ":emp_id", $empId);
                    oci_bind_by_name($attachStmt, ":fy", $financialYear);
                    oci_bind_by_name($attachStmt, ":head_id", $headId);

                    $attachResult = oci_execute($attachStmt, OCI_NO_AUTO_COMMIT);

                    if (!$attachResult) {

                        $e = oci_error($attachStmt);

                        oci_rollback($sql___func___con);

                        echo json_encode([
                            "status" => false,
                            "message" => $e["message"]
                        ]);
                        exit;
                    }

                } else {

                    oci_rollback($sql___func___con);

                    echo json_encode([
                        "status" => false,
                        "message" => "Failed to upload file for Head ID: ".$headId
                    ]);
                    exit;
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT TRANSACTION
    |--------------------------------------------------------------------------
    */

    oci_commit($sql___func___con);

    echo json_encode([
        "status" => true,
        "message" => "Deductions saved successfully"
    ]);

} catch (Exception $e) {

    oci_rollback($sql___func___con);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}