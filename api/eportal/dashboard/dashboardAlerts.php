<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /*
    |--------------------------------------------------------------------------
    | METHOD CHECK
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION CHECK
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access.", null, 401);
    }

    $empCode = trim($_SESSION["emp_code"]);

    /*
    |--------------------------------------------------------------------------
    | VERIFY EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '".$empCode."'
    ");

    $empId = $employee["ID"] ?? null;

    if (!$empId) {
        apiResponse(false, "Profile not found.", null, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | RELEASE SESSION LOCK
    |--------------------------------------------------------------------------
    */

    session_write_close();

    /*
    |--------------------------------------------------------------------------
    | CURRENT FINANCIAL YEAR
    |--------------------------------------------------------------------------
    */

    $year = date("Y");
    $month = date("m");

    $fy = ($month >= 4)
        ? $year . "-" . substr($year + 1, -2)
        : ($year - 1) . "-" . substr($year, -2);

    /*
    |--------------------------------------------------------------------------
    | IT DECLARATION CHECK
    |--------------------------------------------------------------------------
    */

    $isDeclared = singRec("
        SELECT EMP_ID
        FROM EPT_BCS_ITAX_EMP_REGIME
        WHERE EMP_ID = '".$empId."'
        AND FY = '".$fy."'
    ");

    /*
    |--------------------------------------------------------------------------
    | ALERTS
    |--------------------------------------------------------------------------
    */

    $alerts = [];

    if (empty($isDeclared)) {

        $alerts[] = [
            "type" => "danger",
            "message" => "You have not completed IT Declaration for current financial year.",
            "actionText" => "Click here to proceed",
            "actionUrl" => "/eportal/it-return"
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UPCOMING MEETINGS
    |--------------------------------------------------------------------------
    */

    $meetings = multiRec("
        SELECT
            r.ROOM_LABEL,
            TO_CHAR(c.START_TIME,'HH24:MI') AS START_TIME,
            TO_CHAR(c.END_TIME,'HH24:MI') AS END_TIME
        FROM EPT_CONF_ROOM_TRAN c
        LEFT JOIN EPT_CONF_ROOMS r
            ON r.ID = c.ROOM_ID
        WHERE
            (c.CHG_BY = '".$empCode."'
            OR c.BOOK_BY_EMP = '".$empCode."')
        AND c.STATUS IN ('A','N','T')
        AND TRUNC(c.START_TIME) = TRUNC(SYSDATE)
        AND c.START_TIME >= SYSDATE
        AND c.START_TIME <= SYSDATE + (1/24)
        ORDER BY c.START_TIME
    ");

    if (!empty($meetings)) {

        $alerts[] = [
            "type" => "info",
            "message" => count($meetings)." upcoming meeting(s) in next 1 hour",
            "actionText" => "View Schedule",
            "actionUrl" => "/eportal/conference-room"
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MONTH END ALERT
    |--------------------------------------------------------------------------
    */

    if ((int)date("d") >= 25) {

        $alerts[] = [
            "type" => "warning",
            "message" => "Kindly check the status of your leave or OD request to ensure approval.",
            "actionText" => null,
            "actionUrl" => null
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Dashboard alerts fetched successfully.",
        [
            "alerts" => $alerts,
            "meetings" => $meetings
        ]
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    logOracleError($e);

    apiResponse(false, "Unable to fetch dashboard alerts.", null, 500);

} finally {

    /*
    |--------------------------------------------------------------------------
    | CLOSE CONNECTION
    |--------------------------------------------------------------------------
    */

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }

}