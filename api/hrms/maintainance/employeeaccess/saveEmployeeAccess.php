<?php

/*
|--------------------------------------------------------------------------
| File        : saveEmployeeAccess.php
| Module      : HRMS
| Description : Save Employee Profile Access
|--------------------------------------------------------------------------
|
| Payload:
|
| {
|     "employeeCode": "05362",
|     "profileIds": ["1", "2", "5"]
| }
|
|--------------------------------------------------------------------------
*/

ob_start();

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

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

    if (!isset($_SESSION["emp_code"]) ||  empty($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    /* ==========================================================
       REQUEST METHOD VALIDATION
    ========================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* ==========================================================
       READ JSON REQUEST
    ========================================================== */

    $rawInput = file_get_contents("php://input");

    $request = json_decode(
        $rawInput,
        true
    );

    /* ==========================================================
       JSON VALIDATION
    ========================================================== */

    if (!is_array($request)) {
        apiResponse(false, "Invalid request payload.", null, 400);
    }

    /* ==========================================================
       EMPLOYEE CODE
    ========================================================== */

    $employeeCode = trim((string)($request["employee"]?? $request["EMPLOYEE"] ?? ""));

    if ($employeeCode === "") {
        apiResponse(false, "Employee is required.", null, 400);
    }

    /*
     * HR_EMPLOYEE_INFO.EMP_CODE is VARCHAR2.
     *
     * Therefore we don't force ctype_digit() here.
     * This keeps the API compatible if employee codes
     * contain alphabetic characters in the future.
     */


    /* ==========================================================
       PROFILE IDS
    ========================================================== */

    $profileIds = (isset($request["profileIds"]) && is_array($request["profileIds"]))
        ? $request["profileIds"]
        : [];


    /* ==========================================================
       REMOVE EMPTY VALUES
    ========================================================== */

    $profileIds = array_values(
        array_unique(
            array_filter(
                $profileIds,
                function ($value) {
                    return $value !== ""
                        && $value !== null;
                }
            )
        )
    );

    /* ==========================================================
       PROFILE VALIDATION
    ========================================================== */

    if (empty($profileIds)) {
        apiResponse(false, "Please select at least one profile.", null, 400);
    }

    /*
     * HR_PROFILES.PROFILE_ID is expected to be numeric.
     *
     * Validate every profile ID before putting it into SQL.
     */

    foreach ($profileIds as $profileId) {

        $profileId = trim((string)$profileId);

        if ($profileId === "" || !ctype_digit($profileId)) {
            apiResponse(false, "Invalid profile selected.", null, 400);
        }
    }


    /* ==========================================================
       LOGGED-IN USER
    ========================================================== */

    $empCode = trim(
        (string)$_SESSION["emp_code"]
    );

    if ($empCode === "") {
        apiResponse(false, "Unable to identify logged-in user.", null, 401);
    }

    /*
     * Escape values because the existing common executeQry()
     * implementation is being used with raw SQL.
     */

    $employeeCodeSql = str_replace(
        "'",
        "''",
        $employeeCode
    );

    $empCodeSql = str_replace(
        "'",
        "''",
        $empCode
    );


    /* ==========================================================
       START TRANSACTION
    ========================================================== */

    startQry();


    /* ==========================================================
       INSERT PROFILE ACCESS
    ========================================================== */

    $insertedCount = 0;

    foreach ($profileIds as $profileId) {

        $profileId = trim((string)$profileId);


        /*
         * Old PHP code:
         *
         * INSERT INTO HR_EMP_PROFILE
         * (
         *     ID,
         *     EMP_CODE,
         *     PROFILE_ID,
         *     EFFEC_FROM,
         *     CHG_ON,
         *     CHG_BY
         * )
         * VALUES
         * (
         *     NULL,
         *     employee,
         *     profile,
         *     SYSDATE,
         *     SYSDATE,
         *     logged-in-user
         * )
         *
         * We are preserving the same behavior.
         */

        $sql = "
            INSERT INTO HR_EMP_PROFILE
            (
                ID,
                EMP_CODE,
                PROFILE_ID,
                EFFEC_FROM,
                CHG_ON,
                CHG_BY
            )
            VALUES
            (
                NULL,
                '{$employeeCodeSql}',
                '{$profileId}',
                SYSDATE,
                SYSDATE,
                '{$empCodeSql}'
            )
        ";


        $result = executeQry($sql);


        if ($result === false) {

            throw new Exception(
                "Failed to save profile ID {$profileId}."
            );
        }


        $insertedCount++;
    }


    /* ==========================================================
       COMMIT TRANSACTION
    ========================================================== */

    endQry(
        "Employee access saved successfully."
    );


    /* ==========================================================
       CLOSE CONNECTION
    ========================================================== */

    if (isset($sql___func___con)) {
        @oci_close($sql___func___con);
    }


    /* ==========================================================
       SUCCESS RESPONSE
    ========================================================== */

    apiResponse(
        true,
        "Employee access saved successfully.",
        [
            "employeeCode" => $employeeCode,
            "profileIds"   => array_values($profileIds),
            "savedCount"   => $insertedCount
        ],
        200
    );

}


/* ==============================================================
   ERROR HANDLING
============================================================== */

catch (Throwable $e) {

    /* ==========================================================
       ROLLBACK
    ========================================================== */

    if (isset($sql___func___con)) {

        @oci_rollback(
            $sql___func___con
        );
    }


    /* ==========================================================
       LOG ERROR
    ========================================================== */

    logOracleError([
        "message" => $e->getMessage(),
        "file"    => $e->getFile(),
        "line"    => $e->getLine()
    ]);


    /* ==========================================================
       ERROR RESPONSE
    ========================================================== */

    apiResponse(
        false,
        "Unable to save employee access.",
        null,
        500,
        [
            $e->getMessage()
        ]
    );
}