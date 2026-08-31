<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

$checkStmt = null;
$stmt = null;

try {

    /*
    |--------------------------------------------------------------------------
    | METHOD CHECK
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method", null, 405);
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION CHECK
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    $empCode = trim($_SESSION["emp_code"]);

    /*
    |--------------------------------------------------------------------------
    | READ INPUT
    |--------------------------------------------------------------------------
    */

    //$dataReq = json_decode(file_get_contents("php://input"), true);
    $dataReq = $_GET;
    /*
    |--------------------------------------------------------------------------
    | PAGINATION
    |--------------------------------------------------------------------------
    */

    $page = max((int)($dataReq["page"] ?? 1), 1);

    $limit = min(
        max((int)($dataReq["limit"] ?? 10), 1),
        100
    );

    $offset = ($page - 1) * $limit;

    /*
    --------------------------------------------------------------------------
    DATE RANGE
    --------------------------------------------------------------------------

        Fetch only records from one month before today onward.

        Example:
        Today = 31-Aug-2026
        From = 31-Jul-2026

        TRUNC(SYSDATE) removes the current time portion.
    */

    /*
    |--------------------------------------------------------------------------
    | TOTAL RECORDS
    |--------------------------------------------------------------------------
    */

    $countSql = "
        SELECT COUNT(*) AS TOTAL
        FROM EPT_CONF_ROOM_TRAN C
        WHERE
            (
                C.CHG_BY = :emp_code
                OR C.BOOK_BY_EMP = :emp_code
            )
        AND C.STATUS IN ('A','X','N','T','R')
        AND C.START_TIME >= ADD_MONTHS( TRUNC(SYSDATE), -1 )
    ";

    $checkStmt = oci_parse(
        $sql___func___con,
        $countSql
    );

    if (!$checkStmt) {
        $e = oci_error($sql___func___con);
        throw new Exception($e["message"]);
    }

    oci_bind_by_name(
        $checkStmt,
        ":emp_code",
        $empCode
    );

    if (!oci_execute($checkStmt)) {
        $e = oci_error($checkStmt);
        throw new Exception($e["message"]);
    }

    $countRow = oci_fetch_assoc($checkStmt);

    $totalRecords = (int)($countRow["TOTAL"] ?? 0);

    oci_free_statement($checkStmt);
    $checkStmt = null;

        /*
    |--------------------------------------------------------------------------
    | FETCH PAGINATED DATA
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            C.ID,
            C.ROOM_ID,
            R.ROOM_LABEL,

            TO_CHAR(C.START_TIME,'dd-Mon-yyyy') DT,
            TO_CHAR(C.START_TIME,'HH24:MI') STARTTIME,
            TO_CHAR(C.END_TIME,'HH24:MI') ENDTIME,

            C.BOOK_TIME,
            C.STATUS,
            C.BOOK_BY_EMP,
            C.NOOF_ATTD,
            C.REMARKS,

            TO_CHAR(C.CHG_ON,'dd-Mon-yyyy') CHG_ON,

            C.ROOM_FACL1,
            C.ROOM_FACL2,
            C.ROOM_FACL3,
            C.CHG_BY,
            C.DIVSN_ID,

            INITCAP(E.FNAME || ' ' || E.LNAME) AS BOOK_BY_NAME

        FROM EPT_CONF_ROOM_TRAN C

        LEFT JOIN EPT_CONF_ROOMS R
            ON R.ID = C.ROOM_ID

        LEFT JOIN EPT_HR_EMPLOYEE_INFO E
            ON E.EMP_CODE = C.BOOK_BY_EMP

        WHERE
            (
                C.CHG_BY = :emp_code
                OR C.BOOK_BY_EMP = :emp_code
            )
        AND C.STATUS IN ('A','X','N','T','R')

        AND C.START_TIME >= ADD_MONTHS( TRUNC(SYSDATE), -1 )

        ORDER BY C.START_TIME DESC

        OFFSET :offset ROWS
        FETCH NEXT :limit ROWS ONLY
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $sql
    );

    if (!$stmt) {
        $e = oci_error($sql___func___con);
        throw new Exception($e["message"]);
    }

    oci_bind_by_name(
        $stmt,
        ":emp_code",
        $empCode
    );

    oci_bind_by_name(
        $stmt,
        ":offset",
        $offset,
        -1,
        SQLT_INT
    );

    oci_bind_by_name(
        $stmt,
        ":limit",
        $limit,
        -1,
        SQLT_INT
    );

    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        throw new Exception($e["message"]);
    }

    $data = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $data[] = $row;
    }

    oci_free_statement($stmt);
    $stmt = null;

        /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    apiResponse(
        true,
        "Booking history fetched successfully.",
        [
            "data" => $data,
            "page" => $page,
            "limit" => $limit,
            "totalRecords" => $totalRecords,
            "totalPages" => (int) ceil($totalRecords / $limit)
        ]
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    logOracleError($e);
    apiResponse(false, "Unable to fetch booking history.", null, 500);

} finally {

    /*
    |--------------------------------------------------------------------------
    | FREE OCI STATEMENTS
    |--------------------------------------------------------------------------
    */

    if ($checkStmt) {
        oci_free_statement($checkStmt);
    }

    if ($stmt) {
        oci_free_statement($stmt);
    }

    /*
    |--------------------------------------------------------------------------
    | CLOSE DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}