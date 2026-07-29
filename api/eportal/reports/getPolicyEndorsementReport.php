<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {
    /*
    =========================================
    SESSION VALIDATION
    =========================================
    */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (!$empCode) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /*
    =========================================
    FETCH POLICIES
    =========================================
    */

    $sql = "
        SELECT
            P.POLI_ID,
            P.POLICY_NAME,
            NVL(P.IS_MANDAT,'N') AS IS_MANDAT,
            (
                SELECT COUNT(DISTINCT E.EMP_CODE)

                FROM (
                    SELECT *
                    FROM (
                        SELECT
                            H.*,
                            ROW_NUMBER() OVER(
                                PARTITION BY H.EMP_CODE
                                ORDER BY H.EMP_CODE
                            ) RN
                        FROM EPT_HR_EMP_OFFICE_DET H
                    )
                    WHERE RN = 1
                ) H

                JOIN EPT_BCS_EMPLOYEE E
                    ON E.EMP_CODE = H.EMP_CODE

                WHERE E.STATUS = 'A'

                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_POLICY_DIVSN D
                    WHERE D.POLICY_ID = P.POLI_ID
                    AND D.DIVSN_ID = H.DIVSN_ID
                )

                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_POLICY_DEPT DP
                    WHERE DP.POLICY_ID = P.POLI_ID
                    AND DP.DEPT_ID = H.DEPT_ID
                )

            ) AS TARGET_EMPLOYEES,
            (
                SELECT COUNT(
                    DISTINCT CASE
                        WHEN NVL(A.ACCEPTED_FLAG,'N') = 'Y'
                        THEN E.EMP_CODE
                    END
                )

                FROM (
                    SELECT *
                    FROM (
                        SELECT
                            H.*,
                            ROW_NUMBER() OVER(
                                PARTITION BY H.EMP_CODE
                                ORDER BY H.EMP_CODE
                            ) RN
                        FROM EPT_HR_EMP_OFFICE_DET H
                    )
                    WHERE RN = 1
                ) H

                JOIN EPT_BCS_EMPLOYEE E
                    ON E.EMP_CODE = H.EMP_CODE

                LEFT JOIN EPT_USER_POLICY_VIEW_LOG A
                    ON A.EMP_CODE = E.EMP_CODE
                   AND A.POLICY_ID = P.POLI_ID

                WHERE E.STATUS='A'

                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_POLICY_DIVSN D
                    WHERE D.POLICY_ID = P.POLI_ID
                    AND D.DIVSN_ID = H.DIVSN_ID
                )

                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_POLICY_DEPT DP
                    WHERE DP.POLICY_ID = P.POLI_ID
                    AND DP.DEPT_ID = H.DEPT_ID
                )

            ) AS ACCEPTED_COUNT

        FROM EPT_HR_POLICY P

        WHERE P.STATUS='A'
        AND P.IS_MANDAT='Y'

        ORDER BY P.POLI_ID DESC
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $sql
    );
    oci_execute($stmt);

    $data = [];

    while ($row = oci_fetch_assoc($stmt)) {

        $targetEmployees = (int)$row['TARGET_EMPLOYEES'];
        $acceptedCount   = (int)$row['ACCEPTED_COUNT'];

        $pendingCount = max(
            0,
            $targetEmployees - $acceptedCount
        );

        $acceptancePercentage = $targetEmployees > 0
            ? round(
                ($acceptedCount / $targetEmployees) * 100,
                2
            )
            : 0;

        /*
        =========================================
        FETCH APPLICABLE DIVISION / DEPARTMENT
        =========================================
        */
        $policyId = $row['POLI_ID'];

        $applicableSql = "
            SELECT
                'DIVISION' TYPE,
                DV.DIVSN_DESC NAME

            FROM EPT_HR_POLICY_DIVSN PD

            JOIN EPT_HR_DIVISIONS DV
                ON DV.DIVSN_ID = PD.DIVSN_ID

            WHERE PD.POLICY_ID = :policy_id

            UNION ALL

            SELECT
                'DEPARTMENT' TYPE,
                DP1.DEPT_DESC NAME

            FROM EPT_HR_POLICY_DEPT PD

            JOIN EPT_HR_DEPARTMENT DP1
                ON DP1.DEPT_ID = PD.DEPT_ID

            WHERE PD.POLICY_ID = :policy_id
        ";

        $appStmt = oci_parse($sql___func___con, $applicableSql);

        oci_bind_by_name($appStmt, ":policy_id", $policyId);
        oci_execute($appStmt);

        $divisions = [];
        $departments = [];

        while ($appRow = oci_fetch_assoc($appStmt)) {

            if ($appRow['TYPE'] === 'DIVISION') {
                $divisions[] = $appRow['NAME'];
            } else {
                $departments[] = $appRow['NAME'];
            }
        }
        oci_free_statement($appStmt);

        $data[] = [
            "policy_id" => (int)$row['POLI_ID'],
            "policy_name" => $row['POLICY_NAME'],
            "is_mandatory" => $row['IS_MANDAT'],
            "applicable_divisions" => $divisions,
            "applicable_departments" => $departments,
            "target_employees" => $targetEmployees,
            "accepted_count" => $acceptedCount,
            "pending_count" => $pendingCount,
            "acceptance_percentage" => $acceptancePercentage
        ];
    }
    oci_free_statement($stmt);
    /*
    =========================================
    SUCCESS RESPONSE
    =========================================
    */
    apiResponse(true, "Policies fetched successfully", $data, 200);
}
catch (Throwable $e) {
    /*
    =========================================
    LOG ERROR
    =========================================
    */
    logOracleError($e);
    apiResponse(false, "Unable to fetch policies", null, 500);
}
