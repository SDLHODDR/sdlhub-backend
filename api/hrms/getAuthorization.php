<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

try {
    $sanitizedCompIds   = array_map('intval', $_SESSION['compId'] ?? []);
    $sanitizedDivIds    = array_map('intval', $_SESSION['divId'] ?? []);
    $sanitizedDeptCodes = array_map('intval', $_SESSION['deptId'] ?? []);
    
    $compIdsString   = !empty($sanitizedCompIds) ? implode(',', $sanitizedCompIds) : "";
    $divIdsString    = !empty($sanitizedDivIds) ? implode(',', $sanitizedDivIds) : "";
    $deptCodesString = !empty($sanitizedDeptCodes) ? implode(',', $sanitizedDeptCodes) : "";
    
    if (isset($_SESSION['taskId']) && !empty($_SESSION['taskId'])) {
        $sanitizedTaskIds = array_map('intval', $_SESSION['taskId']);
        $taskIdsString = implode(',', $sanitizedTaskIds);
    } else {
        $taskIdsString = "";
    }

    $conditionsDT = [];

    if (!empty($compIdsString)) {
        $conditionsDT[] = "TA.COMP_ID IN ($compIdsString)";
    }
    if (!empty($divIdsString)) {
        $conditionsDT[] = "TA.DIVSN_ID IN ($divIdsString)";
    }
    if (!empty($deptCodesString)) {
        $conditionsDT[] = "TA.DEPT_ID IN ($deptCodesString)";
    }
    if (!empty($taskIdsString)) {
        $taskIdsString .= ",'56'"; //Temporary arrangements should come from profile access
        $conditionsDT[] = "TA.TASK_ID IN ($taskIdsString)";
    }

    $additionalWhereDT = !empty($conditionsDT) ? implode(' AND ', $conditionsDT) . ' AND ' : '';

    $taskarr = [
        'J' => 'Joining',
        'E' => 'Exit',
        'R' => 'Recruitment',
        'C' => 'Others',
        'T' => 'Tenure Change',
        'A' => 'Appraisal',
        'S' => 'Employee Transfer',
        'M' => 'Masters'
    ];

    $taskGrpFilter  = !empty($data['TASK_GRP']) ? "AND TM.ID IN (" . intval($data['TASK_GRP']) . ")" : "";
    $exclude46      = "AND TA.TASK_ID != '46'";
    $is_special_exc = ($empCode === '00152') ? $exclude46 : "";

    /* ==========================================================
       1. DISTINCT TASK COUNTS PER TASK_ID
    ========================================================== */
    $distinct_tasks = multiRec("
        SELECT 
            tasks.TASK_ID, 
            tasks.DISP_SEQ, 
            tasks.TASK_TYPE, 
            COUNT(tasks.TASK_ID) AS CNT
        FROM (
            SELECT TA.TASK_ID, TM.DISP_SEQ, TM.TASK_TYPE
            FROM HR_USER_TASKS TA
            INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
            WHERE TA.EMP_CODE_FOR = '" . $empCode . "'
              AND TA.STATUS = 'O'
              $taskGrpFilter $is_special_exc

            UNION ALL

            SELECT TA.TASK_ID, TM.DISP_SEQ, TM.TASK_TYPE
            FROM HR_USER_TASKS TA
            INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
            WHERE $additionalWhereDT
                  TA.EMP_CODE_FOR IS NULL
              AND TA.STATUS = 'O'
              $taskGrpFilter $is_special_exc
        ) tasks
        GROUP BY tasks.TASK_ID, tasks.DISP_SEQ, tasks.TASK_TYPE 
        ORDER BY tasks.DISP_SEQ, tasks.TASK_TYPE
    ");

    $finalArr = [];

    if (!empty($distinct_tasks)) {
        $allTaskIds = array_column($distinct_tasks, 'TASK_ID');
        $taskIdsIn  = implode(',', array_map('intval', $allTaskIds));

        $task_master_map = [];
        $taskMasterRows = multiRec("SELECT ID, TASK_DESC, TASK_GRP, PROG_URL FROM HR_TASK_MASTER WHERE ID IN ($taskIdsIn)");
        foreach ($taskMasterRows as $r) {
            $task_master_map[$r['ID']] = $r;
        }

        foreach ($distinct_tasks as $task_id) {
            $tid       = (string)$task_id['TASK_ID'];
            $task_desc = $task_master_map[$tid] ?? [];
            $taskType  = $task_id['TASK_TYPE'] ?? '';

            if (isset($taskarr[$taskType]) && $taskarr[$taskType] !== '') {
                $groupLabel = $taskarr[$taskType];

                $finalArr[$groupLabel][] = [
                    'TASK_ID'   => $tid,
                    'TASK_DESC' => htmlspecialchars($task_desc['TASK_DESC'] ?? ''),
                    'CNT'       => (int)$task_id['CNT'],
                ];
            }
        }
    }

    if (!empty($finalArr)) {
        apiResponse(
            true,
            "Authorization data fetched successfully.",
            [
                "success"  => true,
                "taskscnt" => $finalArr
            ]
        );
    } else {
        apiResponse(false, "No pending authorization tasks found.", ["taskscnt" => []], 200);
    }

} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine(),
        ],
        "getAuthorizationCount.php"
    );

    apiResponse(false, "Unable to load authorization counts.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}