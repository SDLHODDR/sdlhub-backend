<?php

ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ ."/../config/utils.php";

header('Content-Type: application/json');

// ================= SESSION VALIDATION =================

if (!isset($_SESSION['emp_code'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$empCode = $_SESSION['emp_code'] ?? '';

// ================= FETCH POLICIES =================
/*
$sql = "
SELECT 
    POLI_ID,
    POLICY_NAME,
    DOC_PATH,
    POLICY_DESC,
    TO_CHAR(START_DATE,'dd-Mon-yyyy') AS STARTDATE,
    TO_CHAR(END_DATE,'dd-Mon-yyyy') AS ENDDATE
FROM EPT_HR_POLICY
WHERE STATUS = 'A'
AND (
    DIVISION_ID = (
        SELECT DIVISION 
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    )
    OR DIVISION_ID IS NULL
)
AND (
    DEPT_ID = (
        SELECT DIVISION 
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    )
    OR DEPT_ID IS NULL
)
-- Optional date filter:
-- AND SYSDATE BETWEEN START_DATE AND NVL(END_DATE, TO_DATE('31-Mar-3000','DD-Mon-YYYY'))
ORDER BY START_DATE DESC
"; */

$sql = "
SELECT 
    POLI_ID,
    POLICY_NAME,
    DOC_PATH,
    POLICY_DESC,
    TO_CHAR(START_DATE, 'dd-Mon-yyyy') AS STARTDATE,
    TO_CHAR(END_DATE, 'dd-Mon-yyyy') AS ENDDATE
FROM EPT_HR_POLICY
WHERE STATUS = 'A'

    /* Division Filter */
    AND (
        DIVISION_ID = (
            SELECT DIVISION
            FROM EPT_BCS_EMPLOYEE
            WHERE EMP_CODE = '$empCode'
        )
        OR DIVISION_ID IS NULL
    )

    /* Department Filter */
    AND (
        DEPT_ID = (
            SELECT DIVISION
            FROM EPT_BCS_EMPLOYEE
            WHERE EMP_CODE = '$empCode'
        )
        OR DEPT_ID IS NULL
    )

   /* Start date should be started */
    AND TRUNC(START_DATE) <= TRUNC(SYSDATE)

    /* End date should be today or future */
    AND (
        END_DATE IS NULL
        OR TRUNC(END_DATE) >= TRUNC(SYSDATE)
    )

ORDER BY START_DATE DESC";

$policy = multiRec($sql);

if (empty($policy)) {
    echo json_encode([
        "status" => true,
        "data" => []
    ]);
    exit;
}

// ================= FORMAT RESPONSE =================

$policies = [];

foreach ($policy as $row) {

    // Adjust base path if needed
    $fileUrl = !empty($row['DOC_PATH'])
        ? "https://hrms.sdlindia.com/hradmin/" . $row['DOC_PATH']
        : null;

    $policies[] = [
        "policyId"     => $row['POLI_ID'],
        "policyName"   => $row['POLICY_NAME'],
        "description"  => $row['POLICY_DESC'],
        "startDate"    => $row['STARTDATE'],
        "endDate"      => $row['ENDDATE'],
        "previewUrl"   => $fileUrl
    ];
}

echo json_encode([
    "status" => true,
    "data"   => $policies
]);

exit;

/*
OUTPUT:
{
  "status": true,
  "data": [
    {
      "policyId": "12",
      "policyName": "Leave Policy",
      "description": "Updated leave rules",
      "startDate": "01-Jan-2025",
      "endDate": "31-Dec-2025",
      "previewUrl": "http://localhost/sdlhub/uploads/policies/leave_policy.pdf"
    }
  ]
}
*/
