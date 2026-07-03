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

/* -------------------------------
   1. Get BDAY GROUPS
--------------------------------*/
$procGrp = singRec("
    SELECT VALUE 
    FROM EPT_BCS_SYS_PARAMS
    WHERE KEY = 'BDAY_GRP'
");

if (!$procGrp || empty($procGrp['VALUE'])) {
    echo json_encode(['status' => true, 'data' => []]);
    exit;
}

$groups = explode(',', $procGrp['VALUE']);
$groupStr = "'" . implode("','", $groups) . "'";

/* -------------------------------
   2. DATE RANGE (MMDD)
--------------------------------*/
$today  = date('md');
$next7  = date('md', strtotime('+7 days'));

/* Handle Dec → Jan wrap */
if ($today <= $next7) {
    $dateCondition = "
        TO_NUMBER(TO_CHAR(be.birth_date,'MMDD')) 
        BETWEEN $today AND $next7
    ";
} else {
    $dateCondition = "
        TO_NUMBER(TO_CHAR(be.birth_date,'MMDD')) >= $today 
        OR TO_NUMBER(TO_CHAR(be.birth_date,'MMDD')) <= $next7
    ";
}

/* -------------------------------
   3. MAIN QUERY
--------------------------------*/
$sql = "
SELECT 
    be.emp_code,
    (be.emp_fname || ' ' || SUBSTR(be.emp_lname,1,1)) AS emp_name,
    be.birth_date,
    TO_CHAR(be.birth_date, 'DD-Mon') AS bmonth,
    eum.message
FROM ept_bcs_employee be
LEFT JOIN ept_user_messages eum 
    ON be.emp_code = eum.created_for
    AND eum.created_by = '$empCode'
    AND TO_CHAR(eum.created_on, 'YYYY') = TO_CHAR(SYSDATE, 'YYYY')
WHERE 
    be.status = 'A'
    AND be.proc_group IN ($groupStr)
    AND ($dateCondition)
ORDER BY TO_DATE(TO_CHAR(be.birth_date, 'DD-Mon'), 'DD-Mon')
";

$rows = multiRec($sql);

/* -------------------------------
   4. GROUP DATA
--------------------------------*/
$result = [];

foreach ($rows as $row) {

    $key = $row['BMONTH'];

    if (!isset($result[$key])) {
        $result[$key] = [];
    }

    $result[$key][] = [
        'emp_code' => $row['EMP_CODE'],
        'name' => ucwords(strtolower($row['EMP_NAME'])),
        'birth_date' => $row['BIRTH_DATE'],
        'message' => $row['MESSAGE'],
        'profile_image' => getProfileUrl($row['EMP_CODE'])
    ];
}

/* -------------------------------
   5. RESPONSE
--------------------------------*/
echo json_encode([
    'status' => true,
    'data' => $result
]);
exit;

/* -------------------------------
   HELPERS
--------------------------------*/
function getProfileUrl($empCode)
{
    $path = $_SERVER['DOCUMENT_ROOT'] . "/assets/img/profiles/{$empCode}.jpg";

    if (file_exists($path)) {
        return "/assets/img/profiles/{$empCode}.jpg";
    }

    return null;
}
