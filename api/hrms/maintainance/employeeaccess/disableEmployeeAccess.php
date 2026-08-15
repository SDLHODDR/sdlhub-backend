<?php

/*
|--------------------------------------------------------------------------
| File        : disableEmployeeAccess.php
| Module      : HRMS
| Description : Disable employee profile access
|--------------------------------------------------------------------------
*/

ob_start();

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";
require_once __DIR__ . "/../../../config/env.php";

header("Content-Type: application/json");

try {

    /* ==========================================================
       SESSION
    ========================================================== */

    if (!isset($_SESSION["emp_code"]) || empty($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    /* ==========================================================
       METHOD
    ========================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* ==========================================================
       REQUEST
    ========================================================== */

    $rawInput = file_get_contents("php://input");

    $request = json_decode(
        $rawInput,
        true
    );

    if (!is_array($request)) {
        apiResponse(false, "Invalid request payload.", null, 400);
    }

    /* ==========================================================
       ID
    ========================================================== */

    $id = trim((string)($request["id"] ?? ""));

    if ($id === "" || !ctype_digit($id)) {
        apiResponse(false, "Invalid access record.", null, 400);
    }

    /* ==========================================================
       UPDATE
    ========================================================== */

    $empCode = trim((string)$_SESSION["emp_code"]);

    startQry();

    $sql = "
        UPDATE HR_EMP_PROFILE
        SET
            EFFEC_TO = SYSDATE,
            CHG_ON   = SYSDATE,
            CHG_BY   = '{$empCode}'
        WHERE ID = '{$id}'
          AND EFFEC_TO IS NULL
    ";

    $result = executeQry($sql);

    if ($result === false) {
        throw new Exception("Unable to disable employee profile access.");
    }
    endQry();

    oci_close($sql___func___con);

    apiResponse(true, "Employee profile access disabled successfully.", null, 200);

} catch (Throwable $e) {

    if (isset($sql___func___con)) {
        @oci_rollback(
            $sql___func___con
        );
    }

    logOracleError([
        "message" => $e->getMessage(),
        "file"    => $e->getFile(),
        "line"    => $e->getLine()
    ]);

    apiResponse(
        false,
        "Unable to disable employee profile access.",
        null,
        500,
        [$e->getMessage()]
    );
}
