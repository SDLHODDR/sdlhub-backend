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

    if (!empty($compIdsString)) {
        $conditions[] = "COMP_ID IN ($compIdsString)";
    }

    if (!empty($divIdsString)) {
        $conditions[] = "DIVSN_ID IN ($divIdsString)";
    }

    if (!empty($deptCodesString)) {
        $conditions[] = "DEPT_ID IN ($deptCodesString)";
    }

    if (!empty($taskIdsString)) {
        $conditions[] = "TASK_ID IN ($taskIdsString)";
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
