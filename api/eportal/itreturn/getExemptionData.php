<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
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
| GET CURRENT FINANCIAL YEAR
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
| FETCH MULTIPLE EXEMPTION RECORDS
|--------------------------------------------------------------------------
*/

$rows = multiRec("
    SELECT
        ID,
        FROM_MONTH,
        TO_MONTH,
        MONTHLY_RENT,
        ANNUAL_RENT,
        ADDRESS,
        CITY,
        LANDLORD_NAME,
        LANDLORD_ADDRESS,
        LANDLORD_PAN,
        LANDLORD_PAN_ATTACH,
        AGREEMENT_ATTACH
    FROM EPT_BCS_ITAX_EXEMPTION
    WHERE EMP_ID = '".$empId."'
    AND FY = '".$financialYear."'
    ORDER BY ID DESC
");

/*
|--------------------------------------------------------------------------
| NO DATA FOUND
|--------------------------------------------------------------------------
*/

if (!$rows || count($rows) === 0) {
    echo json_encode([
        "status" => true,
        "message" => "No exemption data found",
        "data" => []
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| FORMAT RESPONSE DATA
|--------------------------------------------------------------------------
*/

$data = [];

foreach ($rows as $row) {

    $landlordHasPan = !empty($row["LANDLORD_PAN"])
        ? "yes"
        : "no";

    $data[] = [
        "exemption_id" => $row["ID"] ?? "",

        "from" => $row["FROM_MONTH"] ?? "",
        "to" => $row["TO_MONTH"] ?? "",

        "monthlyRent" => $row["MONTHLY_RENT"] ?? "",
        "annualRent" => $row["ANNUAL_RENT"] ?? "",

        "address" => $row["ADDRESS"] ?? "",
        "city" => $row["CITY"] ?? "Non Metro",

        "landlordHasPan" => $landlordHasPan,

        "landlordName" => $row["LANDLORD_NAME"] ?? "",
        "landlordAddress" => $row["LANDLORD_ADDRESS"] ?? "",
        "landlordPan" => $row["LANDLORD_PAN"] ?? "",

        "panCopy" => $row["LANDLORD_PAN_ATTACH"] ?? "",
        "agreementCopy" => $row["AGREEMENT_ATTACH"] ?? ""
    ];
}

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode([
    "status" => true,
    "message" => "Exemption data fetched successfully",
    "data" => $data
]);
exit;