<?php
ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header('Content-Type: application/json');

try {
    /* ========================
       Session Validation
       ======================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (!$empCode) {
        apiResponse(false, "Session expired", null, 401);
    }

    /* ========================
       Get Year Parameter
       ======================== */

    $year = isset($_GET['year'])
        ? intval($_GET['year'])
        : date('Y');

    $strtDate = "01-Jan-$year";
    $endDate  = "31-Dec-$year";

    /* ========================
       Get Employee Holiday Group
       ======================== */

    $employeeSql = "
        SELECT HOL_TBLNO
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    ";
    $employee = singRec($employeeSql);

    if (empty($employee)) {
        apiResponse(false, "Employee holiday group not found", null,404);
    }
    $holGrp = $employee['HOL_TBLNO'];

    /* ========================
       Fetch Holidays
       ======================== */

    $sql = "
        SELECT
            BH.ID,
            BH.DESCR,
            TRIM(TO_CHAR(BH.HOL_DATE, 'DAY')) AS FULL_DAY,
            TO_CHAR(BH.HOL_DATE,'YYYY-MM-DD') AS HOLDATE,
            BH.HOL_TYPE
        FROM EPT_BCS_HOLIDAYS BH
        WHERE BH.HOL_GRP = '$holGrp'
        AND BH.HOL_TYPE IN ('H','O')
        AND BH.HOL_DATE BETWEEN
            TO_DATE('$strtDate','DD-Mon-YYYY')
            AND TO_DATE('$endDate','DD-Mon-YYYY')
        ORDER BY BH.HOL_DATE
    ";

    $sqlRaw = multiRec($sql);

    /* ========================
       Prepare Response
       ======================== */

    $holidays = [];

    foreach ($sqlRaw as $row) {
        $holidays[] = [
            "id"    => $row['ID'],
            "title" => trim($row['DESCR']),
            "date"  => $row['HOLDATE'],
            "day"   => trim($row['FULL_DAY']),
            "type"  => $row['HOL_TYPE']
        ];
    }

    /* ========================
       Success Response
       ======================== */
    apiResponse(true, "Holiday list fetched successfully",$holidays, 200,[],
        [
            "year"  => $year,
            "count" => count($holidays)
        ]
    );

} catch (Throwable $e) {
    /* ========================
       Error Logging
       ======================== */

    logOracleError($e);
    apiResponse(false, "Unable to fetch holidays", null, 500);

}
