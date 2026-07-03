<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ ."/../../config/utils.php";

header('Content-Type: application/json');

session_start();

$empCode = $_SESSION['emp_code'] ?? null;

/* RELEASE LOCK */
session_write_close();

if (!$empCode) {   
    apiResponse(false,"Unauthorized Access",null,401);
}

/* ----------------------------------
   LEAVE SUMMARY (DIRECT FROM BALANCE)
----------------------------------- 

$sql = "
SELECT 
    LVE_CODE,
    NVL(CONS_DAYS, 0) AS CONS_DAYS,
    NVL(BAL_DAYS, 0) AS BAL_DAYS
FROM ept_bcs_leave_balance
WHERE emp_code = '$empCode'
AND LVE_CODE <> 'LWP'
AND SYSDATE BETWEEN EFF_DATE AND (UPTO_DATE + 1)
ORDER BY LVE_CODE
";*/

$sql = "SELECT 
    LVE_CODE,
    NVL(CONS_DAYS, 0) AS CONS_DAYS,
    NVL(BAL_DAYS, 0) AS BAL_DAYS
FROM ept_bcs_leave_balance lb
WHERE emp_code = '$empCode'
AND LVE_CODE <> 'LWP'
AND (
    SELECT MAX(UPTO_DATE) 
    FROM ept_bcs_leave_balance 
    WHERE emp_code = '$empCode'
) BETWEEN lb.EFF_DATE AND (lb.UPTO_DATE + 1)
ORDER BY LVE_CODE";

$rows = multiRec($sql);

/* ----------------------------------
   FORMAT RESPONSE (React Friendly)
----------------------------------- */

$result = [];

foreach ($rows as $row) {
    $result[] = [
        'type'     => $row['LVE_CODE'],      // CL, SL, OL etc
        'consumed' => (float)$row['CONS_DAYS'],
        'balance'  => (float)$row['BAL_DAYS']
    ];
}

/* ----------------------------------
   RESPONSE
----------------------------------- */

echo json_encode([
    'status' => true,
    'data'   => $result
]);

exit;
