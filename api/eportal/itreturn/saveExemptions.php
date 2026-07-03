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
| GET EMP ID
|--------------------------------------------------------------------------
*/

$empId = singRec("
    SELECT ID
    FROM EPT_BCS_EMPLOYEE
    WHERE EMP_CODE = '".$empCode."'
")["ID"] ?? null;

if (!$empId) {
    echo json_encode([
        "status" => false,
        "message" => "Employee not found"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| CURRENT FY
|--------------------------------------------------------------------------
*/

$acctPeriod = singRec("
    SELECT *
    FROM EPT_BCS_ACCT_PERIOD
    WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
");

$financialYear = $acctPeriod["CODE"] ?? "";

/*
|--------------------------------------------------------------------------
| FORM DATA (multipart/form-data)
|--------------------------------------------------------------------------
*/

$id = trim($_POST["exemption_id"] ?? "");

$from = trim($_POST["from"] ?? "");
$to = trim($_POST["to"] ?? "");
$monthlyRent = trim($_POST["monthlyRent"] ?? "");
$annualRent = trim($_POST["annualRent"] ?? "");
$address = trim($_POST["address"] ?? "");
$city = trim($_POST["city"] ?? "");

$landlordHasPan = trim($_POST["landlordHasPan"] ?? "yes");

$landlordName = trim($_POST["landlordName"] ?? "");
$landlordAddress = trim($_POST["landlordAddress"] ?? "");
$landlordPan = trim($_POST["landlordPan"] ?? "");

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
    echo json_encode([
        "status" => false,
        "message" => "Please fill all required fields"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| PAN mandatory validation
|--------------------------------------------------------------------------
| PAN required if:
| 1. landlordHasPan = yes
| OR
| 2. annualRent > 100000
*/

$isPanMandatory = (
    strtolower($landlordHasPan) === "yes" ||
    (float)$annualRent > 100000
);

if ($isPanMandatory) {

    if (empty($landlordPan)) {
        echo json_encode([
            "status" => false,
            "message" => "Landlord PAN is required"
        ]);
        exit;
    }

    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/i', $landlordPan)) {
        echo json_encode([
            "status" => false,
            "message" => "Invalid PAN format"
        ]);
        exit;
    }
}

try {

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    if (!empty($id)) {

        $updateSql = "
            UPDATE EPT_BCS_ITAX_EXEMPTION
            SET
                EMP_ID = :emp_id,
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

        $stmt = oci_parse($sql___func___con, $updateSql);
        oci_bind_by_name($stmt, ":id", $id);

        $exemptionId = $id;
        $message = "Exemption updated successfully";
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    else {

        $insertSql = "
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
        ";

        $stmt = oci_parse($sql___func___con, $insertSql);
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
    | GET INSERTED ID
    |--------------------------------------------------------------------------
    */

    if (empty($id)) {
        $row = singRec("
            SELECT MAX(ID) AS ID
            FROM EPT_BCS_ITAX_EXEMPTION
        ");

        $exemptionId = $row["ID"];
    }

    /*
    |--------------------------------------------------------------------------
    | FILE UPLOAD PATH
    |--------------------------------------------------------------------------
    */

    $publicPath = realpath(__DIR__ . "/../../../../public");

    if (!$publicPath) {
        echo json_encode([
            "status" => false,
            "message" => "Public directory not found"
        ]);
        exit;
    }

    $uploadDir = $publicPath . "/assets/img/incometax/".$financialYear."/";

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
            "message" => "Upload directory is not writable ".$uploadDir
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | LANDLORD PAN COPY
    |--------------------------------------------------------------------------
    */

    if (!empty($_FILES["panCopy"]["name"])) {

        $ext = strtolower(pathinfo($_FILES["panCopy"]["name"], PATHINFO_EXTENSION));
        $allowed = ["jpg", "jpeg", "png", "pdf"];

        if (in_array($ext, $allowed)) {

            $random = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 4);
            $fileName = $empCode . "_LPAN_" . $random . "." . $ext;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES["panCopy"]["tmp_name"], $targetPath)) {

                executeQry("
                    UPDATE EPT_BCS_ITAX_EXEMPTION
                    SET LANDLORD_PAN_ATTACH = '".$fileName."'
                    WHERE ID = '".$exemptionId."'
                ");
            } else {
                echo json_encode([
                    "status" => false,
                    "message" => "Failed to upload Agreement copy",
                    "path" => $targetPath
                ]);
                exit;
            }
        }else {
            echo json_encode([
                "status" => false,
                "message" => "invalid file type response",
                "path" => $targetPath
            ]);
            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AGREEMENT COPY
    |--------------------------------------------------------------------------
    */

    if (!empty($_FILES["agreementCopy"]["name"])) {

        $ext = strtolower(pathinfo($_FILES["agreementCopy"]["name"], PATHINFO_EXTENSION));
        $allowed = ["jpg", "jpeg", "png", "pdf"];

        if (in_array($ext, $allowed)) {

            $random = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 4);
            $fileName = $empCode . "_AGGR_" . $random . "." . $ext;
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES["agreementCopy"]["tmp_name"], $targetPath)) {

                executeQry("
                    UPDATE EPT_BCS_ITAX_EXEMPTION
                    SET AGREEMENT_ATTACH = '".$fileName."'
                    WHERE ID = '".$exemptionId."'
                ");
            }
        }
    }
    oci_commit($sql___func___con);

    echo json_encode([
        "status" => true,
        "message" => $message,
        "id" => $exemptionId
    ]);

} catch (Exception $e) {

    oci_rollback($sql___func___con);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}