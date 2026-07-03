<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/emp_func.php";
require_once __DIR__."/../../config/utils.php";

if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = $_SESSION['emp_code'] ?? '';

$year = date('Y');

$data = multiRec("
SELECT 
    c.id,
    r.room_label,
    TO_CHAR(c.start_time,'DD-Mon-YYYY') DT,
    TO_CHAR(c.start_time,'HH24:MI') STARTTIME,
    TO_CHAR(c.end_time,'HH24:MI') ENDTIME,
    c.book_by_emp,
    c.noof_attd,
    c.room_facl1,
    c.room_facl2,
    c.room_facl3,
    c.divsn_id,
    c.remarks,
    hd.divsn_desc
FROM ept_conf_room_tran c
LEFT JOIN ept_conf_rooms r 
    ON r.id = c.room_id
LEFT JOIN EPT_hr_divisions hd 
    ON hd.divsn_id = c.divsn_id
WHERE c.status = 'A'
AND c.start_time >= SYSDATE - 365
ORDER BY c.start_time DESC
");

foreach($data as &$row){

    $row['BOOK_BY_NAME'] = ucwords(strtolower(getEmpInfoByCode($row['BOOK_BY_EMP'])));

    $faclArr = [];

    if($row['ROOM_FACL1'] == 'Y') $faclArr[] = 'Tea / Coffee';
    if($row['ROOM_FACL2'] == 'Y') $faclArr[] = 'Breakfast';
    if($row['ROOM_FACL3'] == 'Y') $faclArr[] = 'Lunch';

    $row['FACILITIES'] = implode(', ', $faclArr);
}

apiResponse(true,"Success",$data);

