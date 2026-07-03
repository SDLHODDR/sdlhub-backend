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

/* ---------------------------
   GET EMP ID
---------------------------- */
$empId = singRec("
    SELECT ID 
    FROM EPT_BCS_EMPLOYEE 
    WHERE emp_code = '".$empCode."'
")['ID'] ?? null;

if (!$empId) {
    echo json_encode(['status' => false, 'message' => 'Profile Not Found']);
    exit;
}

/* ---------------------------
   CORRECT FY
---------------------------- */
$year = date('Y');
$month = date('m');

$fy = ($month >= 4)
    ? $year . '-' . substr($year + 1, -2)
    : ($year - 1) . '-' . substr($year, -2);

/* ---------------------------
   FETCH LATEST REMARK
---------------------------- */
$row = singRec("
    SELECT remarks,
           ept_get_emp_name(actioned_by) AS acct_name
    FROM (
        SELECT remarks, actioned_by
        FROM ept_bcs_itax_emp_regime
        WHERE fy = '$fy'
        AND emp_id = '$empId'
        AND remarks IS NOT NULL
        ORDER BY id DESC
    )
    WHERE ROWNUM = 1
");

/* ---------------------------
   RESPONSE
---------------------------- */
echo json_encode([
    'status' => true,
    'data' => $row ? [
        'remarks' => $row['REMARKS'],
        'by' => $row['ACCT_NAME']
    ] : null
]);


/*echo json_encode([
    'status' => true,
    'data' => [
        'remarks' => "Testing is going on....",
        'by' => "savita jagtap"
    ]
]);*/
