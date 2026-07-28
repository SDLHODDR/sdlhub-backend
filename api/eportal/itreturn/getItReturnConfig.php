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

try {

    /* =====================================================
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* =====================================================
       GET IT RETURN EDIT DATES
    ===================================================== */

    $param = singRec("
        SELECT SYS_VAL
        FROM EPT_SYS_PARAM
        WHERE SYS_LBL = 'IT_RETURN_EDIT_DATES'
    ");

    $dateString = trim(
        $param['SYS_VAL']
        ?? $param['sys_val']
        ?? ''
    );

    if (empty($dateString)) {
        apiResponse(false, "IT Return edit dates are not configured.");
    }

    /* =====================================================
       VALIDATE DATE RANGE
    ===================================================== */

    $dates = array_map('trim', explode(',', $dateString));

    if (count($dates) !== 2) {
        apiResponse(false, "IT Return edit dates are configured incorrectly.");
    }

    $fromDate = $dates[0];
    $toDate   = $dates[1];
    $today    = date("Y-m-d");

    /* =====================================================
       CHECK EDIT PERMISSION
    ===================================================== */

    $canEdit =
        strtotime($today) >= strtotime($fromDate) &&
        strtotime($today) <= strtotime($toDate);

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(
        true,
        "IT Return configuration fetched successfully.",
        [
            "allowed_dates" => [
                "from_date" => $fromDate,
                "to_date"   => $toDate
            ],
            "today"    => $today,
            "can_edit" => $canEdit
        ]
    );

} catch (Throwable $e) {

    logOracleError($e);
    apiResponse(false, "Unable to fetch IT Return configuration.", null, 500);
}