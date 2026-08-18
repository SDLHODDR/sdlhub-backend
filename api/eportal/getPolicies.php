<?php

ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    // For testing only
    // $empCode = "00575";

    $empCodeEsc = str_replace("'", "''", $empCode);

    /* ===========================================
       FETCH POLICIES
    =========================================== */

    $policy = multiRec("
        SELECT
            POLI_ID,
            POLICY_NAME,
            DOC_PATH,
            POLICY_DESC,
            TO_CHAR(START_DATE,'dd-Mon-yyyy') STARTDATE,
            TO_CHAR(END_DATE,'dd-Mon-yyyy') ENDDATE
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

        -- Optional Date Filter
        -- AND TRUNC(SYSDATE) BETWEEN TRUNC(START_DATE)
        --     AND NVL(TRUNC(END_DATE), DATE '3000-03-31')

        ORDER BY START_DATE DESC
    ");

    /* ===========================================
       FORMAT RESPONSE
    =========================================== */

    $policies = [];

    foreach ($policy as $row) {

        $policies[] = [
            "policyId"    => $row["POLI_ID"],
            "policyName"  => $row["POLICY_NAME"],
            "description" => $row["POLICY_DESC"],
            "startDate"   => $row["STARTDATE"],
            "endDate"     => $row["ENDDATE"],
            "previewUrl"  => !empty($row["DOC_PATH"])
                ? "https://hrms.sdlindia.com/hradmin/" . $row["DOC_PATH"]
                : null,
        ];
    }

    apiResponse(true, "Policies fetched successfully.", $policies
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getPolicies.php"
    );

    apiResponse(false, "Unable to fetch policies.",null, 500);
}

/*
output:
{
    "status": true,
    "message": "Policies fetched successfully.",
    "data": [
        {
            "policyId": "12",
            "policyName": "Leave Policy",
            "description": "Updated leave rules",
            "startDate": "01-JAN-2025",
            "endDate": "31-DEC-2025",
            "previewUrl": "https://hrms.sdlindia.com/hradmin/uploads/policies/leave.pdf"
        }
    ]
}*/