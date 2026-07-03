<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/path.php";
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

require_once __DIR__ . "/../helpers/downloadHelper.php";

$con = db_eportal();

/*
=====================================================
JOB ID
=====================================================
*/

$jobId = (int)($argv[1] ?? 0);

if ($jobId <= 0) {
    exit("Invalid Job ID\n");
}

/*
=====================================================
FETCH JOB
=====================================================
*/

$sql = "
SELECT
    ID,
    FINANCIAL_YEAR,
    REQUESTED_BY
FROM EPT_ITR_DOWNLOAD_JOBS
WHERE ID = :job_id
";

$stmt = oci_parse($con, $sql);

oci_bind_by_name($stmt, ":job_id", $jobId);

oci_execute($stmt);

$job = oci_fetch_assoc($stmt);

if (!$job) {
    exit("Job not found\n");
}

$financialYear = $job["FINANCIAL_YEAR"];
$requestedBy   = $job["REQUESTED_BY"];

try {

    /*
    =====================================================
    CREATE ZIP
    =====================================================
    */

    $zipData = createZipFile(
        "documents",
        "all",
        $financialYear
    );

    $zip = $zipData["zip"];

    processDocumentDownload(
        $zip,
        $financialYear,
        "all"
    );

    $zip->close();

    /*
    =====================================================
    PUBLISH ZIP
    =====================================================
    */

    $published = publishZip(
        $zipData["file_path"],
        $zipData["file_name"]
    );

    /*
    =====================================================
    UPDATE JOB
    =====================================================
    */

    $sql = "
    UPDATE EPT_ITR_DOWNLOAD_JOBS
    SET
        STATUS='COMPLETED',
        FILE_NAME=:file_name,
        DOWNLOAD_URL=:download_url,
        COMPLETED_AT=SYSDATE
    WHERE ID=:job_id
    ";

    $stmt = oci_parse($con, $sql);

    oci_bind_by_name($stmt, ":file_name", $zipData["file_name"]);
    oci_bind_by_name($stmt, ":download_url", $published["download_url"]);
    oci_bind_by_name($stmt, ":job_id", $jobId);

    oci_execute($stmt);

    oci_commit($con);

    /*
    =====================================================
    LOG DOWNLOAD
    =====================================================
    */

    logDownload(
        $con,
        $requestedBy,
        "documents",
        "all",
        "",
        $financialYear,
        $zipData["file_name"],
        $published["file_path"]
    );

    /*
    =====================================================
    EMAIL USER
    =====================================================
    

    sendDownloadMail(
        $requestedBy,
        $published["download_url"],
        $zipData["file_name"]
    );*/

} catch (Throwable $e) {

    $sql = "
    UPDATE EPT_ITR_DOWNLOAD_JOBS
    SET
        STATUS='FAILED',
        ERROR_MESSAGE=:msg,
        COMPLETED_AT=SYSDATE
    WHERE ID=:job_id
    ";

    $stmt = oci_parse($con, $sql);

    $msg = substr($e->getMessage(), 0, 4000);

    oci_bind_by_name($stmt, ":msg", $msg);
    oci_bind_by_name($stmt, ":job_id", $jobId);

    oci_execute($stmt);

    oci_commit($con);

    error_log($e->getMessage());
}
