<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/functions.php";

header("Content-Type: application/json");

$stmt = null;
$uploadStmt = null;

try {

    /*
    |--------------------------------------------------------------------------
    | METHOD CHECK
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method", null, 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION CHECK
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    $empCode = $_SESSION["emp_code"];

    /*
    |--------------------------------------------------------------------------
    | GET EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '{$empCode}'
    ");

    $empId = $employee["ID"] ?? null;

    if (!$empId) {
        apiResponse(false, "Employee not found", null, 404);
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
        apiResponse(false, "Financial Year not found.");
    }

    /*
    |--------------------------------------------------------------------------
    | FORM DATA
    |--------------------------------------------------------------------------
    */

    $id = trim($_POST["exemption_id"] ?? "");

    if ($id !== "" && !ctype_digit($id)) {
        apiResponse(false, "Invalid exemption id.");
    }

    $from = trim($_POST["from"] ?? "");
    $to = trim($_POST["to"] ?? "");

    $monthlyRent = trim($_POST["monthlyRent"] ?? "");
    $annualRent = trim($_POST["annualRent"] ?? "");

    $address = trim($_POST["address"] ?? "");
    $city = trim($_POST["city"] ?? "");

    $landlordHasPan = strtolower(trim($_POST["landlordHasPan"] ?? "yes"));

    $landlordName = trim($_POST["landlordName"] ?? "");
    $landlordAddress = trim($_POST["landlordAddress"] ?? "");
    $landlordPan = strtoupper(trim($_POST["landlordPan"] ?? ""));

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        empty($from) ||
        empty($to) ||
        $monthlyRent === "" ||
        $annualRent === "" ||
        empty($address) ||
        empty($city) ||
        empty($landlordName)
    ) {
        apiResponse(false, "Please fill all required fields.");
    }

    if (!is_numeric($monthlyRent) || $monthlyRent < 0) {
        apiResponse(false, "Invalid Monthly Rent.");
    }

    if (!is_numeric($annualRent) || $annualRent < 0) {
        apiResponse(false, "Invalid Annual Rent.");
    }

    if (strtotime($from) > strtotime($to)) {
        apiResponse(false, "From month cannot be greater than To month.");
    }

    $isPanMandatory =
        ($landlordHasPan === "yes") ||
        ((float)$annualRent > 100000);

    if ($isPanMandatory) {

        if (empty($landlordPan)) {
            apiResponse(false, "Landlord PAN is required.");
        }

        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $landlordPan)) {
            apiResponse(false, "Invalid PAN number.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | START TRANSACTION
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    if (!empty($id)) {

        $sql = "
            UPDATE EPT_BCS_ITAX_EXEMPTION
            SET
                FROM_MONTH = :from_month,
                TO_MONTH = :to_month,
                MONTHLY_RENT = :monthly_rent,
                ANNUAL_RENT = :annual_rent,
                ADDRESS = :address,
                LANDLORD_NAME = :landlord_name,
                LANDLORD_ADDRESS = :landlord_address,
                LANDLORD_PAN = :landlord_pan,
                CITY = :city,
                CHG_ON = SYSDATE,
                CHG_BY = :chg_by,
                FY = :fy
            WHERE ID = :id
        ";

        $stmt = oci_parse($sql___func___con, $sql);

        if (!$stmt) {
            throw new Exception(oci_error($sql___func___con)["message"]);
        }

        oci_bind_by_name($stmt, ":id", $id);

        $message = "Exemption updated successfully";
        $exemptionId = $id;

    } else {

        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */

        $sql = "
            INSERT INTO EPT_BCS_ITAX_EXEMPTION
            (
                EMP_ID,
                FROM_MONTH,
                TO_MONTH,
                MONTHLY_RENT,
                ANNUAL_RENT,
                ADDRESS,
                LANDLORD_NAME,
                LANDLORD_ADDRESS,
                LANDLORD_PAN,
                CITY,
                CHG_ON,
                CHG_BY,
                FY
            )
            VALUES
            (
                :emp_id,
                :from_month,
                :to_month,
                :monthly_rent,
                :annual_rent,
                :address,
                :landlord_name,
                :landlord_address,
                :landlord_pan,
                :city,
                SYSDATE,
                :chg_by,
                :fy
            )
            RETURNING ID INTO :new_id
        ";

        $stmt = oci_parse($sql___func___con, $sql);

        if (!$stmt) {
            throw new Exception(oci_error($sql___func___con)["message"]);
        }

        $newId = null;

        oci_bind_by_name($stmt, ":new_id", $newId, 32);

        $message = "Exemption saved successfully";
    }
        /*
    |--------------------------------------------------------------------------
    | COMMON BINDINGS
    |--------------------------------------------------------------------------
    */

    oci_bind_by_name($stmt, ":emp_id", $empId);
    oci_bind_by_name($stmt, ":from_month", $from);
    oci_bind_by_name($stmt, ":to_month", $to);
    oci_bind_by_name($stmt, ":monthly_rent", $monthlyRent);
    oci_bind_by_name($stmt, ":annual_rent", $annualRent);
    oci_bind_by_name($stmt, ":address", $address);
    oci_bind_by_name($stmt, ":landlord_name", $landlordName);
    oci_bind_by_name($stmt, ":landlord_address", $landlordAddress);
    oci_bind_by_name($stmt, ":landlord_pan", $landlordPan);
    oci_bind_by_name($stmt, ":city", $city);
    oci_bind_by_name($stmt, ":chg_by", $empId);
    oci_bind_by_name($stmt, ":fy", $financialYear);

    /*
    |--------------------------------------------------------------------------
    | EXECUTE MAIN QUERY
    |--------------------------------------------------------------------------
    */

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {

        $e = oci_error($stmt);

        throw new Exception($e["message"]);
    }

    /*
    |--------------------------------------------------------------------------
    | INSERTED RECORD ID
    |--------------------------------------------------------------------------
    */

    if (empty($id)) {
        $exemptionId = $newId;
    }

    /*
    |--------------------------------------------------------------------------
    | PUBLIC DIRECTORY
    |--------------------------------------------------------------------------
    */

    $publicPath = realpath(__DIR__ . "/../../../../public");

    if (!$publicPath) {
        throw new Exception("Public directory not found.");
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $uploadDir =
        $publicPath .
        "/assets/img/incometax/" .
        $financialYear .
        "/" .
        $empCode .
        "/";

    if (!is_dir($uploadDir)) {

        if (!mkdir($uploadDir, 0777, true)) {

            throw new Exception(
                "Unable to create upload directory."
            );
        }
    }

    if (!is_writable($uploadDir)) {

        throw new Exception(
            "Upload directory is not writable."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | OLD FILES
    |--------------------------------------------------------------------------
    */

    $oldFiles = singRec("
        SELECT
            LANDLORD_PAN_ATTACH,
            AGREEMENT_ATTACH
        FROM EPT_BCS_ITAX_EXEMPTION
        WHERE ID = '{$exemptionId}'
    ");

    $oldPanFile = $oldFiles["LANDLORD_PAN_ATTACH"] ?? "";
    $oldAgreementFile = $oldFiles["AGREEMENT_ATTACH"] ?? "";
        /*
    |--------------------------------------------------------------------------
    | LANDLORD PAN COPY
    |--------------------------------------------------------------------------
    */

    if (
        isset($_FILES["panCopy"]) &&
        $_FILES["panCopy"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES["panCopy"]["error"] !== UPLOAD_ERR_OK) {
            throw new Exception("PAN copy upload failed.");
        }

        $maxSize = 5 * 1024 * 1024;

        if ($_FILES["panCopy"]["size"] > $maxSize) {
            throw new Exception("PAN copy must not exceed 5 MB.");
        }

        $tmpName = $_FILES["panCopy"]["tmp_name"];

        if (!is_uploaded_file($tmpName)) {
            throw new Exception("Invalid uploaded PAN copy.");
        }

        $extension = strtolower(
            pathinfo(
                $_FILES["panCopy"]["name"],
                PATHINFO_EXTENSION
            )
        );

        $allowedExtensions = [
            "jpg",
            "jpeg",
            "png",
            "pdf"
        ];

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new Exception("Invalid PAN copy file type.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        $mimeType = finfo_file($finfo, $tmpName);

        finfo_close($finfo);

        $allowedMimeTypes = [
            "application/pdf",
            "application/x-pdf",
            "image/jpeg",
            "image/png"
        ];

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new Exception("Invalid PAN copy.");
        }

        $random = strtoupper(
            substr(md5(uniqid(mt_rand(), true)), 0, 6)
        );

        $panFileName =
            $empCode .
            "_LPAN_" .
            $random .
            "." .
            $extension;

        $targetFile = $uploadDir . $panFileName;

        if (!move_uploaded_file($tmpName, $targetFile)) {
            throw new Exception("Unable to upload PAN copy.");
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE OLD PAN FILE
        |--------------------------------------------------------------------------
        */

        if (!empty($oldPanFile)) {

            $oldPath = $uploadDir . $oldPanFile;

            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        $uploadSql = "
            UPDATE EPT_BCS_ITAX_EXEMPTION
            SET LANDLORD_PAN_ATTACH = :attachment
            WHERE ID = :id
        ";

        $uploadStmt = oci_parse(
            $sql___func___con,
            $uploadSql
        );

        if (!$uploadStmt) {
            throw new Exception(
                oci_error($sql___func___con)["message"]
            );
        }

        oci_bind_by_name(
            $uploadStmt,
            ":attachment",
            $panFileName
        );

        oci_bind_by_name(
            $uploadStmt,
            ":id",
            $exemptionId
        );

        if (!oci_execute($uploadStmt, OCI_NO_AUTO_COMMIT)) {

            $e = oci_error($uploadStmt);

            throw new Exception($e["message"]);
        }
    }
        /*
    |--------------------------------------------------------------------------
    | AGREEMENT COPY
    |--------------------------------------------------------------------------
    */

    if (
        isset($_FILES["agreementCopy"]) &&
        $_FILES["agreementCopy"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES["agreementCopy"]["error"] !== UPLOAD_ERR_OK) {
            throw new Exception("Agreement copy upload failed.");
        }

        $maxSize = 5 * 1024 * 1024;

        if ($_FILES["agreementCopy"]["size"] > $maxSize) {
            throw new Exception("Agreement copy must not exceed 5 MB.");
        }

        $tmpName = $_FILES["agreementCopy"]["tmp_name"];

        if (!is_uploaded_file($tmpName)) {
            throw new Exception("Invalid uploaded agreement copy.");
        }

        $extension = strtolower(
            pathinfo(
                $_FILES["agreementCopy"]["name"],
                PATHINFO_EXTENSION
            )
        );

        $allowedExtensions = [
            "jpg",
            "jpeg",
            "png",
            "pdf"
        ];

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new Exception("Invalid agreement copy file type.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        $mimeType = finfo_file($finfo, $tmpName);

        finfo_close($finfo);

        $allowedMimeTypes = [
            "application/pdf",
            "application/x-pdf",
            "image/jpeg",
            "image/png"
        ];

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new Exception("Invalid agreement copy.");
        }

        $random = strtoupper(
            substr(md5(uniqid(mt_rand(), true)), 0, 6)
        );

        $agreementFileName =
            $empCode .
            "_AGGR_" .
            $random .
            "." .
            $extension;

        $targetFile = $uploadDir . $agreementFileName;

        if (!move_uploaded_file($tmpName, $targetFile)) {
            throw new Exception("Unable to upload agreement copy.");
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE OLD FILE
        |--------------------------------------------------------------------------
        */

        if (!empty($oldAgreementFile)) {

            $oldPath = $uploadDir . $oldAgreementFile;

            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        $agreementSql = "
            UPDATE EPT_BCS_ITAX_EXEMPTION
            SET AGREEMENT_ATTACH = :attachment
            WHERE ID = :id
        ";

        $agreementStmt = oci_parse(
            $sql___func___con,
            $agreementSql
        );

        if (!$agreementStmt) {
            throw new Exception(
                oci_error($sql___func___con)["message"]
            );
        }

        oci_bind_by_name(
            $agreementStmt,
            ":attachment",
            $agreementFileName
        );

        oci_bind_by_name(
            $agreementStmt,
            ":id",
            $exemptionId
        );

        if (!oci_execute($agreementStmt, OCI_NO_AUTO_COMMIT)) {

            $e = oci_error($agreementStmt);

            throw new Exception($e["message"]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    oci_commit($sql___func___con);

    apiResponse(true, $message, [
        "id" => $exemptionId
    ]);

} catch (Throwable $e) {

    oci_rollback($sql___func___con);

    logOracleError($e);

    apiResponse(
        false,
        "Unable to save exemption details.",
        null,
        500
    );

} finally {

    if (isset($stmt) && $stmt) {
        oci_free_statement($stmt);
    }

    if (isset($uploadStmt) && $uploadStmt) {
        oci_free_statement($uploadStmt);
    }

    if (isset($agreementStmt) && $agreementStmt) {
        oci_free_statement($agreementStmt);
    }

    if (isset($sql___func___con) && $sql___func___con) {
        oci_close($sql___func___con);
    }
}