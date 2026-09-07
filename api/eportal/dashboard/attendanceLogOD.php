<?php

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
    apiResponse(false, "Invalid request method.", null, 405);
}

/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["emp_code"])) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

$empCode = $data["emp_code"] ?? "";

/*
|--------------------------------------------------------------------------
| RELEASE SESSION LOCK
|--------------------------------------------------------------------------
*/

session_write_close();

try {

    /*
    |--------------------------------------------------------------------------
    | DATE
    |--------------------------------------------------------------------------
    */

    $date = '02-APR-2025'; //trim($_GET["date"] ?? date("d-M-Y"));
    $date = strtoupper($date);

    $dateObj = DateTime::createFromFormat("d-M-Y", $date);

    if (!$dateObj || strtoupper($dateObj->format("d-M-Y")) !== strtoupper($date)) {
        apiResponse(false, "Invalid date.", null, 400);
    }

    /*
    |--------------------------------------------------------------------------
    | GET EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employee = singRec("
        SELECT
            ID,
            WORK_SITE
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '".$empCode."'
    ");

    if (empty($employee)) {
        apiResponse(false, "Profile not found.", null, 404);
    }

    $empId = $employee["ID"];
    $worksite = $employee["WORK_SITE"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | BUILD QUERY
    |--------------------------------------------------------------------------
    */

    if ($worksite === "SDLPN") {

        $sql = "
            SELECT
                TO_CHAR(PUNCHDATETIME,'HH24:MI:SS') AS TIME,
                CASE
                    WHEN MACHINENO BETWEEN 21 AND 25 THEN 'Panvel'
                    ELSE 'Office'
                END AS LOCATION
            FROM EPT_BCS_MACHINE_ATTN_DATA
            WHERE CARDNO LIKE '%".$empCode."%'
            AND TRUNC(PUNCHDATETIME)=TO_DATE('".$date."','DD-MON-YYYY')
            ORDER BY PUNCHDATETIME
        ";

    } else {

        $sql = "
            SELECT
                TO_CHAR(A.EVENTDATETIME,'HH24:MI:SS') AS TIME,
                NVL(M.MACHINE_NAME,'Office') AS LOCATION
            FROM EPT_BCS_MATRIX_ATTD A
            LEFT JOIN EPT_MACHINE_MASTER M
                ON A.MASTERCONTROLLERID=M.ID
            WHERE A.USERID='".$empCode."'
            AND TRUNC(A.EVENTDATETIME)=TO_DATE('".$date."','DD-MON-YYYY')
            ORDER BY A.EVENTDATETIME
        ";
    }

    $rows = multiRec($sql);

    /*
    |--------------------------------------------------------------------------
    | HELPER
    |--------------------------------------------------------------------------
    */

    function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return sprintf("%02dh %02dm", $hours, $minutes);
    }

        /*
    |--------------------------------------------------------------------------
    | PROCESS ATTENDANCE
    |--------------------------------------------------------------------------
    */

    $records = [];
    $locationSummary = [];

    $previousTime = null;
    $previousLocation = null;

    $firstIn = null;
    $lastOut = null;

    foreach ($rows as $index => $row) {

        $currentTime = strtotime($row["TIME"]);
        $currentLocation = trim($row["LOCATION"]);

        /*
        |--------------------------------------------------------------------------
        | FIRST IN
        |--------------------------------------------------------------------------
        */

        if ($index === 0) {
            $firstIn = $currentTime;
        }

        /*
        |--------------------------------------------------------------------------
        | LOCATION DURATION
        |--------------------------------------------------------------------------
        */

        if ($previousTime !== null) {

            $seconds = $currentTime - $previousTime;
            if ($seconds < 0) {
                $seconds = 0;
            }

            $baseLocation = preg_replace(
                '/\s(In|Out)$/i',
                '',
                $previousLocation
            );

            if (!isset($locationSummary[$baseLocation])) {
                $locationSummary[$baseLocation] = 0;
            }

            $locationSummary[$baseLocation] += $seconds;
        }

        /*
        |--------------------------------------------------------------------------
        | TIMELINE RECORD
        |--------------------------------------------------------------------------
        */

        $records[] = [
            "type" => ($index % 2 === 0) ? "IN" : "OUT",
            "time" => $row["TIME"],
            "location" => $currentLocation
        ];

        $previousTime = $currentTime;
        $previousLocation = $currentLocation;

        /*
        |--------------------------------------------------------------------------
        | LAST OUT
        |--------------------------------------------------------------------------
        */

        if ($index === (count($rows) - 1)) {
            $lastOut = $currentTime;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOCATION SUMMARY
    |--------------------------------------------------------------------------
    */

    $locationData = [];

    foreach ($locationSummary as $location => $seconds) {

        $locationData[] = [
            "location"  => $location,
            "seconds"   => $seconds,
            "formatted" => formatDuration($seconds)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | TOTAL OFFICE TIME
    |--------------------------------------------------------------------------
    */

    if ($firstIn !== null && $lastOut !== null && $lastOut >= $firstIn) {
        $totalOfficeTime = formatDuration($lastOut - $firstIn);
    } else {
        $totalOfficeTime = "00h 00m";
    }

        /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Attendance timeline fetched successfully.",
        [
            "date" => $date,
            "records" => $records,
            "summary" => [
                "byLocation" => $locationData,
                "totalOfficeTime" => $totalOfficeTime
            ]
        ]
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    logOracleError($e);

    apiResponse(false,  "Unable to fetch attendance timeline.", null, 500);

} finally {

    /*
    |--------------------------------------------------------------------------
    | CLOSE DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}