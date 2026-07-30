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

    if (empty($employee["ID"])) {
        apiResponse(false, "Employee not found.", null, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | RELEASE SESSION LOCK
    |--------------------------------------------------------------------------
    */

    session_write_close();

    /*
    |--------------------------------------------------------------------------
    | PERIOD RANGE
    |--------------------------------------------------------------------------
    */

    $fromPrd = "202501";   // Or date('Y') . "01"
    $toPrd   = "202506";   // Or date('Ym')

    /*
    |--------------------------------------------------------------------------
    | SUMMARY QUERY
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            NVL(SUM(a.WORK_DAYS),0) AS WORK_DAYS,
            NVL(SUM(a.WOFF_DAYS),0) AS WEEKOFF_DAYS,
            NVL(SUM(a.HOLI_DAYS),0) AS HOLIDAY_DAYS,
            NVL(SUM(a.LVES_TAKEN),0) AS LEAVE_DAYS,
            NVL(SUM(
                TO_NUMBER(
                    TO_CHAR(
                        LAST_DAY(TO_DATE(a.PRD_CODE,'YYYYMM')),
                        'DD'
                    )
                ) - a.PAID_DAYS
            ),0) AS LWP_DAYS
        FROM EPT_BCS_ATTENDANCE_MON a
        JOIN EPT_BCS_EMPLOYEE b
            ON a.EMP_CODE = b.EMP_CODE
        JOIN EPT_BCS_PERIOD c
            ON c.CODE = a.PRD_CODE
        WHERE a.EMP_CODE = '".$empCode."'
        AND a.PRD_CODE BETWEEN '".$fromPrd."' AND '".$toPrd."'
        AND b.DATE_JOIN <= c.TO_DATE
    ";

    $row = singRec($sql);

    /*
    |--------------------------------------------------------------------------
    | SUMMARY DATA
    |--------------------------------------------------------------------------
    */

    $summary = [
        [
            "category" => "Work",
            "value" => (float)$row["WORK_DAYS"]
        ],
        [
            "category" => "Week Off",
            "value" => (float)$row["WEEKOFF_DAYS"]
        ],
        [
            "category" => "Holidays",
            "value" => (float)$row["HOLIDAY_DAYS"]
        ],
        [
            "category" => "Leave",
            "value" => (float)$row["LEAVE_DAYS"]
        ]
    ];

    if ((float)$row["LWP_DAYS"] > 0) {
        $summary[] = [
            "category" => "LWP",
            "value" => (float)$row["LWP_DAYS"]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | DURATION
    |--------------------------------------------------------------------------
    */

    $startMonth = date("M-Y", strtotime("first day of january this year"));
    $toMonth = date("M-Y");

    /*
    |--------------------------------------------------------------------------
    | AVERAGE WORK HOURS
    |--------------------------------------------------------------------------
    */

    $avgWorkHrs = singRec("
        SELECT ROUND(AVG(WORK_HOUR),2) AS HRS
        FROM EPT_BCS_ATTD_REG
        WHERE EMP_CODE = '".$empCode."'
    ");

    $data = [
        "summary" => $summary,
        "duration" => $startMonth . " To " . $toMonth,
        "avgWorkHrs" => (float)($avgWorkHrs["HRS"] ?? 0)
    ];

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Attendance summary fetched successfully.",
        $data
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(
        false,
        "Unable to fetch attendance summary.",
        null,
        500
    );

} finally {

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }

}