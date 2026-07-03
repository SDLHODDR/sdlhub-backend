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

    if (!isset($_SESSION['emp_code'])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /*
    ============================================
    GET PARAM VALUE
    ============================================
    */
    $param = singRec("
        SELECT SYS_VAL
        FROM EPT_SYS_PARAM
        WHERE SYS_LBL = 'IT_RETURN_EDIT_DATES'
    ");

    if (!empty($param['SYS_VAL'])) {

        // HANDLE BOTH LOWERCASE / UPPERCASE
        $dateString = trim(
            $param['SYS_VAL']
            ?? $param['sys_val']
            ?? ''
        );

        /*
        ============================================
        DEFAULT VALUES
        ============================================
        */

        $allowedDates = [];
        $canEdit = false;
        $today = date("Y-m-d");

        /*
        ============================================
        PROCESS DATE RANGE
        ============================================
        */

        if (!empty($dateString)) {

            $dates = explode(",", $dateString);

            if (count($dates) !== 2) {

                echo json_encode([
                    "status" => false,
                    "message" => "Two Date parameters (From and To) are expected."
                ]);

                exit;
            }

            $fromDate = trim($dates[0]);
            $toDate   = trim($dates[1]);

            $allowedDates = [
                "from_date" => $fromDate,
                "to_date" => $toDate
            ];

            /*
            ============================================
            RANGE CHECK
            ============================================
            */
            $canEdit = (
                strtotime($today) >= strtotime($fromDate) &&
                strtotime($today) <= strtotime($toDate)
            );
        }   

        /*
        ============================================
        RESPONSE
        ============================================
        */

        echo json_encode([
            "status" => true,
            "allowed_dates" => $allowedDates,
            "today" => $today,
            "can_edit" => $canEdit
        ]);
    }else{
         echo json_encode([
            "status" => false,
            "message" => "Dates not set for It return"
        ]);
    }

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}