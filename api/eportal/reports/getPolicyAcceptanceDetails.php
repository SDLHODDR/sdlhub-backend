<?php

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json");

$response = [
    "status" => false,
    "message" => "",
    "summary" => [],
    "data" => []
];

try {

    $con = db_eportal();

    /* ========================================
        SESSION VALIDATION
    ========================================= */   

    if (!isset($_SESSION['emp_code'])) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Unauthorized Access"
        ]);
        exit;
    }

    /* ========================================
        READ INPUT
    ========================================= */
   
    $rawInput = file_get_contents("php://input");
    $input = json_decode($rawInput, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $_POST = $input;
    }

    $policyId = 49; //trim($_POST["policy_id"] ?? '');

    if (empty($policyId)) {

        echo json_encode([
            "status" => false,
            "message" => "Policy ID is required"
        ]);

        exit;
    }

    /* =========================================
        POLICY DETAILS
    ========================================== */

    $policySql = "
        SELECT
            POLI_ID,
            POLICY_NAME,
            IS_MANDAT,
            STATUS,
            START_DATE,
            END_DATE
        FROM EPT_HR_POLICY
        WHERE POLI_ID = :policy_id
    ";

    $policyStmt = oci_parse($con, $policySql);
    oci_bind_by_name( $policyStmt,":policy_id", $policyId);

    oci_execute($policyStmt);
    $policy = oci_fetch_assoc($policyStmt);

    if (!$policy) {
        echo json_encode([
            "status" => false,
            "message" => "Policy not found"
        ]);
        exit;
    }

    /* =========================================
        SUMMARY
    ========================================== */

    $summarySql = "
    SELECT
        COUNT(DISTINCT E.EMP_CODE) TOTAL_EMPLOYEES,

        COUNT(
            DISTINCT CASE
                WHEN NVL(A.ACCEPTED_FLAG,'N') = 'Y'
                THEN E.EMP_CODE
            END
        ) ACCEPTED_COUNT

    FROM (
        SELECT *
        FROM (
            SELECT
                H.*,
                ROW_NUMBER() OVER (
                    PARTITION BY H.EMP_CODE
                    ORDER BY H.EMP_CODE
                ) RN
            FROM EPT_HR_EMP_OFFICE_DET H
        )
        WHERE RN = 1
    ) H
    
    JOIN EPT_BCS_EMPLOYEE E
        ON H.EMP_CODE = E.EMP_CODE

    LEFT JOIN EPT_USER_POLICY_VIEW_LOG A
        ON A.EMP_CODE = E.EMP_CODE
    AND A.POLICY_ID = :policy_id

    LEFT JOIN EPT_HR_DIVISIONS DIVS
        ON DIVS.DIVSN_ID = H.DIVSN_ID

    LEFT JOIN EPT_HR_DEPARTMENT DEPT
        ON DEPT.DEPT_ID = H.DEPT_ID

    WHERE 
    E.STATUS='A' AND 
    H.EMP_CODE = E.EMP_CODE 
    
    AND EXISTS (
        SELECT 1
        FROM EPT_HR_POLICY_DIVSN D
        WHERE D.POLICY_ID = :policy_id
        AND D.DIVSN_ID = H.DIVSN_ID
    )
    AND EXISTS (
        SELECT 1
        FROM EPT_HR_POLICY_DEPT DP
        WHERE DP.POLICY_ID = :policy_id
        AND DP.DEPT_ID = H.DEPT_ID
    )

    ";

    $summaryStmt = oci_parse($con, $summarySql);
    oci_bind_by_name($summaryStmt, ":policy_id", $policyId);
    oci_execute($summaryStmt);

    $summary = oci_fetch_assoc($summaryStmt);
    $totalEmployees = (int)($summary["TOTAL_EMPLOYEES"] ?? 0);
    $acceptedCount = (int)($summary["ACCEPTED_COUNT"] ?? 0);
    $pendingCount = $totalEmployees - $acceptedCount;

    /* =========================================
        EMPLOYEE DETAILS
    ========================================== */

    $detailSql = "
        SELECT 
        E.EMP_CODE,
    TRIM(
        E.EMP_FNAME
        || ' '
        || NVL(E.EMP_MNAME,'')
        || ' '
        || E.EMP_LNAME
    ) AS EMP_NAME,

    DIVS.DIVSN_DESC AS DIVISION,
    DEPT.DEPT_DESC AS DEPARTMENT,
    DEPT.DEPT_CODE,
    HR_GET_DESIGN_NAME(E.DESIGNATION) DESIG_NAME,
    CASE
        WHEN NVL(A.ACCEPTED_FLAG,'N') = 'Y'
        THEN 'Accepted'
        ELSE 'Pending'
    END AS POLICY_STATUS,

    TO_CHAR(
        A.ACCEPTED_ON,
        'DD-MON-YYYY HH24:MI:SS'
    ) AS ACCEPTED_ON,

    A.IP_ADDR,
    A.USER_AGENT 

FROM (
    SELECT *
    FROM (
        SELECT
            H.*,
            ROW_NUMBER() OVER (
                PARTITION BY H.EMP_CODE
                ORDER BY H.EMP_CODE
            ) RN
        FROM EPT_HR_EMP_OFFICE_DET H
    )
    WHERE RN = 1
) H

JOIN EPT_BCS_EMPLOYEE E
    ON H.EMP_CODE = E.EMP_CODE

LEFT JOIN EPT_USER_POLICY_VIEW_LOG A
    ON A.EMP_CODE = E.EMP_CODE
   AND A.POLICY_ID = :policy_id

LEFT JOIN EPT_HR_DIVISIONS DIVS
    ON DIVS.DIVSN_ID = H.DIVSN_ID

LEFT JOIN EPT_HR_DEPARTMENT DEPT
    ON DEPT.DEPT_ID = H.DEPT_ID

WHERE 
E.STATUS='A' AND 
H.EMP_CODE = E.EMP_CODE 
 
AND EXISTS (
    SELECT 1
    FROM EPT_HR_POLICY_DIVSN D
    WHERE D.POLICY_ID = :policy_id
    AND D.DIVSN_ID = H.DIVSN_ID
)
AND EXISTS (
    SELECT 1
    FROM EPT_HR_POLICY_DEPT DP
    WHERE DP.POLICY_ID = :policy_id
    AND DP.DEPT_ID = H.DEPT_ID
)

ORDER BY
    CASE
        WHEN NVL(A.ACCEPTED_FLAG,'N')='Y'
        THEN 1
        ELSE 2
    END,
    EMP_NAME
    ";

    $detailStmt = oci_parse($con, $detailSql);
    oci_bind_by_name($detailStmt, ":policy_id", $policyId);

    oci_execute($detailStmt);

    $result = oci_execute($detailStmt);

    if (!$result) {
        $e = oci_error($detailStmt);
        echo json_encode([
            "status" => false,
            "oracle_error" => $e
        ]);
        exit;
    }

    $employees = [];

    while ($row = oci_fetch_assoc($detailStmt)) {
        $employees[] = [
            "emp_code" => trim($row["EMP_CODE"]),
            "emp_name" => trim($row["EMP_NAME"]),
            "designation" => trim($row["DESIG_NAME"]),
            "department"  => trim($row["DEPARTMENT"] ?? ""),
            "division"  => trim($row["DIVISION"] ?? ""),
            "dept_code" => trim($row["DEPT_CODE"] ?? ""),
            "policy_status" => $row["POLICY_STATUS"],
            "accepted_on" => $row["ACCEPTED_ON"],
            "ip_address" => $row["IP_ADDR"],
            "user_agent" => $row["USER_AGENT"]
        ];
    }

    /* =========================================
        RESPONSE
    ========================================== */
 
    $response = [
        "status" => true,
        "summary" => [
            "policy_id" => $policy["POLI_ID"],
            "policy_name" => $policy["POLICY_NAME"],
            "mandatory" => $policy["IS_MANDAT"],
            "start_date" => $policy['START_DATE'],
            "end_date" => $policy['END_DATE'],
            "total_employees" => $totalEmployees,
            "accepted_count" => $acceptedCount,
            "pending_count" => $pendingCount,
            "acceptance_percentage" => $totalEmployees > 0 ? round(($acceptedCount / $totalEmployees) * 100,2): 0
        ],
        "data" => $employees
    ];
}
catch (Exception $e) {

    $response = [
        "status" => false,
        "message" => $e->getMessage()
    ];
}

echo json_encode($response);
exit;