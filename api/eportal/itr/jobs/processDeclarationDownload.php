<?php

require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../helpers/generateDeclarationPdf.php";

$con = db_eportal();

$jobId = $argv[1] ?? null;
$financialYear = $argv[2] ?? null;

if (empty($jobId) || empty($financialYear)) {
    die("Job ID or Financial Year missing");
}

$status = "PROCESSING";
$filePath = null;

try {

    /*
    ====================================================
    MARK JOB AS PROCESSING
    ====================================================
    */

    $updateSql = "
    UPDATE EPT_ITR_DOWNLOAD_JOBS
    SET STATUS = :status
    WHERE ID = :job_id
    ";

    $stmt = oci_parse($con, $updateSql);

    oci_bind_by_name($stmt, ":status", $status);
    oci_bind_by_name($stmt, ":job_id", $jobId);

    oci_execute($stmt);
    oci_commit($con);

    /*
    ====================================================
    CREATE JOB FOLDER
    ====================================================
    */

    $baseDir =  __DIR__ . "/../../../../../public/uploads/itr_downloads";
    $jobFolder =  $baseDir ."/job_" . $jobId;

    if (!is_dir($jobFolder)) {
        mkdir($jobFolder, 0777, true);
    }

    $outputFilePath =  "uploads/itr_downloads/job_" . $jobId;

    /*
    ====================================================
    GET EMPLOYEES
    ====================================================
    */

    $employeeSql = "
    SELECT EMP_CODE
    FROM EPT_BCS_EMPLOYEE
    WHERE STATUS = 'A'
      AND EMP_CODE IN ('00575', '05362')
    ";

    $employeeStmt = oci_parse($con, $employeeSql);

    if (!oci_execute($employeeStmt)) {

        $e = oci_error($employeeStmt);

        throw new Exception(
            "Employee Query Failed : " .
            $e['message']
        );
    }

    /*
    ====================================================
    CREATE ZIP FILE
    ====================================================
    */

    $zipFile =
        $jobFolder .
        "/ITR_Declaration_" .
        $financialYear .
        ".zip";

    $zip = new ZipArchive();

    if (
        $zip->open( $zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== TRUE) {
        throw new Exception(
            "Unable to create ZIP file"
        );
    }

    /*
    ====================================================
    GENERATE PDFS
    ====================================================
    */

    while ($row = oci_fetch_assoc($employeeStmt)) {

        $empCode = trim($row["EMP_CODE"]);

        try {

            $pdfFile =
                $jobFolder .
                "/Investment_Declaration_" .
                $empCode .
                ".pdf";

            generateDeclarationPdf($empCode, $financialYear, $pdfFile);

            if (file_exists($pdfFile)) {
                $zip->addFile($pdfFile, $empCode ."/Investment_Declaration.pdf");
            }

        } catch (Exception $e) {

            error_log(
                "PDF Generation Failed : " .
                $empCode .
                " => " .
                $e->getMessage()
            );
        }
    }

    $zip->close();

    if (!file_exists($zipFile)) {
        throw new Exception(
            "ZIP file not created"
        );
    }

    /*
    ====================================================
    SAVE FILE PATH
    ====================================================
    */

    $filePath =
        $outputFilePath .
        "/ITR_Declaration_" .
        $financialYear .
        ".zip";

    $updateSql = "
    UPDATE EPT_ITR_DOWNLOAD_JOBS
    SET FILE_PATH = :file_path
    WHERE ID = :job_id
    ";

    $stmt = oci_parse(
        $con,
        $updateSql
    );

    oci_bind_by_name($stmt, ":file_path", $filePath);
    oci_bind_by_name($stmt, ":job_id", $jobId);

    oci_execute($stmt);
    oci_commit($con);

    /*
    ====================================================
    GET REQUESTOR EMAIL
    ====================================================
    */

    $requestorSql = "
    SELECT SYS_VAL
    FROM EPT_SYS_PARAM
    WHERE SYS_LBL = 'ITR_EMAIL'
    ";

    $stmt = oci_parse($con, $requestorSql);
    oci_execute($stmt);

    $row = oci_fetch_assoc($stmt);

    if (!$row || empty($row["SYS_VAL"]) ) {
        throw new Exception(
            "ITR_EMAIL not configured"
        );
    }

    $toEmail = trim($row["SYS_VAL"]);

    /*
    ====================================================
    CREATE DOWNLOAD URL
    ====================================================
    */

    $APP_URL = "http://localhost/sdlhub_new/public";

    $downloadUrl =
        rtrim($APP_URL, "/") .
        "/" .
        $filePath;

    /*
    ====================================================
    EMAIL BODY
    ====================================================
    */

    $subject = "ITR Declaration Download Ready";

    $emailBody = "
    Dear User,<br><br>

    Your ITR Declaration ZIP file has been generated successfully.<br><br>

    Download Link:<br>
    <a href='{$downloadUrl}'>{$downloadUrl}</a>

    <br><br>

    Regards,<br>
    IT Team
    ";

    /*
    ====================================================
    SEND EMAIL
    ====================================================

    Replace with your existing mail function

    sendMail(
        $toEmail,
        $subject,
        $emailBody
    );

    ====================================================
    */
    error_log("Download URL Generated : " .$downloadUrl);
    $status = "COMPLETED";

} catch (Exception $e) {
    error_log("ITR Download Job Failed : " .$e->getMessage());
    $status = "FAILED";
}

/*
====================================================
FINAL UPDATE
====================================================
*/

$updateSql = "
UPDATE EPT_ITR_DOWNLOAD_JOBS
SET FILE_PATH = :file_path,
    STATUS = :status,
    COMPLETED_AT = SYSDATE,
    DOWNLOAD_URL = :download_url
WHERE ID = :job_id
";

$stmt = oci_parse( $con, $updateSql);

oci_bind_by_name($stmt, ":download_url", $downloadUrl);
oci_bind_by_name($stmt, ":file_path", $filePath);
oci_bind_by_name($stmt, ":status", $status);
oci_bind_by_name($stmt, ":job_id", $jobId);

oci_execute($stmt);
oci_commit($con);

echo "Job Completed : {$status}" . PHP_EOL;

?>