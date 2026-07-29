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
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* =====================================================
       GET EMPLOYEE DETAILS
    ===================================================== */

    $employee = singRec("
        SELECT
            ID,
            DATE_JOIN
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    ");

    if (empty($employee)) {
        apiResponse(false, "Employee not found.");
    }

    $empId    = $employee["ID"];
    $dateJoin = $employee["DATE_JOIN"];

    /* =====================================================
       CHECK ELIGIBILITY
       (Joined during current financial year)
    ===================================================== */

    $currentYear = date("Y");
    $financialYearStart = strtotime("01-04-" . $currentYear);

    $isEligible = false;

    if (!empty($dateJoin)) {
        $joiningDate = strtotime($dateJoin);

        if ($joiningDate >= $financialYearStart) {
            $isEligible = true;
        }
    }

    // Remove this after testing
    // $isEligible = true;

    /* =====================================================
       FETCH FORM 12B DATA
    ===================================================== */

    $form12B = singRec("
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
        WHERE EMP_ID = '$empId'
    ");

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(
        true,
        "Form 12B data fetched successfully.",
        [
            "eligible" => $isEligible,
            "form12B"  => $form12B ?: []
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch Form 12B data.", null, 500);
}