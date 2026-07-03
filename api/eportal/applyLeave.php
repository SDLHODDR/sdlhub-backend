<?php
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-TOKEN");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/validateCsrf.php";
require_once __DIR__ ."/../../config/utils.php";

header("Content-Type: application/json");

/* -------------------------------------------------
   1. SESSION 
------------------------------------------------- */
if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

/* -------------------------------------------------
   2. READ JSON INPUT
------------------------------------------------- */
$data = json_decode(file_get_contents("php://input"), true);

$EMP_CODE   = $_SESSION['emp_code'];

$LVE_CODE   = trim($data['leave_code'] ?? '');
$FROM_DATE  = $data['from_date'] ?? '';
$TO_DATE    = $data['to_date'] ?? '';
$TOTAL_DAYS = (float)($data['days'] ?? 0);
$REASON     = trim($data['reason'] ?? '');

/* -------------------------------------------------
   3. SESSION CODE NORMALIZATION (IMPORTANT)
------------------------------------------------- */
$START_ON = strtoupper($data['start_session'] ?? 'B');
$END_ON   = strtoupper($data['end_session'] ?? 'E');

$validSessions = ['M','B','E'];

if (!in_array($START_ON, $validSessions) || !in_array($END_ON, $validSessions)) {
    echo json_encode(["status" => false, "message" => "Invalid session code"]);
    exit;
}

/* -------------------------------------------------
   4. BASIC VALIDATION
------------------------------------------------- */
$today = date('Y-m-d');

if (!$LVE_CODE || !$FROM_DATE || !$TO_DATE || !$REASON) {
    echo json_encode(["status" => false, "message" => "Invalid input"]);
    exit;
}

if ($FROM_DATE < $today) {
    echo json_encode(["status" => false, "message" => "From date cannot be less than today"]);
    exit;
}

if ($FROM_DATE > $TO_DATE) {
    echo json_encode(["status" => false, "message" => "From date cannot be greater than To date"]);
    exit;
}

if ($TOTAL_DAYS <= 0) {
    echo json_encode(["status" => false, "message" => "Invalid number of days"]);
    exit;
}

/* -------------------------------------------------
   4. DATABASE CONNECTION
------------------------------------------------- */
$conn = db_eportal();

/* -------------------------------------------------
   6. FIND MANAGER
------------------------------------------------- */
$mgrSql = "SELECT hr_get_emp_mgr(:emp_code, SYSDATE) AS EMP_CODE FROM dual";
$stmt = oci_parse($conn, $mgrSql);
oci_bind_by_name($stmt, ":emp_code", $EMP_CODE);
oci_execute($stmt);

$mgrRow = oci_fetch_assoc($stmt);
$MANAGER = $mgrRow['EMP_CODE'] ?: $EMP_CODE;

/* -------------------------------------------------
   7. FIND PRIMARY ID
------------------------------------------------- */
$priSql = "SELECT ID FROM ept_bcs_employee WHERE emp_code = :emp_code";
$stmt = oci_parse($conn, $priSql);
oci_bind_by_name($stmt, ":emp_code", $EMP_CODE);

if (!oci_execute($stmt)) {
    echo json_encode(["status"=>false,"message"=>"Employee lookup failed","error"=>oci_error($stmt)]);
    exit;
}

$row = oci_fetch_assoc($stmt);
$PRIMARY_ID = $row['ID'] ?? null;

if (!$PRIMARY_ID) {
    echo json_encode(["status"=>false,"message"=>"Invalid employee"]);
    exit;
}


/* -------------------------------------------------
   8. CHECK OVERLAPPING LEAVES
------------------------------------------------- */
$overlapSql = "
SELECT emp_code FROM ept_bcs_emp_leaves_temp
WHERE emp_code = :emp_code
AND status NOT IN ('X','R')
AND (
    lve_date_fr BETWEEN TO_DATE(:from_dt,'YYYY-MM-DD') AND TO_DATE(:to_dt,'YYYY-MM-DD')
 OR lve_date_to BETWEEN TO_DATE(:from_dt,'YYYY-MM-DD') AND TO_DATE(:to_dt,'YYYY-MM-DD')
 OR TO_DATE(:from_dt,'YYYY-MM-DD') BETWEEN lve_date_fr AND lve_date_to
)
UNION
SELECT emp_code FROM ept_bcs_emp_leaves
WHERE emp_code = :emp_code
AND (
    lve_date_fr BETWEEN TO_DATE(:from_dt,'YYYY-MM-DD') AND TO_DATE(:to_dt,'YYYY-MM-DD')
 OR lve_date_to BETWEEN TO_DATE(:from_dt,'YYYY-MM-DD') AND TO_DATE(:to_dt,'YYYY-MM-DD')
 OR TO_DATE(:from_dt,'YYYY-MM-DD') BETWEEN lve_date_fr AND lve_date_to
)
";

$stmt = oci_parse($conn, $overlapSql);
oci_bind_by_name($stmt, ":emp_code", $EMP_CODE);
oci_bind_by_name($stmt, ":from_dt", $FROM_DATE);
oci_bind_by_name($stmt, ":to_dt", $TO_DATE);
oci_execute($stmt);

if (oci_fetch($stmt)) {
    echo json_encode(["status" => false, "message" => "Leave already exists for this period"]);
    exit;
}

/* -------------------------------------------------
   9. INSERT LEAVE
------------------------------------------------- */
$insertSql = "
INSERT INTO ept_bcs_emp_leaves_temp
(
 EMP_CODE, LVE_DATE_FR, LVE_DATE_TO,
 LVE_START_ON, LVE_END_ON,
 LVE_CODE, TOTAL_DAYS, REASON,
 CHG_BY, CHG_ON,
 APRVR_ID, RAISED_BY, STATUS
)
VALUES
(
 :emp_code,
 TO_DATE(:from_dt,'YYYY-MM-DD'),
 TO_DATE(:to_dt,'YYYY-MM-DD'),
 :start_on,
 :end_on,
 :lve_code,
 :total_days,
 :reason,
 :chg_by,
 SYSDATE,
 :manager,
 :raised_by,
 'T'
)
RETURNING ID INTO :new_id
";

$stmt = oci_parse($conn, $insertSql);

oci_bind_by_name($stmt, ":emp_code", $EMP_CODE);
oci_bind_by_name($stmt, ":from_dt", $FROM_DATE);
oci_bind_by_name($stmt, ":to_dt", $TO_DATE);
oci_bind_by_name($stmt, ":start_on", $START_ON);
oci_bind_by_name($stmt, ":end_on", $END_ON);
oci_bind_by_name($stmt, ":lve_code", $LVE_CODE);
oci_bind_by_name($stmt, ":total_days", $TOTAL_DAYS);
oci_bind_by_name($stmt, ":reason", $REASON);
oci_bind_by_name($stmt, ":chg_by", $PRIMARY_ID);
oci_bind_by_name($stmt, ":manager", $MANAGER);
oci_bind_by_name($stmt, ":raised_by", $EMP_CODE);
oci_bind_by_name($stmt, ":new_id", $NEW_ID, 32);

$r = oci_execute($stmt, OCI_NO_AUTO_COMMIT);

/*if (!$r) {
    oci_rollback($conn);
    echo json_encode(["status" => false, "message" => "Save failed"]);
    exit;
}*/

if (!$r) {
    $e = oci_error($stmt); 
    oci_rollback($conn);

    echo json_encode([
        "status" => false,
        "message" => "Save failed",
        "oracle_error" => $e['message'],
        "oracle_code"  => $e['code']
    ]);
    exit;
}

oci_commit($conn);

/* -------------------------------------------------
   10. EMAIL NOTIFICATION
------------------------------------------------- 
$payload = json_encode(["leave_id" => $NEW_ID]);

$ch = curl_init("http://localhost/sdlhubnew/backend/api/eportal/sendLeaveEmail.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_COOKIE => session_name() . '=' . session_id()
]);
curl_exec($ch);
curl_close($ch);
*/
/* -------------------------------------------------
   11. RESPONSE
------------------------------------------------- */
echo json_encode([
    "status" => true,
    "message" => "Leave applied successfully",
    "leave_id" => $NEW_ID
]);

ob_end_flush();
