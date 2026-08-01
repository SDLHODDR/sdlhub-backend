<?php
ob_start();

/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /*====================================================
      SESSION
    ====================================================*/

    $empCode = $_SESSION['emp_code'] ?? null;

    if (!$empCode) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /* Release session lock */
    session_write_close();

    /*====================================================
      CACHE (OPTIONAL)
    ====================================================*/

    $cacheFile = sys_get_temp_dir() . "/att10_" . $empCode . ".json";

    /*
    if (
        file_exists($cacheFile) &&
        (time() - filemtime($cacheFile)) < 120
    ) {
        echo file_get_contents($cacheFile);
        exit;
    }
    */

    /*====================================================
      BUILD LAST 10 DAYS
    ====================================================*/

    $dates   = [];
    $dateMap = [];

    for ($i = 9; $i >= 0; $i--) {

        $date = date("d-M-Y", strtotime("-{$i} days"));

        $dates[] = $date;

        $dateMap[$date] = [
            "date"        => date("d-M", strtotime($date)),
            "in"          => "--:--",
            "out"         => "--:--",
            "workingHrs"  => "Day Off",
            "onDesk"      => "00:00",
            "offDesk"     => "00:00",
            "terrace"     => "00:00",
            "leaveType"   => "--",
            "remarks"     => ""
        ];
    }

    $dateList = "'" . implode("','", $dates) . "'";

    $startDate = date("d-M-Y", strtotime("-9 days"));

    /*====================================================
      TIME METRICS
    ====================================================*/

    $sql = "
        WITH dates AS
        (
            SELECT TO_DATE(:start_date,'DD-MON-YYYY') + LEVEL - 1 dt
            FROM dual
            CONNECT BY LEVEL <= 10
        )

        SELECT

            TO_CHAR(dt,'DD-MON-YYYY') ASON_DATE,

            NVL(
                (
                    SELECT TO_CHAR(
                        TO_DATE(SUM(time_diff_sec),'SSSSS'),
                        'HH24:MI'
                    )
                    FROM ept_bcs_attd_daily d
                    WHERE d.emp_code = :emp_code
                    AND d.ason_date = TO_CHAR(dt,'DD-MON-YYYY')
                    AND d.machine_no IN (5,6,11)
                ),
                '00:00'
            ) ONDESK,

            NVL(
                TO_CHAR(
                    TO_DATE(
                        EPT_GET_OFFDESK_TIME(:emp_code, dt),
                        'SSSSS'
                    ),
                    'HH24:MI'
                ),
                '00:00'
            ) OFFDESK,

            NVL(
                TO_CHAR(
                    TO_DATE(
                        EPT_GET_TERRACE_TIME(:emp_code, dt),
                        'SSSSS'
                    ),
                    'HH24:MI'
                ),
                '00:00'
            ) TERRACE

        FROM dates
        ORDER BY dt
    ";

    $stmt = oci_parse($sql___func___con, $sql);

    oci_bind_by_name($stmt, ":start_date", $startDate);
    oci_bind_by_name($stmt, ":emp_code", $empCode);

    oci_execute($stmt);

    while ($row = oci_fetch_assoc($stmt)) {

        $key = date("d-M-Y", strtotime($row["ASON_DATE"]));

        if (!isset($dateMap[$key])) {
            continue;
        }

        $dateMap[$key]["onDesk"]  = $row["ONDESK"] ?? "00:00";
        $dateMap[$key]["offDesk"] = $row["OFFDESK"] ?? "00:00";
        $dateMap[$key]["terrace"] = $row["TERRACE"] ?? "00:00";
    }

    oci_free_statement($stmt);

    /* =============================
       1. TIME METRICS (SINGLE QUERY)
    ============================== */

    $startDate = date('d-M-Y', strtotime("-9 days"));

    $sql = "
        WITH dates AS (
            SELECT TO_DATE(:start_date,'DD-MON-YYYY') + LEVEL - 1 dt
            FROM dual
            CONNECT BY LEVEL <= 10
        )
        SELECT
            TO_CHAR(dt,'DD-MON-YYYY') AS ASON_DATE,

            NVL((
                SELECT TO_CHAR(
                    TO_DATE(SUM(time_diff_sec),'SSSSS'),
                    'HH24:MI'
                )
                FROM ept_bcs_attd_daily d
                WHERE d.emp_code = :empCode
                AND d.ason_date = TO_CHAR(dt,'DD-MON-YYYY')
                AND d.machine_no IN (5,6,11)
            ),'00:00') AS ONDESK,

            NVL(
                TO_CHAR(
                    TO_DATE(EPT_GET_OFFDESK_TIME(:empCode,dt),'SSSSS'),
                    'HH24:MI'
                ),
                '00:00'
            ) AS OFFDESK,

            NVL(
                TO_CHAR(
                    TO_DATE(EPT_GET_TERRACE_TIME(:empCode,dt),'SSSSS'),
                    'HH24:MI'
                ),
                '00:00'
            ) AS TERRACE

        FROM dates
        ORDER BY dt
    ";

    $stid = oci_parse($sql___func___con, $sql);

    oci_bind_by_name($stid, ":start_date", $startDate);
    oci_bind_by_name($stid, ":empCode", $empCode);

    if (!oci_execute($stid)) {
        $e = oci_error($stid);
        throw new Exception($e['message']);
    }

    while ($row = oci_fetch_assoc($stid)) {

        $key = date('d-M-Y', strtotime($row['ASON_DATE']));

        if (!isset($dateMap[$key])) {
            continue;
        }

        $dateMap[$key]['onDesk']  = $row['ONDESK'] ?? '00:00';
        $dateMap[$key]['offDesk'] = $row['OFFDESK'] ?? '00:00';
        $dateMap[$key]['terrace'] = $row['TERRACE'] ?? '00:00';
    }

    oci_free_statement($stid);

    /* =============================
       2. PUNCH DATA
    ============================== */

    $punchRows = multiRec("
        SELECT
            ATTD_DATE,
            TO_CHAR(IN_TIME,'HH24:MI:SS') AS IN_TIME,
            TO_CHAR(OUT_TIME,'HH24:MI:SS') AS OUT_TIME,
            WORK_HOUR
        FROM EPT_BCS_ATTD_REG
        WHERE EMP_CODE = '$empCode'
        AND ATTD_DATE IN ($dateList)
    ");

    foreach ($punchRows as $row) {

        $key = date('d-M-Y', strtotime($row['ATTD_DATE']));

        if (!isset($dateMap[$key])) {
            continue;
        }

        $dateMap[$key]['in'] = $row['IN_TIME'] ?: '--:--';
        $dateMap[$key]['out'] = $row['OUT_TIME'] ?: '--:--';

        if (!empty($row['WORK_HOUR'])) {
            $dateMap[$key]['workingHrs'] = $row['WORK_HOUR'] . " HRS";
        }
    }
    /* =============================
   3. LEAVE (BULK)
============================= */

$leaveRows = multiRec("
    SELECT
        LVE_CODE,
        NO_DAYS,
        REMARKS,
        LVE_DATE_FR,
        LVE_DATE_TO
    FROM EPT_BCS_EMP_LEAVES
    WHERE EMP_CODE = '$empCode'
");

foreach ($leaveRows as $leave) {

    $fromDate = strtotime($leave['LVE_DATE_FR']);
    $toDate   = strtotime($leave['LVE_DATE_TO']);

    foreach ($dates as $displayDate) {

        $currentDate = strtotime($displayDate);

        if ($currentDate >= $fromDate && $currentDate <= $toDate) {

            if (isset($dateMap[$displayDate])) {

                $dateMap[$displayDate]['leaveType'] =
                    $leave['LVE_CODE'] .
                    '(' .
                    number_format((float)$leave['NO_DAYS'], 1) .
                    ')';

                $dateMap[$displayDate]['remarks'] =
                    $leave['REMARKS'] ?? '';
            }
        }
    }
}

/* =============================
   4. HOLIDAYS (BULK)
============================= */

$holidayRows = multiRec("
    SELECT
        HOL_DATE,
        DESCR
    FROM EPT_BCS_HOLIDAYS
    WHERE HOL_GRP = (
        SELECT HOL_TBLNO
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    )
    AND HOL_DATE IN ($dateList)
");

foreach ($holidayRows as $holiday) {

    $holidayKey = date('d-M-Y', strtotime($holiday['HOL_DATE']));

    if (
        isset($dateMap[$holidayKey]) &&
        $dateMap[$holidayKey]['leaveType'] === '--'
    ) {
        $dateMap[$holidayKey]['leaveType'] = $holiday['DESCR'] ?? 'Holiday';
    }
}

/* =============================
   FINAL RESPONSE
============================= */

ksort($dateMap);

$response = array_values($dateMap);

/* Cache Response */
$output = json_encode([
    "status" => true,
    "data"   => $response
]);

file_put_contents($cacheFile, $output);

ob_clean();

/* Standard API Response */
apiResponse(true, "Attendance fetched successfully", $response, 200 );

} catch (Exception $e) {

    ob_clean();
    apiResponse(false, $e->getMessage(), null, 500);
}