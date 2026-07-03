<?php

/*
=====================================================
VALIDATE DOWNLOAD REQUEST
=====================================================
*/
function validateDownloadRequest(
    string $category,
    string $type,
    string $financialYear,
    string $empCode = ""
): void {

    $allowedCategories = [
        "documents",
        "declaration"
    ];

    $allowedTypes = [
        "single",
        "all"
    ];

    if (!in_array($category, $allowedCategories, true)) {
    responseError("Invalid download category");
	}

	if (!in_array($type, $allowedTypes, true)) {
		responseError("Invalid download type");
	}

	if (empty($financialYear)) {
		responseError("Financial year is required");
	}

	if ($type === "single" && empty(trim($empCode))) {
		responseError("Employee code is required");
	}
}

/*
=====================================================
GENERATE ZIP FILE NAME
=====================================================
*/

function generateZipFileName(
    string $category,
    string $type,
    string $financialYear,
    string $empCode=""
): string
{
    $prefix = $category === "declaration"
        ? "itr_declaration"
        : "itr_documents";

    $target = $type === "single"
        ? $empCode
        : "ALL";

    return sprintf(
        "%s_%s_%s_%s_%d.zip",
        $prefix,
        $financialYear,
        $target,
        date("Ymd_His"),
        random_int(1000,9999)
    );
}

/*
=====================================================
CREATE ZIP FILE
=====================================================
*/
function createZipFile(
    string $category,
    string $type,
    string $financialYear,
    string $empCode = ""
): array {

    // Create temp directory if required
    ensureDirectoryExists(TEMP_ZIP_PATH);
    
    $zipFileName = generateZipFileName($category, $type, $financialYear, $empCode);
   
    $zipFilePath = rtrim(TEMP_ZIP_PATH, "/") . "/" . $zipFileName;

    // Create ZIP
    $zip = new ZipArchive();

    if (
        $zip->open(
            $zipFilePath,
            ZipArchive::CREATE | ZipArchive::OVERWRITE
        ) !== TRUE
    ) {
        responseError("Unable to create ZIP file.");
    }

    return [
        "zip"       => $zip,
        "file_name" => $zipFileName,
        "file_path" => $zipFilePath
    ];
}

/*
=====================================================
ADD FILE TO ZIP
=====================================================
*/
function addFileToZip(
    ZipArchive $zip,
    SplFileInfo $file,
    string $relativePath
): void {

    $path = $file->getRealPath();

	if ($path === false) {
		return;
	}

	$zip->addFile($path, $relativePath);
}

/**
 * Returns all files inside a directory recursively.
 */
function getDirectoryFiles(
    string $folder
): RecursiveIteratorIterator
{
    return new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $folder,
            RecursiveDirectoryIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
}

/*
=====================================================
PROCESS DOCUMENT DOWNLOAD
=====================================================
*/
function processDocumentDownload(
    ZipArchive $zip,
    string $financialYear,
    string $type,
    string $empCode = ""
): void {
	
    /*
    =====================================================
    SINGLE EMPLOYEE
    =====================================================
    */
    if ($type === "single") {

        $empFolder = sprintf(
			"%s/%s/%s",
			INCOME_TAX_PATH,
			$financialYear,
			$empCode
		);
       
        if (!is_dir($empFolder)) {           
            responseError("Employee folder not found.");
        }
              
       foreach(getDirectoryFiles($empFolder) as $file){

            if ($file->isDir()) {
                continue;
            }
          
			$relativePath = substr(
				$file->getRealPath(),
				strlen($empFolder) + 1
			);

			addFileToZip(
				$zip,
				$file,
				$relativePath
			);
        }

        return;
    }

    /*
    =====================================================
    ALL EMPLOYEES
    =====================================================
    */

    $fyFolder = sprintf(
		"%s/%s",
		INCOME_TAX_PATH,
		$financialYear
	);

    if (!is_dir($fyFolder)) {
        responseError("Financial year folder not found.");
    }

    foreach(getDirectoryFiles($fyFolder) as $file){

        if ($file->isDir()) {
            continue;
        }

        $relativePath = substr(
			$file->getRealPath(),
			strlen($fyFolder) + 1
		);

		addFileToZip(
			$zip,
			$file,
			$relativePath
		);
    }
}


function createDeclarationDownloadJob(
$con,
string $financialYear,
string $requestedBy
): int {
    $sql = "
        INSERT INTO EPT_ITR_DOWNLOAD_JOBS
        (
            JOB_TYPE,
            FINANCIAL_YEAR,
            REQUESTED_BY,
            STATUS,
            CREATED_AT
        )
        VALUES
        (
            'DECLARATION',
            :financial_year,
            :requested_by,
            'PENDING',
            SYSDATE
        )
        RETURNING ID INTO :job_id
    ";

    $stmt = oci_parse($con, $sql);

    $jobId = 0;

    oci_bind_by_name($stmt, ":financial_year", $financialYear);
    oci_bind_by_name($stmt, ":requested_by", $requestedBy);
    oci_bind_by_name($stmt, ":job_id", $jobId, 32);

    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        responseError("Failed to create download job : " . $e["message"]);
    }

    oci_commit($con);
    
    return $jobId;
    
}


/*
=====================================================
PROCESS DECLARATION DOWNLOAD
=====================================================
*/
function processDeclarationDownload(
    $con,
    ZipArchive $zip,
    string $financialYear,
    string $type,
    string $empCode,
    string $requestedBy
): void {

    /*
    =====================================================
    SINGLE EMPLOYEE
    =====================================================
    */
    if ($type === "single") {

        ensureDirectoryExists(TEMP_DECLARATION_PATH);

        $pdfFile =
            TEMP_DECLARATION_PATH .
            "/Investment_Declaration_" .
            $empCode .
            ".pdf";

        generateDeclarationPdf(
            $empCode,
            $financialYear,
            $pdfFile
        );

        if (!file_exists($pdfFile)) {
            responseError("Declaration PDF generation failed.");
        }

       addFileToZip(
			$zip,
			new SplFileInfo($pdfFile),
			$empCode . "/Investment_Declaration.pdf"
		);
		try {
			$zip->close();
		} finally {
			if (file_exists($pdfFile)) {
				unlink($pdfFile);
			}
		}

        return;
    }
       
    $jobId = createDeclarationDownloadJob(
		$con,
		$financialYear,
		$requestedBy
	);
    
    /*
    =====================================================
    START BACKGROUND WORKER
    =====================================================
    */
    
    startDeclarationWorker(
		$jobId,
		$financialYear
	);    
	
    responseSuccess("Your request has been submitted successfully. A download link will be emailed once processing is completed.");
}

function startDeclarationWorker(
    int $jobId,
    string $financialYear
): void
{
	
	$workerFile = __DIR__ . "/../jobs/processDeclarationDownload.php";

    if (!file_exists($workerFile)) {
         responseError("Worker file not found.");
    }

    ensureDirectoryExists(ITR_JOB_LOG_PATH);

    $workerLog = ITR_JOB_LOG_PATH . "/worker.log";

    $command =
        "php "
        . escapeshellarg($workerFile)
        . " "
        . escapeshellarg($jobId)
        . " "
        . escapeshellarg($financialYear)
        . " >> "
        . escapeshellarg($workerLog)
        . " 2>&1 &";

    exec($command, $output, $status);

	if ($status !== 0) {
		responseError("Unable to start background worker.");
	}
}

/*
=====================================================
PUBLISH ZIP TO PUBLIC DIRECTORY
=====================================================
*/
function publishZip(string $zipFilePath, string $zipFileName): array
{
    $publicZipDir = PUBLIC_PATH . "/temp_zip";

    ensureDirectoryExists($publicZipDir);

    $publicZipPath = $publicZipDir . "/" . $zipFileName;

    if (!file_exists($zipFilePath)) {
        responseError("Generated ZIP file not found.");
    }

    if (!copy($zipFilePath, $publicZipPath)) {
        responseError("Failed to publish ZIP file.");
    }

    /*
    -----------------------------------------------------
    DELETE WORKING ZIP
    -----------------------------------------------------
    */

    @unlink($zipFilePath);

    return [
        "file_path"    => $publicZipPath,
        "download_url" => rtrim(PUBLIC_URL, "/") . "/temp_zip/" . rawurlencode($zipFileName)
    ];
}

function logDownload(
    $con,
    string $loggedEmpCode,
    string $category,
    string $type,
    string $empCode,
    string $financialYear,
    string $zipFileName,
    string $publicZipPath
): void
{
		
	$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
	$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

	$browserName = getBrowserName($userAgent);
	//$fileSizeMb = round(filesize($publicZipPath) / 1024 / 1024, 2);
	
	$fileSizeMb = file_exists($publicZipPath)
    ? round(filesize($publicZipPath) / 1024 / 1024, 2)
    : 0;

	$targetEmp = $type === "single" ? $empCode : "ALL";
        
	$logSql = "
	INSERT INTO EPT_ITR_DOWNLOAD_LOG
	(
		LOG_ID,
		EMP_CODE,
		DOWNLOAD_CATEGORY,
		DOWNLOAD_TYPE,
		TARGET_EMP_CODE,
		FINANCIAL_YEAR,
		FILE_NAME,
		FILE_SIZE_MB,
		IP_ADDRESS,
		USER_AGENT,
		BROWSER_NAME,
		DOWNLOAD_TIME,
		STATUS
	)
	VALUES
	(
		EPT_ITR_DOWNLOAD_LOG_SEQ.NEXTVAL,
		:emp_code,
		:download_category,
		:download_type,
		:target_emp_code,
		:financial_year,
		:file_name,
		:file_size,
		:ip_address,
		:user_agent,
		:browser_name,
		SYSDATE,
		'SUCCESS'
	)
	";

	$logStmt = oci_parse($con, $logSql);

	if (!$logStmt) {
		$e = oci_error($con);
		error_log("ITR Download Log Parse Error: " . $e['message']);
		return;
	}

	oci_bind_by_name($logStmt, ":emp_code", $loggedEmpCode);
	oci_bind_by_name($logStmt, ":download_type", $type);
	oci_bind_by_name($logStmt, ":download_category", $category);
	oci_bind_by_name($logStmt, ":target_emp_code", $targetEmp);
	oci_bind_by_name($logStmt, ":financial_year", $financialYear);
	oci_bind_by_name($logStmt, ":file_name", $zipFileName);
	oci_bind_by_name($logStmt, ":file_size", $fileSizeMb);
	oci_bind_by_name($logStmt, ":ip_address", $ipAddress);
	oci_bind_by_name($logStmt, ":user_agent", $userAgent);
	oci_bind_by_name($logStmt, ":browser_name", $browserName);

	if (!oci_execute($logStmt)) {
		$e = oci_error($logStmt);
		error_log("ITR Download Log Error: " . $e['message']);
	} else {
		oci_commit($con);
	}

	oci_free_statement($logStmt);
}
?>
