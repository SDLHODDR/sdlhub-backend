<?php
//  ini_set('display_errors', 1);
//  error_reporting(E_ALL);

require_once "gatepass/gp_head.php";

$authTasksData = [];

$taskId = $data['task_id'];

$base_query = "
            SELECT distinct * 
                FROM (
                    SELECT eut.*
                        FROM EPT_USER_TASKS eut
                        INNER JOIN EPT_PROFILE_TASK ept ON eut.TASK_ID = ept.TASK_ID
                        WHERE eut.emp_code_for = '" . $empCode . "' AND eut.STATUS = 'O'
                        UNION ALL
                    SELECT eut.*
                        FROM EPT_USER_TASKS eut
                        INNER JOIN EPT_PROFILE_TASK ept ON eut.TASK_ID = ept.TASK_ID
                        WHERE profile_id IN (SELECT profile_id FROM ept_emp_profile WHERE emp_code = '" . $empCode . "') 
                        AND emp_code_for IS NULL AND eut.STATUS = 'O'
                ) tasks";

                //ORDER BY task_id, 3, 13
$myTasksData = multiRec("
                SELECT 
                    t.*, etm.task_desc as TASK_DESC
                FROM (" . $base_query . ") t
                INNER JOIN ept_task_master etm 
                   ON etm.id = t.TASK_ID
                WHERE t.TASK_ID = " . $taskId . "
                ORDER BY t.CREATED_ON DESC");



foreach($myTasksData as $res)
{
    // $details = null;
    if (!empty($res['TRAN_CODE'])) {

        //346 → Ticket Booking
        if ($taskId == 346) {
            // $details = singRec("
            //     SELECT REQ_DATE, ID, SITE_CODE, TRVL_CLASS, TRVL_EMP, EMP_CODE, PERSON_NAME,
            //         decode(TRVL_MODE , 'F' , 'Flight' , 'T' , 'Train' , 'B' , 'Bus') AS TRVL_MODE,
            //         TRVL_DATE, TRVL_FROM_LOC, TRVL_TO_LOC, TRVL_FT_NAME, TRVL_FT_NO, EVENT_ID, 
            //         to_char(TTNT_DEPR_TIME , 'hh24:mi') AS TTNT_DEPR_TIME,
            //         to_char(TTNT_ARVL_TIME , 'hh24:mi') AS TTNT_ARVL_TIME,
            //         REMARKS, STATUS, TRVL_TKT_ID
            //     FROM EPT_BCS_TRVLTKT_REQUEST
            //         WHERE REQ_BY = '".$empCode."'
            //         AND ID = '".$res['TRAN_CODE']."'
            // ");
             $details = singRec("
                SELECT REQ_DATE, ID, SITE_CODE, TRVL_CLASS, TRVL_EMP, EMP_CODE, PERSON_NAME,
                    decode(TRVL_MODE , 'F' , 'Flight' , 'T' , 'Train' , 'B' , 'Bus') AS TRVL_MODE,
                    TRVL_DATE, TRVL_FROM_LOC, TRVL_TO_LOC, TRVL_FT_NAME, TRVL_FT_NO, EVENT_ID, 
                    to_char(TTNT_DEPR_TIME , 'hh24:mi') AS TTNT_DEPR_TIME,
                    to_char(TTNT_ARVL_TIME , 'hh24:mi') AS TTNT_ARVL_TIME,
                    REMARKS, STATUS, TRVL_TKT_ID
                FROM EPT_BCS_TRVLTKT_REQUEST
                    WHERE ID = '".$res['TRAN_CODE']."'
            ");
        }

        //349 → Outdoor
        else if ($taskId == 349) {
            $details = singRec("
                SELECT *
                    FROM EPT_EMPLOYEE_GPASS
                        WHERE ID = '".$res['TRAN_CODE']."'
            ");
        }

        // //109 → Leave
        else if ($taskId == 109) {
            // $details = singRec("
            //     SELECT * 
            //         FROM EPT_BCS_EMP_LEAVES_TEMP
            //             WHERE EMP_CODE = '".$empCode."'
            //                 AND ID = '".$res['TRAN_CODE']."'
            // ");
            $details = singRec("
                SELECT * 
                    FROM EPT_BCS_EMP_LEAVES_TEMP
                        WHERE ID = '".$res['TRAN_CODE']."'
            ");
        }

        //357 -> Conference Room
        else if ($taskId == 357) {
        
            //echo "\n" . $taskId . " - " . $res['TRAN_CODE'];
            $details = singRec( 
                "select * from 
                    ept_conf_room_tran 
                    where status in ('T') 
                    and id = '" . $res['TRAN_CODE'] . "'
            ");
            
        }
    }

    $requestFor = "";
    if(($details || !empty($details) || count($details) > 0 || isset($details['EMP_CODE'])) && $taskId == 109){
       $requestFor = ucwords(getEmpInfoByCode($details['EMP_CODE'])); 
    } else if($taskId == 349) {
       $requestType = ''; 
       $requestFor = $res['TASK_GRP_DESC'];
        if (str_starts_with($requestFor, 'POSTREMARKS~')) {
            //$requestFor = substr($requestFor, strlen('POSTREMARKS~'));
            $parts = explode('~', $requestFor, 2);
            $requestType = $parts[0] ?? '';           // "POSTREMARKS"
            $requestFor  = $parts[1] ?? $requestFor;  // "Tanvi Kochrekar"
        }
    } else {
       $requestFor = $res['TASK_GRP_DESC'];
    }
    //echo $res['TASK_GRP_DESC'] . "====" . $requestFor; exit;

     $authTasksData[] = [
        'ID'           => $res['ID'],
        'TASK_DESC'    => $res['TASK_DESC'],
        'TRAN_CODE'    => $res['TRAN_CODE'],
        'TASK_ID'      => $res['TASK_ID'],
        'CREATED_BY'   => ucwords(getEmpInfoByCode($res['CREATED_BY'])),
        'CREATED_ON'   => $res['CREATED_ON'],
        'SITE_CODE'    => $res['SITE_CODE'],
        'STATUS'       => $res['STATUS'],
        "statusColor" => $statusAuthColorMap[$res['STATUS']] ?? "secondary",
        "statusText" => $statusAuthTextMap[$res['STATUS']] ?? "Open",
        'REQUEST_FOR'  => $requestFor,
        'REQUEST_TYPE'  => $requestType,
        'DETAILS'      => $details
    ];
}         

echo json_encode([
    "status" => true,
    "success" => true,
    "tasks"   => $authTasksData
]);