<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__."/../../config/utils.php";

if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = $_SESSION['emp_code'] ?? '';

/* ---------------------------
   READ INPUT
---------------------------- */
$dataReq = json_decode(file_get_contents("php://input"), true);

// ============================
// INPUT
// ============================
$page  = isset($dataReq['page']) ? (int)$dataReq['page'] : 1;
$limit = isset($dataReq['limit']) ? (int)$dataReq['limit'] : 10;
//$search = $dataReq['search'] ?? '';
//$status = $dataReq['status'] ?? '';

$offset = ($page - 1) * $limit;

try {

    $page  = isset($dataReq['page']) ? (int)$dataReq['page'] : 1;
    $limit = isset($dataReq['limit']) ? (int)$dataReq['limit'] : 10;

    // Prevent invalid values
    $page  = max($page, 1);
    $limit = max($limit, 1);

    $offset = ($page - 1) * $limit;

    /* ================= TOTAL RECORDS ================= */

    $countSql = "
        select count(*) as total
        from ept_conf_room_tran c
        where 
            (c.chg_by = '$empCode' or c.book_by_emp = '$empCode') 
            and c.status in ('A','X','N','T','R')
    ";

    $countRes = singRec($countSql);

    $totalRecords = $countRes['TOTAL'] ?? 0;


    /* ================= PAGINATED DATA ================= */

    $sql = "
        select 
            c.id, 
            c.room_id, 
            r.room_label, 
            to_char(c.start_time,'dd-Mon-yyyy') dt, 
            to_char(c.start_time,'hh24:mi') starttime,
            to_char(c.end_time,'hh24:mi') endtime, 
            c.book_time, 
            c.status,
            c.book_by_emp,
            c.NOOF_ATTD,
            c.REMARKS,
            c.chg_on,
            c.ROOM_FACL1,
            c.ROOM_FACL2,
            c.ROOM_FACL3,
            c.CHG_BY,
            c.DIVSN_ID,
            initcap(e.fname || ' ' || e.lname) as book_by_name
        from ept_conf_room_tran c
        left join ept_conf_rooms r 
            on r.id = c.room_id
        left join EPT_hr_employee_info e
            on e.emp_code = c.book_by_emp
        where 
            (c.chg_by = '$empCode' or c.book_by_emp = '$empCode') 
            and c.status in ('A','X','N','T','R')
        order by c.start_time desc
        offset $offset rows fetch next $limit rows only
    ";

    $data = multiRec($sql);

    echo json_encode([
        "status" => true,
        "data" => $data,
        "page" => $page,
        "limit" => $limit,
        "totalRecords" => (int)$totalRecords,
        "totalPages" => ceil($totalRecords / $limit)
    ]);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
// try {
//     $sql = "
//     select 
//         c.id, 
//         c.room_id, 
//         r.room_label, 
//         to_char(c.start_time,'dd-Mon-yyyy') dt, 
//         to_char(c.start_time,'hh24:mi') starttime,
//         to_char(c.end_time,'hh24:mi') endtime, 
//         c.book_time, 
//         c.status,
//         c.book_by_emp,
//         c.NOOF_ATTD,
//         c.REMARKS,
//         c.chg_on,
//         c.ROOM_FACL1,c.ROOM_FACL2,c.ROOM_FACL3,c.CHG_BY,c.DIVSN_ID,
//         initcap(e.fname || ' ' || e.lname) as book_by_name
//     from ept_conf_room_tran c
//     left join ept_conf_rooms r 
//         on r.id = c.room_id
//     left join EPT_hr_employee_info e
//         on e.emp_code = c.book_by_emp
//     where 
//         (c.chg_by = '$empCode' or c.book_by_emp = '$empCode') 
//         and c.status in ('A','X','N','T','R')
//     order by c.start_time desc
// ";

//     $data = multiRec($sql);

//     echo json_encode([
//         "status" => true,
//         "count" => count($data),
//         "data" => $data
//     ]);

// } catch (Exception $e) {

//     echo json_encode([
//         "status" => false,
//         "message" => "Database error",
//         "error" => $e->getMessage()
//     ]);
// }
