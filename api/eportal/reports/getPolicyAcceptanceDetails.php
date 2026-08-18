<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json");
require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";


try {

    $con = db_eportal();

    if (!$con) {
		apiResponse(false, "Database connection failed.", null, 500);
	}

    /* ========================================
        SESSION VALIDATION
    ========================================= */
    if (!isset($_SESSION['emp_code'])) {
		apiResponse(false, "Unauthorized Access", null, 401);
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
		apiResponse(false, "Policy ID is required.", null, 400);
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
            TO_CHAR(START_DATE,'dd-Mon-yyyy') START_DATE,
            TO_CHAR(END_DATE,'dd-Mon-yyyy') END_DATE
        FROM EPT_HR_POLICY
        WHERE POLI_ID = :policy_id
    ";

    $policyStmt = oci_parse($con, $policySql);

	if (!$policyStmt) {
		$e = oci_error($con);
		logOracleError($e, $policySql);
		apiResponse(false, "Unable to prepare policy query.", null, 500);
	}

	oci_bind_by_name($policyStmt, ":policy_id", $policyId);

	if (!oci_execute($policyStmt)) {
		$e = oci_error($policyStmt);
		logOracleError($e, $policySql);
		apiResponse(false, "Unable to fetch policy details.", null, 500);
	}

	$policy = oci_fetch_assoc($policyStmt);

	if (!$policy) {
		apiResponse(false, "Policy not found.", null, 404);
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

	if (!$summaryStmt) {
		$e = oci_error($con);
		logOracleError($e, $summarySql);
		apiResponse(false, "Unable to prepare summary query.", null, 500);
	}

	oci_bind_by_name($summaryStmt, ":policy_id", $policyId);

	if (!oci_execute($summaryStmt)) {
		$e = oci_error($summaryStmt);
		logOracleError($e, $summarySql);
		apiResponse(false, "Unable to fetch summary.", null, 500);
	}
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
			'dd-Mon-yyyy HH24:MI:SS'
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

	if (!$detailStmt) {
		$e = oci_error($con);
		logOracleError($e, $detailSql);
		apiResponse(false, "Unable to prepare employee query.", null, 500);
	}

	oci_bind_by_name($detailStmt, ":policy_id", $policyId);

	if (!oci_execute($detailStmt)) {

		$e = oci_error($detailStmt);
		logOracleError($e, $detailSql);

		apiResponse(
			false,
			"Unable to fetch employee details.",
			null,
			500
		);
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

    apiResponse(
    true,
    "Policy acceptance report loaded successfully.",
		[
			"summary" => [
				"policy_id" => $policy["POLI_ID"],
				"policy_name" => $policy["POLICY_NAME"],
				"mandatory" => $policy["IS_MANDAT"],
				"start_date" => $policy["START_DATE"],
				"end_date" => $policy["END_DATE"],
				"total_employees" => $totalEmployees,
				"accepted_count" => $acceptedCount,
				"pending_count" => $pendingCount,
				"acceptance_percentage" =>
					$totalEmployees > 0
						? round(($acceptedCount / $totalEmployees) * 100, 2)
						: 0
			],
			"employees" => $employees
		]
	);
}
catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "Policy Acceptance Report API"
    );

    apiResponse(
        false,
        "Something went wrong while loading the report.",
        null,
        500
    );
}
finally {

    if (isset($policyStmt) && is_object($policyStmt)) {
		oci_free_statement($policyStmt);
	}

    if (isset($summaryStmt) && is_object($summaryStmt)) {
        oci_free_statement($summaryStmt);
    }

    if (isset($detailStmt) && is_object($detailStmt)) {
        oci_free_statement($detailStmt);
    }

	   if (!empty($con)) {
		oci_close($con);
	}
}
