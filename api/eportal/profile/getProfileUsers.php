<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/* =========================================
   CHECK SESSION
========================================= */

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {
    apiResponse(false, "Unauthorized access", null, 401);
    exit;
}

/* =========================================
   VALIDATE REQUEST METHOD
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(false, "Invalid request method", null, 405);
    exit;
}

/* =========================================
   GET PROFILE ID
========================================= */

$profileId = $_GET['profileId'] ?? '';

if (empty($profileId)) {
    apiResponse(false, "Profile ID is required", null, 400);
    exit;
}

/* =========================================
   FETCH USERS OF SELECTED PROFILE
========================================= */

$users = [];

$sql = "
    SELECT 
        e.EMP_CODE,
        TRIM(
            NVL(e.EMP_FNAME, '') || ' ' ||
            NVL(e.EMP_MNAME, '') || ' ' ||
            NVL(e.EMP_LNAME, '')
        ) AS EMP_NAME,
        EPT_GET_DEPT_NAME(HR_GET_DEPTCODE_ID(e.DEPT_CODE)) DEPT_NAME ,
        HR_GET_DESIGN_NAME(e.DESIGNATION) DESIG_NAME,
        HR_GET_DIVISION_NAME(e.DIVISION) DIVISION_NAME      
    FROM EPT_EMP_PROFILE p
    INNER JOIN EPT_BCS_EMPLOYEE e
        ON p.EMP_CODE = e.EMP_CODE   
    WHERE p.PROFILE_ID = '$profileId'
    ORDER BY e.EMP_CODE ASC
";

$results = multiRec($sql);

if (!empty($results)) {
    foreach ($results as $row) {
        $users[] = [
            "empCode" => $row["EMP_CODE"] ?? "",
            "empName" => $row["EMP_NAME"] ?? "",
            "department" => $row["DEPT_NAME"] ?? "",
            "designation" => $row["DESIG_NAME"] ?? ""
        ];
    }
}

/* =========================================
   RESPONSE
========================================= */

apiResponse(
    true,
    "Profile users fetched successfully",
    $users,
    200
);

exit;

?>
