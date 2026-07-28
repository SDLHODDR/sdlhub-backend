<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* =====================================================
       METHOD VALIDATION
    ===================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* =====================================================
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION["emp_code"] ?? "";

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* =====================================================
       GET EMPLOYEE ID
    ===================================================== */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    ");

    $empId = $employee["ID"] ?? null;

    if (empty($empId)) {
        apiResponse(false, "Employee not found.");
    }

    /* =====================================================
       GET CURRENT FINANCIAL YEAR
    ===================================================== */

    $acctPeriod = singRec("
        SELECT CODE
        FROM EPT_BCS_ACCT_PERIOD
        WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
    ");

    $financialYear = $acctPeriod["CODE"] ?? "";

    if (empty($financialYear)) {
        apiResponse(false, "Financial year not found.");
    }

    /* =====================================================
       FETCH EXEMPTION RECORDS
    ===================================================== */

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
        WHERE EMP_ID = '$empId'
        AND FY = '$financialYear'
        ORDER BY ID DESC
    ");

    /* =====================================================
       FORMAT RESPONSE
    ===================================================== */

    $data = [];

    foreach ($rows as $row) {

        $data[] = [
            "exemption_id"   => $row["ID"] ?? "",
            "from"           => $row["FROM_MONTH"] ?? "",
            "to"             => $row["TO_MONTH"] ?? "",
            "monthlyRent"    => $row["MONTHLY_RENT"] ?? "",
            "annualRent"     => $row["ANNUAL_RENT"] ?? "",
            "address"        => $row["ADDRESS"] ?? "",
            "city"           => $row["CITY"] ?? "Non Metro",
            "landlordHasPan" => !empty($row["LANDLORD_PAN"]) ? "yes" : "no",
            "landlordName"   => $row["LANDLORD_NAME"] ?? "",
            "landlordAddress"=> $row["LANDLORD_ADDRESS"] ?? "",
            "landlordPan"    => $row["LANDLORD_PAN"] ?? "",
            "panCopy"        => $row["LANDLORD_PAN_ATTACH"] ?? "",
            "agreementCopy"  => $row["AGREEMENT_ATTACH"] ?? ""
        ];
    }

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(
        true,
        empty($data)
            ? "No exemption data found."
            : "Exemption data fetched successfully.",
        [
            "financial_year" => $financialYear,
            "exemptions" => $data
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch exemption data.", null, 500);
}