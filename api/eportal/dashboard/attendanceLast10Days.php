<?php
ob_start();

/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ ."/../../config/utils.php";

header('Content-Type: application/json');

try {

	$empCode = $_SESSION['emp_code'] ?? null;
	if (!$empCode) {   
		apiResponse(false,"Unauthorized Access",null,401);
	}

    /* =============================
       CACHE (2 MIN)
    ============================== */
    $cacheFile = sys_get_temp_dir() . "/att10_" . $empCode . ".json";

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 120) {
        echo file_get_contents($cacheFile);
        exit;
    }

    /* RELEASE SESSION LOCK */
    session_write_close();

    /* =============================
       DATE RANGE
    ============================== */
    $dates = [];
    $dateMap = [];

    for ($i = 9; $i >= 0; $i--) {
        $d = date('d-M-Y', strtotime("-$i days"));

        $dates[] = $d;

        $dateMap[$d] = [
            "date" => date('d-M', strtotime($d)),
            "in" => '--:--',
            "out" => '--:--',
            "workingHrs" => 'Day Off',
            "onDesk" => '00:00',
            "offDesk" => '00:00',
            "terrace" => '00:00',
            "leaveType" => '--',
            "remarks" => ''
        ];
    }

    $dateList = "'" . implode("','", $dates) . "'";

    /* =============================
       1. TIME METRICS (SINGLE QUERY)
    ============================== */
    $startDate = date('d-M-Y', strtotime("-9 days"));

    $sql = "
    WITH dates AS (
        SELECT TO_DATE(:start_date, 'DD-MON-YYYY') + LEVEL - 1 AS dt
        FROM dual
        CONNECT BY LEVEL <= 10
    )
    SELECT 
        TO_CHAR(dt, 'DD-MON-YYYY') AS ason_date,

        NVL((
            SELECT TO_CHAR(
                TO_DATE(SUM(time_diff_sec), 'sssss'), 'hh24:mi'
            )
            FROM ept_bcs_attd_daily d
            WHERE d.emp_code = :empCode
            AND d.ason_date = TO_CHAR(dt, 'DD-MON-YYYY')
            AND d.machine_no IN (5,6,11)
        ), '00:00') AS onDesk,

        NVL(TO_CHAR(
            TO_DATE(EPT_GET_OFFDESK_TIME(:empCode, dt), 'sssss'),
        'hh24:mi'), '00:00') AS offDesk,

        NVL(TO_CHAR(
            TO_DATE(ept_get_terrace_time(:empCode, dt), 'sssss'),
        'hh24:mi'), '00:00') AS terrace

    FROM dates
    ORDER BY dt
    ";

    $stid = oci_parse($sql___func___con, $sql);
    oci_bind_by_name($stid, ":start_date", $startDate);
    oci_bind_by_name($stid, ":empCode", $empCode);
    oci_execute($stid);

    while ($row = oci_fetch_assoc($stid)) {
        $dateMap[$row['ASON_DATE']]['onDesk'] = $row['ONDESK'];
        $dateMap[$row['ASON_DATE']]['offDesk'] = $row['OFFDESK'];
        $dateMap[$row['ASON_DATE']]['terrace'] = $row['TERRACE'];
    }

    /* =============================
       2. PUNCH DATA (BULK)
    ============================== */
    $punchRows = multiRec("
        SELECT attd_date,
        to_char(IN_TIME,'HH24:MI:SS') IN_TIM,
        to_char(OUT_TIME,'HH24:MI:SS') OUT_TIM,
        WORK_HOUR
        FROM ept_bcs_attd_reg 
        WHERE emp_code='$empCode'
        AND attd_date IN ($dateList)
    ");
    
    foreach ($punchRows as $r) {
        $d = $r['attd_date'];

        $dateMap[$d]['in'] = $r['IN_TIM'] ?? '--:--';
        $dateMap[$d]['out'] = $r['OUT_TIM'] ?? '--:--';

        $dateMap[$d]['workingHrs'] = $r['WORK_HOUR']
            ? $r['WORK_HOUR'] . " HRS"
            : "Day Off";
    }

    /* =============================
       3. LEAVE (BULK)
    ============================== */
    $leaveRows = multiRec("
        SELECT LVE_CODE, NO_DAYS, REMARKS, lve_date_fr, lve_date_to
        FROM bcs_emp_leaves
        WHERE emp_code='$empCode'
    ");

    foreach ($dates as $d) {
        foreach ($leaveRows as $l) {
            if ($l['LVE_DATE_FR'] <= $d && $l['LVE_DATE_TO'] >= $d) {
                $dateMap[$d]['leaveType'] =
                    $l['LVE_CODE'] . '(' . number_format($l['NO_DAYS'], 1) . ')';
                $dateMap[$d]['remarks'] = $l['REMARKS'];
            }
        }
    }

    /* =============================
       4. HOLIDAYS (BULK)
    ============================== */
    $holidayRows = multiRec("
        SELECT hol_date, descr
        FROM ept_bcs_holidays 
        WHERE hol_grp = (
            SELECT hol_tblno
            FROM ept_bcs_employee  
            WHERE emp_code='$empCode'
        )
        AND hol_date IN ($dateList)
    ");

    foreach ($holidayRows as $h) {
        if ($dateMap[$h['HOL_DATE']]['leaveType'] === '--') {
            $dateMap[$h['HOL_DATE']]['leaveType'] = $h['DESCR'];
        }
    }

    /* =============================
       FINAL OUTPUT
    ============================== */
    $final = array_values($dateMap);

    $output = json_encode([
        "status" => true,
        "data" => $final
    ]);

    file_put_contents($cacheFile, $output);

    ob_clean();
    echo $output;

} catch (Exception $e) {

    ob_clean();

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
