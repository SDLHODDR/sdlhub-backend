<?php

require_once "tb_head.php";


if($data['saveTbrData']==true)
{
    startQry();
   
    if($data['TRVL_EMP']=='E'){
        $pn = singRec("select epplive.get_emp_name('".$data['EMP_CODE']."')pn from dual");
    }
    else {
        $pn['PN'] = $data['PERSON_NAME'];
        $data['EMP_CODE'] = null;
    }
    if($data['TTNT_ARVL_TIME'] < $data['TTNT_DEPR_TIME']){
        $arr_date= date('d-M-Y', strtotime($data['TRVL_DATE']. ' + 1 days'));
        echo json_encode([
            "status" => false,
            "task_id" => null,
            "status_code" => 200,
            "data" => $arr_date,
            "message" => "Arrival Date"
        ]);
    }
    else {
        $arr_date=$data['TRVL_DATE'];
    }    
    $insert_id=executeQry("INSERT INTO epplive.BCS_TRVLTKT_REQUEST
    (ID, REQ_DATE, REQ_BY, SITE_CODE, TRVL_EMP, EMP_CODE, PERSON_NAME, TRVL_MODE, TRVL_CLASS , TRVL_DATE, TRVL_FROM_LOC, TRVL_TO_LOC, TRVL_FT_NAME, TRVL_FT_NO ,EVENT_ID, TTNT_DEPR_TIME, TTNT_ARVL_TIME, REMARKS, STATUS, CHG_ON, CHG_BY)
        values( 													
            null, 
            sysdate, 
            '".$empCode."', 
            '".$_SESSION['eptSiteCode']."', 
            '".$data['TRVL_EMP']."', 
            '".$data['EMP_CODE']."', 
            '".$pn['PN']."',
            '".$data['TRVL_MODE']."', 
            '".$data['TRVL_CLASS']."', 
            '".$data['TRVL_DATE']."', 
            '".$data['TRVL_FROM_LOC']."', 
            '".$data['TRVL_TO_LOC']."', 
            '".$data['TRVL_FT_NAME']."', 
            '".$data['TRVL_FT_NO']."', 
            null,
            to_date('".$data['TRVL_DATE'].' '.$data['TTNT_DEPR_TIME']."','dd-Mon-yyyy hh24:mi'),
            to_date('".$arr_date.' '.$data['TTNT_ARVL_TIME']."','dd-Mon-yyyy hh24:mi'),
            '".str_replace("'", "''", $data['REMARKS'])."', 
            'N', 
            sysdate, 
            '".$empCode."') 
            returning ID into :newId",'newId');
    
    if($insert_id) {
        if($data['withAuth']==true){
            $person = singRec("select * from epplive.bcs_trvltkt_request where id='".$insert_id."'");
            if($person['TRVL_EMP']=='E'){
                $pn = singRec("select epplive.get_emp_name('".$person['EMP_CODE']."')pn from dual");
                $name = singRec("SELECT epplive.hr_get_emp_mgr('".$person['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
                $name1 = findParentOrgEmp($person['EMP_CODE']);        
                $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
                
                $sitecode = singRec("select pay_site from epplive.bcs_employee where emp_code='".$person['EMP_CODE']."'");
            } else {
                $pn['PN'] = $person['PERSON_NAME'];
                $sitecode = $_SESSION['eptSiteCode'];
                $name = singRec("SELECT epplive.hr_get_emp_mgr('".$_SESSION['eptSiteCode']."',SYSDATE)EMP_CODE FROM DUAL");
                $name1 = findParentOrgEmp($_SESSION['eptSiteCode']);        
                $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;
            }

            $task_id=executeQry("insert into EPT_USER_TASKS (
                ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (null, '346', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$insert_id."', null, 'A', null, 'Ticket Request From ".$person['TRVL_FROM_LOC']." To ".$person['TRVL_TO_LOC']." Dated ".$person['TRVL_DATE']."', '".$sitecode['PAY_SITE']."', '', sysdate, '', '".$person['PERSON_NAME']."', '') returning ID into :taskId" ,'taskId');

            executeQry("update epplive.BCS_TRVLTKT_REQUEST set status='T' where id='".$insert_id."'") ;
            
            if($task_id){
                echo json_encode([
                    "status" => true,
                    "status_code" => 200,
                    "booking_id" => $insert_id,
                    "task_id" => $task_id,
                    "message" => "Request sent for Authorization"
                ]);
                endQry("Sent for Authorization!");
            }
            else{
                echo json_encode([
                    "status" => false,
                    "status_code" => 500,
                    "message" => "Some Error occured"
                ]);
            }
        } else {
            echo json_encode([
                "status" => true,
                "task_id" => $insert_id,
                "status_code" => 200,
                "message" => "Ticket booking generated successfully"
            ]);
        }
        
    } else {
        echo json_encode([
            "status" => false,
            "status_code" => 500,
            "message" => "Some Error occured"
        ]);
    }
    endQry();
    //}
} else if($data['editTbrData']==true)
{
    startQry();
    
    if($data['TRVL_EMP']=='E'){
        $pn = singRec("select epplive.get_emp_name('".$data['EMP_CODE']."') PN from dual");
    }
    else {
        $pn['PN'] = $data['PERSON_NAME'];
        $data['EMP_CODE'] = null;
    }
    if($data['TTNT_ARVL_TIME'] < $data['TTNT_DEPR_TIME']){
        $arr_date= date('d-M-Y', strtotime($data['TRVL_DATE']. ' + 1 days'));
        // echo json_encode([
        //     "status" => false,
        //     "task_id" => null,
        //     "status_code" => 200,
        //     "data" => $arr_date,
        //     "message" => "Arrival Date"
        // ]);
    }
    else {
        $arr_date=$data['TRVL_DATE'];
    }

    $qry = "update epplive.bcs_trvltkt_request
    set 													
    SITE_CODE='".$_SESSION['eptSiteCode']."', 
    TRVL_EMP='".$data['TRVL_EMP']."', 
    EMP_CODE='".$data['EMP_CODE']."', 
    PERSON_NAME='".$pn['PN']."',
    TRVL_MODE='".$data['TRVL_MODE']."', 
    TRVL_CLASS='".$data['TRVL_CLASS']."', 
    TRVL_DATE=to_date('".$data['TRVL_DATE']."','dd-Mon-yyyy'), 
    TRVL_FROM_LOC='".$data['TRVL_FROM_LOC']."', 
    TRVL_TO_LOC='".$data['TRVL_TO_LOC']."', 
    TRVL_FT_NAME='".$data['TRVL_FT_NAME']."', 
    TRVL_FT_NO='".$data['TRVL_FT_NO']."', 
    TTNT_DEPR_TIME=to_date('".$data['TRVL_DATE'].' '.$data['TTNT_DEPR_TIME']."','dd-Mon-yyyy hh24:mi'),
    TTNT_ARVL_TIME=to_date('".$arr_date.' '.$data['TTNT_ARVL_TIME']."','dd-Mon-yyyy hh24:mi'),
    REMARKS='".str_replace("'", "''", $data['REMARKS'])."',
    STATUS='N'
    where ID='".$data['ID']."'";

    try {
        $ok = executeQry($qry); 
        echo json_encode([
            "status" => true,
            "status_code" => 200,
            "message" => "Ticking booking updated successfully"
        ]);
    } catch(Exception $e){

        echo json_encode([
            "status" => false,
            "status_code" => 500,
            "message" => "Failed to update ticket booking " . $e->getMessage()
        ]);

    }
    
    endQry();
} else if($data['deleteTB']==true)
{
    startQry();
    if($data["delteId"]) {
        executeQry("DELETE FROM epplive.bcs_trvltkt_request WHERE ID in (".$data['delteId'].")");
    }
    echo json_encode([
        "status" => true,
        "status_code" => 200,
        "message" => "Ticket deleted successfully"
    ]);
    endQry();
} else if($data['closeTicket']==true)
{
  startQry();
  if($data['ID'] != "") {
    executeQry("update epplive.BCS_TRVLTKT_REQUEST set status='X' where id='".$data['ID']."' ");

    $tickdets = singRec("select a.*, decode(a.TRVL_MODE, 'F', 'Flight', 'T', 'Train', 'B', 'Bus')TRVLMODE, to_char(a.TTNT_DEPR_TIME, 'hh24:mi')TTNT_DEPR_TIME, to_char(a.TTNT_ARVL_TIME, 'hh24:mi')TTNT_ARVL_TIME, ddmonyyyy(a.TRVL_DATE)DT from epplive.BCS_TRVLTKT_REQUEST a where a.id='".$data['ID']."'");
    
    $t2 = singRec("select * from epplive.bcs_user_tasks where task_id='347' and tran_code='".$data['ID']."'");
    
    if($tickdets['TRVL_TKT_ID']!='')
    {
      $task_id2 = executeQry("insert into epplive.bcs_user_tasks (ID, TASK_ID, CREATED_ON, CREATED_BY, EXPIRE_ON, STATUS, AUTH_BY, AUTH_ON, REMARKS, TRAN_CODE, REF_TASK_ID, TASK_TYPE, UDF_1, TRAN_DESC, SITE_CODE, EMP_CODE_FOR, CHG_ON, UDF_2, TASK_GRP_DESC, IP_ADDR) values (null, '347', sysdate,'".$empCode."' , (sysdate+2), 'O', null, null, null, '".$data['ID']."', null, 'A', null, 'Ticket Cancellation Request From ".$tickdets['TRVL_FROM_LOC']." To ".$tickdets['TRVL_TO_LOC']." Dated ".$tickdets['TRVL_DATE']."', '".$t2['SITE_CODE']."', '".$t2['EMP_CODE_FOR']."', sysdate, '', '".$t2['TASK_GRP_DESC']."', '')");
    } else {
        executeQry("update ept_user_tasks set status='C' , REMARKS='Auto Closed , Cancelled Ticket' where tran_code='".$data['ID']."' and task_id =346 ") ;
    }
    
     if($tickdets['EMP_CODE'] != "" || !empty($tickdets['EMP_CODE'])) {
        $name = singRec("SELECT epplive.hr_get_emp_mgr('".$data['EMP_CODE']."',SYSDATE)EMP_CODE FROM DUAL");
        $name1 = findParentOrgEmp($data['EMP_CODE']);        
        $Manager = $name['EMP_CODE'] ? $name['EMP_CODE'] : $name1;

        $empemail = singRec("select EMAIL_ID_OFF as empemail from epplive.bcs_employee WHERE emp_code = '".$tickdets['EMP_CODE']."'");
        $manageremail = singRec("select EMAIL_ID_OFF as COM_EMAIL from epplive.bcs_employee WHERE emp_code = '".$Manager."'");

        if($manageremail['COM_EMAIL'] != 'rap@sdlindia.com'){
            $mailBody='Hi
            <br><br> The Following Ticket has been <b style="color:red">CANCELLED</b>.
            <br>
            <br><br>
            <b>  Employee  :</b> '.(getEmpInfoByCode($tickdets['EMP_CODE'])).'<br>
                    <b>  Travel Date :</b> '.$tickdets['DT'].'<br>
                    <b>  From & Departure Time: </b> '.$tickdets['TRVL_FROM_LOC'].' & '.$tickdets['TTNT_DEPR_TIME'].'<br>
                    <b>  To & Arrival Time : </b> '.$tickdets['TRVL_TO_LOC'].' & '.$tickdets['TTNT_ARVL_TIME'].'<br>
                    <b>  Mode :</b> '.$tickdets['TRVLMODE'].'<br>
                    <b>  Via :</b> '.$tickdets['TRVL_FT_NAME'].' - '.$tickdets['TRVL_FT_NO'].'<br>
                    <b>  Cancelled By :</b>'.(getEmpInfoByCode($tickdets['REQ_BY'])).'<br><br>
            <br><br> Regards<br> Admin';
            
            $maild = executeQry("INSERT INTO EPT_BCS_MAILBOX_EPP(ID,SUBJECT,MAIL_BODY,ATTACHMENT,STATUS, CHG_ON,CHG_BY,MAIL_DESCR) values(null,'  Ticket Cancellation : ".getEmpInfoByCode($tickdets['EMP_CODE'])." dated ".$tickdets['TRVL_DATE']."', '".trim($mailBody)."',null,'N',SYSDATE, '".$empCode."','Ticket Booking')  returning ID into :mid",'mid',1);

            executeQry("INSERT INTO EPT_BCS_MAILBOX_EPP_DETAILS(ID,MAIL_ID,EMAIL_TO,EMAIL_CC,EMAIL_BCC) values(null,'".$maild."', '".strtolower($empemail['EMPEMAIL'])." ','attendance@sdlindia.com,".$manageremail['COM_EMAIL']."',null)");

            
        }
    }
    echo json_encode([
        "status" => true,
        "status_code" => 200,
        "message" => "Ticket cancelled successfully"
    ]);

    endQry("Ticket has been cancelled!");

  }
}

ob_end_flush();
