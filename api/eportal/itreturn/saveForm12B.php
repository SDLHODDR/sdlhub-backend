<?php

//error_reporting(E_ALL);
//ini_set("display_errors", 1);

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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {    
    apiResponse(false, "Invalid request method", null, 405);
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['emp_code'])) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

$empCode = $_SESSION['emp_code'] ?? '';

/* ---------------------------
    GET EMP ID
---------------------------- */
$empId = singRec("
    SELECT ID 
    FROM EPT_BCS_EMPLOYEE 
    WHERE emp_code = '".$empCode."'
")['ID'] ?? null;

if (!$empId) {
    apiResponse(false, "Profile Not Found", null, 404);
}

/*
|--------------------------------------------------------------------------
| READ JSON INPUT
|--------------------------------------------------------------------------
*/

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    apiResponse(false, "Invalid input data.");
}

/*
|--------------------------------------------------------------------------
| FORM DATA
|--------------------------------------------------------------------------
*/

$id = trim($input["id"] ?? "");

if ($id !== "" && !ctype_digit($id)) {
    apiResponse(false, "Invalid record id.");
}

$previousEmployerName    = trim($input["previousEmployerName"] ?? "");
$previousEmployerAddress = trim($input["previousEmployerAddress"] ?? "");
$tanNumber               = trim($input["tanNumber"] ?? "");
$panNumber               = trim($input["panNumber"] ?? "");

$fromDate = trim($input["fromDate"] ?? "");
$toDate   = trim($input["toDate"] ?? "");

$totalSalary = trim($input["totalSalary"] ?? "");
$hra         = trim($input["hra"] ?? "");
$perquisites = trim($input["perquisites"] ?? "");
$total       = trim($input["total"] ?? "");
$insurance   = trim($input["insurance"] ?? "");
$tds         = trim($input["tds"] ?? "");
$remarks     = trim($input["remarks"] ?? "");

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (
    empty($previousEmployerName) ||
    empty($previousEmployerAddress) ||
    empty($tanNumber) ||
    empty($panNumber) ||
    empty($fromDate) ||
    empty($toDate) ||
    $totalSalary === "" ||
    $hra === "" ||
    $perquisites === "" ||
    $total === "" ||
    $insurance === "" ||
    $tds === ""
) {   
    apiResponse(false, "Please fill all required fields.");
}

$fromObj = DateTime::createFromFormat('Y-m-d', $fromDate);
$toObj   = DateTime::createFromFormat('Y-m-d', $toDate);

if (!$fromObj || $fromObj->format('Y-m-d') !== $fromDate) {
    apiResponse(false, "Invalid From Date.");
}

if (!$toObj || $toObj->format('Y-m-d') !== $toDate) {
    apiResponse(false, "Invalid To Date.");
}

if (strtotime($fromDate) > strtotime($toDate)) {
    apiResponse(false, "From Date cannot be greater than To Date.");
}

if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/i', $panNumber)) {
    apiResponse(false, "Invalid PAN number.");
}

if (!preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/i', $tanNumber)) {
    apiResponse(false, "Invalid TAN number.");
}

$numericFields = [
    "Total Salary" => $totalSalary,
    "HRA" => $hra,
    "Perquisites" => $perquisites,
    "Total" => $total,
    "Insurance" => $insurance,
    "TDS" => $tds
];

foreach ($numericFields as $label => $value) {

    if (
        !is_numeric($value) ||
        $value < 0 ||
        $value > 999999999
    ) {
        apiResponse(false, "$label is invalid.");
    }
}

try {

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING RECORD
    |--------------------------------------------------------------------------
    */

    $checkSql = "
        SELECT ID
        FROM EPT_BCS_ITAX_12B
        WHERE EMP_ID = :emp_id
    ";

    $checkStmt = oci_parse($sql___func___con, $checkSql);

    if (!$checkStmt) {
        $e = oci_error($sql___func___con);
        throw new Exception($e["message"]);
    }

    oci_bind_by_name($checkStmt, ":emp_id", $empId);

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
  
    if (!empty($existing) || !empty($id)) {

        $recordId = !empty($id)
            ? $id
            : $existing["ID"];

        $updateSql = "
            UPDATE EPT_BCS_ITAX_12B
            SET
                NAME_PREVEMP = :previousEmployerName,
                ADDRESS_PREVEMP = :previousEmployerAddress,
                TAN_PREVEMP = :tanNumber,
                PAN_PREVEMP = :panNumber,

                FROM_PREVEMP = TO_DATE(:fromDate, 'YYYY-MM-DD'),
                TO_PREVEMP   = TO_DATE(:toDate, 'YYYY-MM-DD'),

                TOTAL_SALARY = :totalSalary,
                HRA_CA_OTH_ALLOWANCE = :hra,
                PERQUISITE_AND_PF = :perquisites,
                TOTAL_5_6_7 = :total,
                AMOUNT_DEDUCTED_LI_PF = :insurance,
                TOTAL_TAX_DEDUCTED = :tds,
                REMARKS = :remarks,

                CHG_ON = SYSDATE,
                CHG_BY = :chg_by

            WHERE ID = :id
        ";

        $stmt = oci_parse($sql___func___con, $updateSql);

        if (!$stmt) {
            $e = oci_error($sql___func___con);
            throw new Exception($e["message"]);
        }

        oci_bind_by_name($stmt, ":previousEmployerName", $previousEmployerName);
        oci_bind_by_name($stmt, ":previousEmployerAddress", $previousEmployerAddress);
        oci_bind_by_name($stmt, ":tanNumber", $tanNumber);
        oci_bind_by_name($stmt, ":panNumber", $panNumber);

        oci_bind_by_name($stmt, ":fromDate", $fromDate);
        oci_bind_by_name($stmt, ":toDate", $toDate);

        oci_bind_by_name($stmt, ":totalSalary", $totalSalary);
        oci_bind_by_name($stmt, ":hra", $hra);
        oci_bind_by_name($stmt, ":perquisites", $perquisites);
        oci_bind_by_name($stmt, ":total", $total);
        oci_bind_by_name($stmt, ":insurance", $insurance);
        oci_bind_by_name($stmt, ":tds", $tds);
        oci_bind_by_name($stmt, ":remarks", $remarks);

        oci_bind_by_name($stmt, ":chg_by", $empId);
        oci_bind_by_name($stmt, ":id", $recordId);

        $message = "Form 12B updated successfully";
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    else {

        $insertSql = "
            INSERT INTO EPT_BCS_ITAX_12B
            (
                EMP_ID,

                NAME_PREVEMP,
                ADDRESS_PREVEMP,
                TAN_PREVEMP,
                PAN_PREVEMP,

                FROM_PREVEMP,
                TO_PREVEMP,

                TOTAL_SALARY,
                HRA_CA_OTH_ALLOWANCE,
                PERQUISITE_AND_PF,
                TOTAL_5_6_7,
                AMOUNT_DEDUCTED_LI_PF,
                TOTAL_TAX_DEDUCTED,
                REMARKS,

                CHG_ON,
                CHG_BY
            )
            VALUES
            (
                :emp_id,

                :previousEmployerName,
                :previousEmployerAddress,
                :tanNumber,
                :panNumber,

                TO_DATE(:fromDate, 'YYYY-MM-DD'),
                TO_DATE(:toDate, 'YYYY-MM-DD'),

                :totalSalary,
                :hra,
                :perquisites,
                :total,
                :insurance,
                :tds,
                :remarks,

                SYSDATE,
                :chg_by
            )
        ";

        $stmt = oci_parse($sql___func___con, $insertSql);

        if (!$stmt) {
            $e = oci_error($sql___func___con);
            throw new Exception($e["message"]);
        }

        oci_bind_by_name($stmt, ":emp_id", $empId);

        oci_bind_by_name($stmt, ":previousEmployerName", $previousEmployerName);
        oci_bind_by_name($stmt, ":previousEmployerAddress", $previousEmployerAddress);
        oci_bind_by_name($stmt, ":tanNumber", $tanNumber);
        oci_bind_by_name($stmt, ":panNumber", $panNumber);

        oci_bind_by_name($stmt, ":fromDate", $fromDate);
        oci_bind_by_name($stmt, ":toDate", $toDate);

        oci_bind_by_name($stmt, ":totalSalary", $totalSalary);
        oci_bind_by_name($stmt, ":hra", $hra);
        oci_bind_by_name($stmt, ":perquisites", $perquisites);
        oci_bind_by_name($stmt, ":total", $total);
        oci_bind_by_name($stmt, ":insurance", $insurance);
        oci_bind_by_name($stmt, ":tds", $tds);
        oci_bind_by_name($stmt, ":remarks", $remarks);

        oci_bind_by_name($stmt, ":chg_by", $empId);

        $message = "Form 12B saved successfully";
    }

    /*
    |--------------------------------------------------------------------------
    | EXECUTE
    |--------------------------------------------------------------------------
    */

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt);
        throw new Exception($e["message"]);
    }

    oci_commit($sql___func___con);
    apiResponse(true, $message);

} catch (Throwable $e) {

    oci_rollback($sql___func___con);
    logOracleError($e);
    apiResponse(false, "Unable to save Form 12B.", null, 500);

}finally {

    if (isset($checkStmt) && $checkStmt) {
        oci_free_statement($checkStmt);
    }

    if (isset($stmt) && $stmt) {
        oci_free_statement($stmt);
    }

}