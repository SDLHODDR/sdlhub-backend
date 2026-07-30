

<?php

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$conn = db_eportal();
$sql___func___con = $conn;

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       DATABASE CONNECTION
    =========================================== */

    if (!$conn) {
        apiResponse(false, "Database connection failed.", null, 500);
    }

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       FETCH POLICIES
    =========================================== */

    $empCodeEsc = str_replace("'", "''", $empCode);

    $sql = "
        SELECT
            POLI_ID,
            POLICY_NAME,
            DOC_PATH,
            POLICY_DESC,
            TO_CHAR(START_DATE,'DD-MON-YYYY') STARTDATE,
            TO_CHAR(END_DATE,'DD-MON-YYYY') ENDDATE
        FROM EPT_HR_POLICY
        WHERE STATUS = 'A'

        AND (
            DIVISION_ID = (
                SELECT DIVISION
                FROM EPT_BCS_EMPLOYEE
                WHERE EMP_CODE = '{$empCodeEsc}'
            )
            OR DIVISION_ID IS NULL
        )

        AND (
            DEPT_ID = (
                SELECT DEPT_ID
                FROM EPT_BCS_EMPLOYEE
                WHERE EMP_CODE = '{$empCodeEsc}'
            )
            OR DEPT_ID IS NULL
        )

        AND TRUNC(START_DATE) <= TRUNC(SYSDATE)

        AND (
            END_DATE IS NULL
            OR TRUNC(END_DATE) >= TRUNC(SYSDATE)
        )

        ORDER BY START_DATE DESC
    ";

    $policyRows = multiRec($sql);

    /* ===========================================
       FORMAT RESPONSE
    =========================================== */

    $policies = [];

    foreach ($policyRows as $row) {

        $fileUrl = !empty($row["DOC_PATH"])
            ? "https://hrms.sdlindia.com/hradmin/" . $row["DOC_PATH"]
            : null;

        $policies[] = [
            "policyId"    => $row["POLI_ID"],
            "policyName"  => $row["POLICY_NAME"],
            "description" => $row["POLICY_DESC"],
            "startDate"   => $row["STARTDATE"],
            "endDate"     => $row["ENDDATE"],
            "previewUrl"  => $fileUrl
        ];
    }

    apiResponse(true, "Policies fetched successfully.", $policies);

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getPolicies.php"
    );

    apiResponse(false, "Something went wrong while fetching policies.", null,500);

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }

}

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