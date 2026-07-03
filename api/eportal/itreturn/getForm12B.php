<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

header("Content-Type: application/json");

if (!isset($_SESSION['emp_code'])) {
    apiResponse(false, "Unauthorized Access", null, 401);
    exit;
}

$empCode = $_SESSION['emp_code'];

/*
|--------------------------------------------------------------------------
| GET EMPLOYEE PRIMARY ID
|--------------------------------------------------------------------------
*/

$employee = singRec("
    SELECT ID, DATE_JOIN
    FROM EPT_BCS_EMPLOYEE
    WHERE EMP_CODE = '".$empCode."'
");

if (!$employee) {
    echo json_encode([
        "status" => false,
        "message" => "Employee not found"
    ]);
    exit;
}

$empId = $employee["ID"];
$dateJoin = $employee["DATE_JOIN"];

/*
|--------------------------------------------------------------------------
| DOJ CHECK
| Example: Financial year starts from 1st April current FY
|--------------------------------------------------------------------------
*/

$currentYear = date("Y");
$financialYearStart = date("Y-m-d", strtotime("01-04-" . $currentYear));

$isEligible = false;

if (!empty($dateJoin)) {
    $joiningDate = date("Y-m-d", strtotime($dateJoin));

    if ($joiningDate >= $financialYearStart) {
        $isEligible = true;
    }
}
 $isEligible = true;
/*
|--------------------------------------------------------------------------
| FETCH EXISTING FORM DATA
|--------------------------------------------------------------------------
*/

$data = singRec("
    SELECT
        ID,
        NAME_PREVEMP,
        ADDRESS_PREVEMP,
        TAN_PREVEMP,
        PAN_PREVEMP,
        TO_CHAR(FROM_PREVEMP, 'YYYY-MM-DD') AS FROM_PREVEMP,
        TO_CHAR(TO_PREVEMP, 'YYYY-MM-DD') AS TO_PREVEMP,
        TOTAL_SALARY,
        HRA_CA_OTH_ALLOWANCE,
        PERQUISITE_AND_PF,
        TOTAL_5_6_7,
        AMOUNT_DEDUCTED_LI_PF,
        TOTAL_TAX_DEDUCTED,
        REMARKS
    FROM EPT_BCS_ITAX_12B
    WHERE EMP_ID = '".$empId."'
");

echo json_encode([
    "status" => true,
    "eligible" => $isEligible,
    "data" => $data ?: []
]);