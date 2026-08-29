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

try {

    $sanitizedCompIds = array_map('intval', $_SESSION['compId']);
    $sanitizedDivIds = array_map('intval', $_SESSION['divId']);
    $sanitizedDeptCodes = array_map('intval', $_SESSION['deptId']);
    if(isset($_SESSION['taskId'])) {
        $sanitizedTaskIds = array_map('intval', $_SESSION['taskId']);
        $taskIdsString = "'" . implode("','", $sanitizedTaskIds) . "'";
    } else {
        $taskIdsString = "";
    }
    $compIdsString = "'" . implode("','", $sanitizedCompIds) . "'";
    $divIdsString = "'" . implode("','", $sanitizedDivIds) . "'";
    $deptCodesString = "'" . implode("','", $sanitizedDeptCodes) . "'";
    
    $conditions = [];
    $conditionsDT = [];

    if (!empty($compIdsString)) {
        $conditions[] = "COMP_ID IN ($compIdsString)";
        $conditionsDT[] = " TA.COMP_ID IN ($compIdsString)";
    }

    if (!empty($divIdsString)) {
        $conditions[] = "DIVSN_ID IN ($divIdsString)";
        $conditionsDT[] = " TA.DIVSN_ID IN ($divIdsString)";
    }

    if (!empty($deptCodesString)) {
        $conditions[] = "DEPT_ID IN ($deptCodesString)";
        $conditionsDT[] = " TA.DEPT_ID IN ($deptCodesString)";
    }

    if (!empty($taskIdsString)) {
        $conditions[] = "TASK_ID IN ($taskIdsString)";
        $conditionsDT[] = " TA.TASK_ID IN ($taskIdsString)";
    }

    $additionalWhere = '';

    if (!empty($conditions)) {
        $additionalWhere = ' AND ' . implode(' AND ', $conditions);
    }

    if($empCode === '00152'){
        $mytasks_count = singRec("
            SELECT SUM(count) AS total_count
            FROM (
                SELECT COUNT(DISTINCT id) AS count
                FROM HR_USER_TASKS
                WHERE emp_code_for = '" . $empCode . "' AND STATUS = 'O' AND TASK_ID != '46'

                UNION ALL

                SELECT COUNT(DISTINCT id) AS count
                FROM HR_USER_TASKS
                WHERE emp_code_for IS NULL AND STATUS = 'O'
                $additionalWhere
            ) subquery
        ");
    } else {
         
        $mytasks_count = singRec("
            SELECT SUM(count) AS total_count
            FROM (
                SELECT COUNT(DISTINCT id) AS count
                FROM HR_USER_TASKS 
                WHERE emp_code_for = '" . $empCode . "' AND STATUS = 'O'

                UNION ALL
                
                SELECT COUNT(DISTINCT id) AS count
                FROM HR_USER_TASKS
                WHERE 
                emp_code_for IS NULL AND STATUS = 'O'
                $additionalWhere
            ) subquery
        ");
    }    
    
    $results['TOTAL_COUNT'] = $mytasks_count['TOTAL_COUNT'];
    
    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */
    $taskarr= array('J'=>'Joining' , 'E'=> 'Exit' , 'R'=>'Recruitment' , 'C'=> 'Others','T'=>'Tenure Change' ,'A' => 'Appraisal' , 'S' => 'Employee Transfer');

    $joining_taskarr = [4,13,35,36,37,38,39,41,42,43,44,45,50,51];
    $exit_task_ids   = ['18','19','20','23','24'];

    $taskGrpFilter  = !empty($data['TASK_GRP']) ? "AND TM.ID IN (" . $data['TASK_GRP'] . ")" : "";
    $exclude46      = "AND TA.TASK_ID != '46'";
    $is_special_exc = ($empCode=== '00152') ? $exclude46 : "";

    // print_r($taskarr);
    // print_r($joining_taskarr);
    // print_r($exit_task_ids);
    // print_r($taskGrpFilter);
    // print_r($exclude46);
    // print_r($is_special_exc);
    // exit;

    $additionalWhereDT = '';

    if (!empty($conditionsDT)) {
        $additionalWhereDT = implode(' AND ', $conditionsDT);
    }
    
    $distinct_tasks = multiRec("SELECT 
                    TASK_ID, DISP_SEQ, TASK_TYPE, COUNT(TASK_ID) AS CNT
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
                            AND TA.EMP_CODE_FOR IS NULL
                            AND TA.STATUS = 'O'
                            $taskGrpFilter $is_special_exc
                    ) tasks
                    GROUP BY TASK_ID, DISP_SEQ, TASK_TYPE 
                    ORDER BY DISP_SEQ, TASK_TYPE");

    if (empty($distinct_tasks)) {
        $task_master_map = [];
        $tasks_grouped   = [];
    } else {
        // echo "================";
        // print_r($distinct_tasks);
        // exit;
        $allTaskIds = array_column($distinct_tasks, 'TASK_ID');
        $taskIdsIn  = implode(',', array_map('intval', $allTaskIds));

        $task_master_map = [];
        foreach (multiRec("SELECT ID, TASK_DESC, TASK_GRP, PROG_URL FROM HR_TASK_MASTER WHERE ID IN ($taskIdsIn)") as $r) {
                $task_master_map[$r['ID']] = $r;
        }

        $joining_in = implode(',', $joining_taskarr);
        $exit_in    = implode(',', array_map('intval', $exit_task_ids));

        // echo '---------------';
        // print_r($allTaskIds);
        // print_r($taskIdsIn);
        // print_r($task_master_map);
        // print_r($joining_in);
        // print_r($exit_in);
        // exit;

        $all_rows = multiRec("SELECT tasks.*,
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
                    WHERE TA.EMP_CODE_FOR = '" . $empCode . "'
                    AND TA.STATUS = 'O'
                    AND TA.TASK_ID IN ($taskIdsIn)
                    $is_special_exc
                
                    UNION
                
                    SELECT TA.ID, TA.TASK_ID, TA.STATUS, TA.TRAN_CODE, TA.TRAN_DESC,
                            TA.EMP_CODE_FOR, TA.CREATED_BY, TA.CREATED_ON,
                            TA.COMP_ID, TA.DIVSN_ID, TA.DEPT_ID,
                            TA.AUTH_BY, TA.AUTH_ON, TA.REMARKS, TA.UDF_2,
                            TM.DISP_SEQ
                    FROM HR_USER_TASKS TA
                    INNER JOIN HR_TASK_MASTER TM ON TM.ID = TA.TASK_ID
                    WHERE TA.COMP_ID   IN ($compIdsString)
                    AND TA.DIVSN_ID  IN ($divIdsString)
                    AND TA.DEPT_ID   IN ($deptCodesString)
                    AND TA.TASK_ID   IN ($taskIdsIn)
                    AND TA.EMP_CODE_FOR IS NULL
                    AND TA.STATUS = 'O'
                    $is_special_exc
                ) tasks
                LEFT JOIN HR_EMPLOYEE_INFO EMP_CB
                    ON EMP_CB.EMP_CODE = tasks.CREATED_BY
                LEFT JOIN HR_EMP_SEPARATION SEP
                    ON SEP.ID = tasks.TRAN_CODE
                    AND tasks.TASK_ID IN ($exit_in)
                LEFT JOIN HR_EMP_OFFICE_DET OFF_EXIT
                    ON OFF_EXIT.EMP_CODE = SEP.EMP_CODE
                    AND SEP.RELEIVING_DATE BETWEEN OFF_EXIT.EFFEC_FROM AND NVL(OFF_EXIT.EFFEC_TO, DATE '3000-03-01')
                    AND tasks.TASK_ID IN ($exit_in)
                LEFT JOIN HR_EMP_OFFICE_DET OFF_JOIN
                    ON OFF_JOIN.EMP_CODE = tasks.TRAN_CODE
                    AND SYSDATE BETWEEN OFF_JOIN.EFFEC_FROM AND NVL(OFF_JOIN.EFFEC_TO, DATE '3000-03-01')
                    AND tasks.TASK_ID IN ($joining_in)
                LEFT JOIN HR_RECRUITMENT REC
                    ON REC.ID = tasks.TRAN_CODE
                    AND tasks.TASK_ID IN (2,6)
                LEFT JOIN HR_ORGANOGRAM_LOC LOC
                    ON LOC.ID = REC.ORG_LOC_ID
                    AND tasks.TASK_ID IN (2,6)
                ORDER BY tasks.DISP_SEQ, tasks.CREATED_ON");

        $tasks_grouped = [];
        foreach ($all_rows as $row) {
            $tasks_grouped[$row['TASK_ID']][] = $row;
        }
    }

    //echo '########################';
    //print_r($all_rows);
    // print_r($tasks_grouped);
    // exit;
    $tasktype = null;
    // $finalArr = [];
    // foreach ($distinct_tasks as $task_id){
    //     $tid       = (string)$task_id['TASK_ID'];
    //     $task_desc = $task_master_map[$tid] ?? [];
    //     $mytasks   = $tasks_grouped[$tid]   ?? [];

    //     if ($task_id['TASK_TYPE'] !== $tasktype){
    //         $finalArr[$taskarr[$task_id['TASK_TYPE']]][] = htmlspecialchars($task_desc['TASK_DESC'] ?? '') . ' ( ' . $task_id['CNT'] . ' )';
    //     }
    // }

    // print_r($finalArr);
    // exit;
    $finalArr = [];
    foreach ($distinct_tasks as $task_id) {
        $tid       = (string)$task_id['TASK_ID'];
        $task_desc = $task_master_map[$tid] ?? [];

        if ($task_id['TASK_TYPE'] !== $tasktype) {
            $groupLabel = $taskarr[$task_id['TASK_TYPE']];

            $finalArr[$groupLabel][] = [
                'TASK_ID'   => $tid,
                'TASK_DESC' => htmlspecialchars($task_desc['TASK_DESC'] ?? ''),
                'CNT'       => (int)$task_id['CNT'],
            ];
        }
    }

    // echo json_encode($finalArr);
    // exit;
    $results = $finalArr;
    if($results || !empty($results)) {
        apiResponse(
            true,
            "Authorization data fetched successfully.",
            [
                "success" => true,
                "taskscnt"   => $results
            ]
        );
    } else {
        apiResponse(false, "Unable to fetch authorization duty data.", null, 200);
    }
    
    apiResponse(true, "Authorization cound loaded successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getCapabilitiesList.php"
    );

    apiResponse(false, "Unable to load capabilities.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
