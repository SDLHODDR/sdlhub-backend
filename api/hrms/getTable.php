<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true) ?? [];

try {
    $authTasksData = [];
    $taskIdd = isset($data['task_id']) ? (int) $data['task_id'] : 0;

    // Safely parse session values
    $rawComp = is_array($_SESSION['compId'] ?? null) ? $_SESSION['compId'] : [];
    $rawDiv  = is_array($_SESSION['divId'] ?? null) ? $_SESSION['divId'] : [];
    $rawDept = is_array($_SESSION['deptId'] ?? null) ? $_SESSION['deptId'] : [];
    $rawTask = is_array($_SESSION['taskId'] ?? null) ? $_SESSION['taskId'] : [];

    $sanitizedCompIds   = array_values(array_filter(array_map('intval', $rawComp)));
    $sanitizedDivIds    = array_values(array_filter(array_map('intval', $rawDiv)));
    $sanitizedDeptCodes = array_values(array_filter(array_map('intval', $rawDept)));
    $sanitizedTaskIds   = array_values(array_filter(array_map('intval', $rawTask)));

    // Ensure the current task_id is authorized
    if ($taskIdd > 0 && !in_array($taskIdd, $sanitizedTaskIds, true)) {
        $sanitizedTaskIds[] = $taskIdd;
    }

    $compIdsString   = !empty($sanitizedCompIds) ? "'" . implode("','", $sanitizedCompIds) . "'" : "'-1'";
    $divIdsString    = !empty($sanitizedDivIds) ? "'" . implode("','", $sanitizedDivIds) . "'" : "'-1'";
    $deptCodesString = !empty($sanitizedDeptCodes) ? "'" . implode("','", $sanitizedDeptCodes) . "'" : "'-1'";
    $taskIdsString   = !empty($sanitizedTaskIds) ? "'" . implode("','", $sanitizedTaskIds) . "'" : "'-1'";

    $conditionsDT = [];
    if (!empty($sanitizedCompIds)) {
        $conditionsDT[] = " TA.COMP_ID IN ($compIdsString)";
    }
    if (!empty($sanitizedDivIds)) {
        $conditionsDT[] = " TA.DIVSN_ID IN ($divIdsString)";
    }
    if (!empty($sanitizedDeptCodes)) {
        $conditionsDT[] = " TA.DEPT_ID IN ($deptCodesString)";
    }
    if (!empty($sanitizedTaskIds)) {
        $conditionsDT[] = " TA.TASK_ID IN ($taskIdsString)";
    }

    $additionalWhereDT = !empty($conditionsDT) ? implode(' AND ', $conditionsDT) : '1=1';

    $joining_taskarr = [4, 13, 35, 36, 37, 38, 39, 41, 42, 43, 44, 45, 50, 51, 55];
    $exit_task_ids   = ['18', '19', '20', '23', '24'];

    $taskGrpFilter  = !empty($data['TASK_GRP']) ? "AND TM.ID IN (" . intval($data['TASK_GRP']) . ")" : "";
    $exclude46      = "AND TA.TASK_ID != '46'";
    $is_special_exc = ($empCode === '00152') ? $exclude46 : "";

    $authTasksData['exit_task_ids']   = $exit_task_ids;
    $authTasksData['joining_taskarr'] = $joining_taskarr;

    // 1. DISTINCT TASKS
    $distinct_tasks = multiRec("
        SELECT 
            TASK_ID, DISP_SEQ, TASK_TYPE, COUNT(TASK_ID) AS CNT
        FROM (
            SELECT TA.TASK_ID, TM.DISP_SEQ, TM.TASK_TYPE
            FROM HR_USER_TASKS TA
            INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
            WHERE TA.EMP_CODE_FOR = '{$empCode}'
              AND TA.STATUS = 'O'
              {$taskGrpFilter} {$is_special_exc}

            UNION ALL

            SELECT TA.TASK_ID, TM.DISP_SEQ, TM.TASK_TYPE
            FROM HR_USER_TASKS TA
            INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
            WHERE {$additionalWhereDT}
              AND (TA.EMP_CODE_FOR IS NULL OR TA.TASK_ID = {$taskIdd})
              AND TA.STATUS = 'O'
              {$taskGrpFilter} {$is_special_exc}
        ) tasks
        GROUP BY TASK_ID, DISP_SEQ, TASK_TYPE 
        ORDER BY DISP_SEQ, TASK_TYPE
    ");

    $tasks_grouped = [];

    if (!empty($distinct_tasks)) {
        $allTaskIds = array_column($distinct_tasks, 'TASK_ID');
        if ($taskIdd > 0 && !in_array($taskIdd, $allTaskIds)) {
            $allTaskIds[] = $taskIdd;
        }
        $taskIdsIn = implode(',', array_map('intval', array_unique($allTaskIds)));

        $joining_in = implode(',', $joining_taskarr);
        $exit_in    = implode(',', array_map('intval', $exit_task_ids));

        // 2. DETAILED ROWS
        $all_rows = multiRec("
            SELECT tasks.*,
                INITCAP(LOWER(EMP_CB.FNAME || ' ' || EMP_CB.LNAME)) AS CREATED_BY_NAME,
                GET_SHCOMP_NAME(tasks.COMP_ID)    AS CNAME,
                GET_DIVISION_NAME(tasks.DIVSN_ID) AS DIVSN,
                GET_DEPT_NAME(tasks.DEPT_ID)      AS DNAME,
                SEP.EMP_CODE                               AS SEP_EMP_CODE,
                TO_CHAR(SEP.RELEIVING_DATE,'DD-Mon-YYYY')  AS SEP_REL_DATE,
                GET_DEPT_NAME(OFF_EXIT.DEPT_ID)            AS EXIT_DEPT,
                GET_DESIGN_NAME(OFF_EXIT.DESI_ID)          AS EXIT_DESIG,
                GET_ORG_LOC_NAME(OFF_EXIT.ORG_LOC_ID)      AS EXIT_LOC,
                GET_DESIGN_NAME(OFF_JOIN.DESI_ID)          AS JOIN_DESIG,
                GET_ORG_LOC_NAME(OFF_JOIN.ORG_LOC_ID)      AS JOIN_LOC,
                LOC.GEO_DESC                               AS REQ_LOC_DESC
            FROM (
                SELECT TA.ID, TA.TASK_ID, TA.STATUS, TA.TRAN_CODE, TA.TRAN_DESC, 
                       TA.EMP_CODE_FOR, TA.CREATED_BY, TA.CREATED_ON,
                       TA.COMP_ID, TA.DIVSN_ID, TA.DEPT_ID,
                       TA.AUTH_BY, TA.AUTH_ON, TA.REMARKS, TA.UDF_2,
                       TM.DISP_SEQ
                FROM HR_USER_TASKS TA
                INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
                WHERE TA.EMP_CODE_FOR = '{$empCode}'
                  AND TA.STATUS = 'O'
                  AND TA.TASK_ID IN ({$taskIdsIn})
                  {$is_special_exc}
            
                UNION
            
                SELECT TA.ID, TA.TASK_ID, TA.STATUS, TA.TRAN_CODE, TA.TRAN_DESC,  
                       TA.EMP_CODE_FOR, TA.CREATED_BY, TA.CREATED_ON,
                       TA.COMP_ID, TA.DIVSN_ID, TA.DEPT_ID,
                       TA.AUTH_BY, TA.AUTH_ON, TA.REMARKS, TA.UDF_2,
                       TM.DISP_SEQ
                FROM HR_USER_TASKS TA
                INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
                WHERE TA.COMP_ID   IN ({$compIdsString})
                  AND TA.DIVSN_ID  IN ({$divIdsString})
                  AND TA.DEPT_ID   IN ({$deptCodesString})
                  AND TA.TASK_ID   IN ({$taskIdsIn})
                  AND (TA.EMP_CODE_FOR IS NULL OR TA.TASK_ID = {$taskIdd})
                  AND TA.STATUS = 'O'
                  {$is_special_exc}
            ) tasks
            LEFT JOIN HR_EMPLOYEE_INFO EMP_CB
                ON EMP_CB.EMP_CODE = tasks.CREATED_BY
            LEFT JOIN HR_EMP_SEPARATION SEP
                ON SEP.ID = tasks.TRAN_CODE
                AND tasks.TASK_ID IN ({$exit_in})
            LEFT JOIN HR_EMP_OFFICE_DET OFF_EXIT
                ON OFF_EXIT.EMP_CODE = SEP.EMP_CODE
                AND SEP.RELEIVING_DATE BETWEEN OFF_EXIT.EFFEC_FROM AND NVL(OFF_EXIT.EFFEC_TO, DATE '3000-03-01')
                AND tasks.TASK_ID IN ({$exit_in})
            LEFT JOIN HR_EMP_OFFICE_DET OFF_JOIN
                ON OFF_JOIN.EMP_CODE = tasks.TRAN_CODE
                AND SYSDATE BETWEEN OFF_JOIN.EFFEC_FROM AND NVL(OFF_JOIN.EFFEC_TO, DATE '3000-03-01')
                AND tasks.TASK_ID IN ({$joining_in})
            LEFT JOIN HR_RECRUITMENT REC
                ON REC.ID = tasks.TRAN_CODE
                AND tasks.TASK_ID IN (2,6)
            LEFT JOIN HR_ORGANOGRAM_LOC LOC
                ON LOC.ID = REC.ORG_LOC_ID
                AND tasks.TASK_ID IN (2,6)
            ORDER BY tasks.DISP_SEQ, tasks.CREATED_ON
        ");

        if (!empty($all_rows)) {
            foreach ($all_rows as $row) {
                $tasks_grouped[$row['TASK_ID']][] = $row;
            }
        }
    }

    // Direct mapping to the requested taskIdd
    $authTasksData['mytask'] = $tasks_grouped[$taskIdd] ?? [];

    apiResponse(true, "Authorization data loaded successfully.", $authTasksData);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine(),
        ],
        "getTable.php"
    );

    apiResponse(false, "Unable to load capabilities: " . $e->getMessage(), null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}