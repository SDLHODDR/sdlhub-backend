<?php
ob_start();

require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/session.php";
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

    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

    /*
       Remove this after testing  */
       $year = 2025;
   

    /* ========================
       Get Employee Holiday Group
       ======================== */

    $empSql = "
        SELECT HOL_TBLNO
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '$empCode'
    ";

    $employee = singRec($empSql);

    if (empty($employee)) {
        apiResponse(false, "Employee holiday group not found", null, 404);
    }

    $holGrp = $employee['HOL_TBLNO'];

    /* ========================
       Fetch Holiday Rules
       ======================== */

    $sql = "
        SELECT
            ID,
            DESCR
        FROM EPT_BCS_HOLIDAYS_INSTR
        WHERE HOL_GRP = '$holGrp'
        AND HOL_YEAR = '$year'
        ORDER BY ID ASC
    ";
    $sqlRaw = multiRec($sql);

    /* ========================
       Prepare Response Data
       ======================== */

    $rules = [];

    foreach ($sqlRaw as $row) {

        // Remove html wrapper if exists
        $cleanHtml = preg_replace(
            '/<\/?html>/i',
            '',
            $row['DESCR']
        );

        $rules[] = [
            "id"    => $row['ID'],
            "descr" => trim($cleanHtml)
        ];
    }

    /* ========================
       Success Response
       ======================== */

    apiResponse(true, "Holiday rules fetched successfully", $rules, 200,
        [
            "year"  => $year,
            "count" => count($rules)
        ]
    );

} catch (Throwable $e) {

    /* ========================
       Oracle Error Logging
       ======================== */

    logOracleError($e);
    apiResponse(false, "Unable to fetch holiday rules", null, 500);
}
