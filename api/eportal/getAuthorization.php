<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once "gatepass/gp_head.php";

try {

$authTasksData = [];

$base_query = "
            SELECT distinct * 
                FROM (
                    SELECT eut.*
                        FROM EPT_USER_TASKS eut
                        INNER JOIN EPT_PROFILE_TASK ept ON eut.TASK_ID = ept.TASK_ID
                        WHERE eut.emp_code_for = '" . $empCode . "' AND eut.STATUS = 'O'
                        UNION
                    SELECT eut.*
                        FROM EPT_USER_TASKS eut
                        INNER JOIN EPT_PROFILE_TASK ept ON eut.TASK_ID = ept.TASK_ID
                        WHERE profile_id IN (SELECT profile_id FROM ept_emp_profile WHERE emp_code = '" . $empCode . "') 
                        AND emp_code_for IS NULL AND eut.STATUS = 'O'
                ) tasks";

                //ORDER BY task_id, 3, 13
$myTasksCounts = multiRec("
                SELECT 
                    t.TASK_ID,
                    etm.task_desc,
                    COUNT(*) as total
                FROM (" . $base_query . ") t
                INNER JOIN ept_task_master etm 
                   ON etm.id = t.TASK_ID
                GROUP BY 
                    t.TASK_ID,
                    etm.task_desc
                ORDER BY t.TASK_ID");

$overAllCnt = 0;

foreach($myTasksCounts as $res)
{
    $overAllCnt += (int)$res['TOTAL']; // cast to int for accurate math
	$authTasksData[]=[
        'TASK_ID'    => $res['TASK_ID'],
        'TASK_DESC'  => $res['TASK_DESC'],
        'TOTAL'      => $res['TOTAL'],
    ];
}          
/*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */
    if($authTasksData || !empty($authTasksData)) {
        apiResponse(
            true,
            "Authorization data fetched successfully.",
            [
                "success" => true,
                "taskscnt"   => $authTasksData,
                "SUBTOTAL" => $overAllCnt
            ]
        );
    } else {
        apiResponse(false, "Unable to fetch authorization duty data.", null, 404);
    }
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch authorization slice data.", null, 500);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}