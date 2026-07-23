<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "tb_head.php";
// $decodeOT = [
//     'OD' => 'Out For Full Day' ,
//     'OI' => 'In/Out Same Day', 
//     'FO' => 'First Half Out',
//     'SO' => 'Second Half Out',
//     'FW' => 'Field Work',
// 	'TO' => 'Tour'
// ];
if($data['authForm']==true)
{
    startQry();

     $data["AUTH_REMARKS"] = isset($data["AUTH_REMARKS"]) && $data["AUTH_REMARKS"] !== ''
            ? $data["AUTH_REMARKS"]
            : null;
    
    if($data['flag']=='R')
	{
        //$userTask = singRec("select * from ept_user_tasks where task_id='346' and id = " . $data["TID"] . "");
        $userTask = singRec("select * from ept_user_tasks where task_id='346' and id = " . $data["TASK_ID"] . "");
        
        taskUpdate('C', $data["AUTH_REMARKS"], $userTask['ID']); 

        executeQry("update EPT_bcs_trvltkt_request set STATUS='R' , auth_by='".$empCode."', auth_on=sysdate where ID = " . $userTask["TRAN_CODE"] . " ");

        // echo json_encode([
        //     "status" => true,
        //     "status_code" => 200,
        //     "message" => "Record Rejected successfully"
        // ]);
        endQry('Task Rejected');
        apiResponse(true,"Record Rejected successfully");

		
    } else if($data['flag']=='A') {
        //$userTask = singRec("select * from ept_user_tasks where task_id='346' and id = " . $data["TID"] . "");
        $userTask = singRec("select * from ept_user_tasks where task_id='346' and id = " . $data["TASK_ID"] . "");
        
        taskUpdate('C', $data["AUTH_REMARKS"], $userTask['ID']); 

        $individual_taks_dets = singRec("select a.* , 'Ticket from ' || a.trvl_from_loc || ' To ' || a.trvl_to_loc || ' Dated ' || a.trvl_date as newtaskdets from EPT_bcs_trvltkt_request a where id= '".$userTask['TRAN_CODE']."'");

        $sitecode = singRec("select PAY_SITE from EPT_bcs_employee where emp_code='".$individual_taks_dets['EMP_CODE']."'");

        $task_id=executeQry("insert into EPT_bcs_user_tasks  
          (ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values 
          (null, '347', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$userTask['TRAN_CODE']."', null, 'A', null, '".$individual_taks_dets['NEWTASKDETS']."', '".(($sitecode['PAY_SITE']) ? $sitecode['PAY_SITE'] : $_SESSION['ept']['eptSiteCode']) ."', null, sysdate, '', '".$userTask['TASK_GRP_DESC']."', '')");

          executeQry("update EPT_bcs_trvltkt_request set STATUS='A' , auth_by='".$empCode."', auth_on=sysdate where id= '".$userTask['TRAN_CODE']."' ");

        endQry('Task Approved');
        // echo json_encode([
        //     "status" => true,
        //     "status_code" => 200,
        //     "message" => "Record Authroized successfully"
        // ]);
        apiResponse(true,"Record Authroized successfully");
    }
}

ob_end_flush();
