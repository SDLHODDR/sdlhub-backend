<?php

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {
    /*
    =========================================
    SESSION VALIDATION
    =========================================
    */

    $empCodeSession = $_SESSION["emp_code"] ?? "";

    if (!$empCodeSession) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /*
    =========================================
    REQUEST INPUT
    =========================================
    */

    $input = json_decode(file_get_contents("php://input"), true);

    $financialYear = trim($input["financial_year"] ?? "");
    $empCode = trim($input["emp_code"] ?? "");
    $downloadType = trim($input["download_type"] ?? "");
    $fromDate = trim($input["from_date"] ?? "");
    $toDate = trim($input["to_date"] ?? "");

    /*
    =========================================
    BUILD WHERE CLAUSE
    =========================================
    */

    $where = " WHERE 1=1 ";

    if (!empty($financialYear)) {
        $where .= "
            AND FINANCIAL_YEAR = :financial_year
        ";
    }

    if (!empty($empCode)) {
        $where .= "
            AND EMP_CODE = :emp_code
        ";
    }

    if (!empty($downloadType)) {
        $where .= "
            AND DOWNLOAD_TYPE = :download_type
        ";
    }

    if (!empty($fromDate)) {
        $where .= "
            AND TRUNC(DOWNLOAD_TIME) >=
            TO_DATE(:from_date,'YYYY-MM-DD')
        ";
    }

    if (!empty($toDate)) {
        $where .= "
            AND TRUNC(DOWNLOAD_TIME) <=
            TO_DATE(:to_date,'YYYY-MM-DD')
        ";
    }

    /*
    =========================================
    SUMMARY QUERY
    =========================================
    */

    $summarySql = "
        SELECT
            COUNT(*) TOTAL_DOWNLOADS,
            COUNT(DISTINCT EMP_CODE) UNIQUE_USERS,
            SUM(
                CASE
                    WHEN STATUS = 'SUCCESS'
                    THEN 1
                    ELSE 0
                END
            ) SUCCESS_COUNT,
            SUM(
                CASE
                    WHEN STATUS <> 'SUCCESS'
                    THEN 1
                    ELSE 0
                END
            ) FAILED_COUNT
        FROM EPT_ITR_DOWNLOAD_LOG
        $where
    ";

    $summaryStmt = oci_parse($con, $summarySql);

    bindFilters(
        $summaryStmt,
        $financialYear,
        $empCode,
        $downloadType,
        $fromDate,
        $toDate
    );

    oci_execute($summaryStmt);

    $summary = oci_fetch_assoc($summaryStmt);

    /*
    =========================================
    REPORT DATA QUERY
    =========================================
    */

    $sql = "

        SELECT
            LOG_ID,
            EMP_CODE,
            DOWNLOAD_TYPE,
            TARGET_EMP_CODE,
            FINANCIAL_YEAR,
            FILE_NAME,
            FILE_SIZE_MB,
            IP_ADDRESS,
            USER_AGENT,
            BROWSER_NAME,
            STATUS,
            REMARKS,
            TO_CHAR(
                DOWNLOAD_TIME,
                'DD-Mon-YYYY HH24:MI:SS'
            ) DOWNLOAD_TIME
        FROM EPT_ITR_DOWNLOAD_LOG
        $where
        ORDER BY DOWNLOAD_TIME DESC
    ";

    $stmt = oci_parse($con, $sql);

    bindFilters(
        $stmt,
        $financialYear,
        $empCode,
        $downloadType,
        $fromDate,
        $toDate
    );

    oci_execute($stmt);

    $data = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $data[] = $row;
    }

    /*
    =========================================
    SUCCESS RESPONSE
    =========================================
    */

    apiResponse(
        true,
        "ITR download logs fetched successfully",
        [
            "summary" => [
                "total_downloads" => (int) ($summary["TOTAL_DOWNLOADS"] ?? 0),

                "unique_users" => (int) ($summary["UNIQUE_USERS"] ?? 0),

                "success_count" => (int) ($summary["SUCCESS_COUNT"] ?? 0),

                "failed_count" => (int) ($summary["FAILED_COUNT"] ?? 0),
            ],

            "data" => $data,
        ],
        200
    );
} catch (Throwable $e) {
    logOracleError($e);

    apiResponse(false, "Unable to fetch ITR download logs", null, 500);
}

/*
=========================================
COMMON BIND FUNCTION
=========================================
*/

function bindFilters(
    $stmt,
    $financialYear,
    $empCode,
    $downloadType,
    $fromDate,
    $toDate
) {
    if (!empty($financialYear)) {
        oci_bind_by_name($stmt, ":financial_year", $financialYear);
    }

    if (!empty($empCode)) {
        oci_bind_by_name($stmt, ":emp_code", $empCode);
    }

    if (!empty($downloadType)) {
        oci_bind_by_name($stmt, ":download_type", $downloadType);
    }

    if (!empty($fromDate)) {
        oci_bind_by_name($stmt, ":from_date", $fromDate);
    }

    if (!empty($toDate)) {
        oci_bind_by_name($stmt, ":to_date", $toDate);
    }
}
