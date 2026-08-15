<?php

/*
|--------------------------------------------------------------------------
| File        : getEmployees.php
| Module      : HRMS
| Description : Get employees for Employee Access
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
       SESSION VALIDATION
    ========================================================== */

    if (!isset($_SESSION["emp_code"]) || empty($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    /* ==========================================================
       REQUEST METHOD
    ========================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* ==========================================================
       GET EMPLOYEES
    ========================================================== */

   $sql = "
    SELECT
        EMP_CODE,
        FNAME,
        MNAME,
        LNAME
    FROM HR_EMPLOYEE_INFO
    WHERE NVL(STATUS, 'A') = 'A'
    ORDER BY FNAME, MNAME, LNAME
";

$employees = multiRec($sql);

if (!is_array($employees)) {
    $employees = [];
}

/* ==========================================================
   NORMALIZE RESPONSE
========================================================== */

$data = [];

foreach ($employees as $employee) {

    $empCode = trim(
        (string)($employee["EMP_CODE"] ?? $employee["emp_code"] ?? "")
    );

    if ($empCode === "") {
        continue;
    }

    /* ======================================================
       EMPLOYEE NAME
       
       HR_EMPLOYEE_INFO does not have EMP_NAME.
       Construct name from FNAME + MNAME + LNAME.
    ====================================================== */

    $fname = trim((string)($employee["FNAME"] ?? $employee["fname"] ?? ""));
    $mname = trim((string)($employee["MNAME"] ?? $employee["mname"] ?? ""));
    $lname = trim((string)($employee["LNAME"] ?? $employee["lname"] ?? ""));

    $nameParts = array_filter(
        [$fname, $mname, $lname],
        fn($value) => $value !== ""
    );

    $empName = implode(" ", $nameParts);

    /* ======================================================
       RESPONSE
    ====================================================== */

    $data[] = [
        "id"      => $empCode,
        "label"   => $empCode . " - " . $empName,
        "empCode" => $empCode,
        "empName" => $empName,
    ];
}

    apiResponse(true, "Employees fetched successfully.", $data, 200);

} catch (Throwable $e) {

    logOracleError([
        "message" => $e->getMessage(),
        "file"    => $e->getFile(),
        "line"    => $e->getLine()
    ]);

    apiResponse(false, "Unable to fetch employees.", null, 500, [$e->getMessage()]);
}
