<?php

/*
|--------------------------------------------------------------------------
| File        : getEmployeeAccess.php
| Module      : HRMS
| Description : Get employee profile access
|--------------------------------------------------------------------------
*/

ob_start();
define('CURRENT_PORTAL', 'hrms');
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
        apiResponse(
            false,
            "Session expired. Please login again.",
            null,
            401
        );
    }


    /* ==========================================================
       METHOD
    ========================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(
            false,
            "Invalid request method.",
            null,
            405
        );
    }


    /* ==========================================================
       READ REQUEST
    ========================================================== */

    $rawInput = file_get_contents("php://input");

    $request = json_decode(
        $rawInput,
        true
    );

    if (!is_array($request)) {
        apiResponse(
            false,
            "Invalid request payload.",
            null,
            400
        );
    }


    /* ==========================================================
       EMPLOYEE
    ========================================================== */

    $employee = trim(
        (string)($request["employee"] ?? "")
    );

    if ($employee === "") {
        apiResponse(
            false,
            "Employee is required.",
            null,
            400
        );
    }


    /* ==========================================================
       GET EMPLOYEE NAME
       
       HR_EMPLOYEE_INFO does not have EMP_NAME.
       Name is created from FNAME + MNAME + LNAME.
    ========================================================== */

    $employeeSql = "
        SELECT
            EMP_CODE,
            FNAME,
            MNAME,
            LNAME
        FROM HR_EMPLOYEE_INFO
        WHERE EMP_CODE = '{$employee}'
    ";

    $employeeRecord = singRec($employeeSql);

    $employeeName = "";

    if (is_array($employeeRecord) && !empty($employeeRecord)) {

        $fname = trim(
            (string)(
                $employeeRecord["FNAME"]
                ?? $employeeRecord["fname"]
                ?? ""
            )
        );

        $mname = trim(
            (string)(
                $employeeRecord["MNAME"]
                ?? $employeeRecord["mname"]
                ?? ""
            )
        );

        $lname = trim(
            (string)(
                $employeeRecord["LNAME"]
                ?? $employeeRecord["lname"]
                ?? ""
            )
        );

        /*
         * Build full name while automatically
         * ignoring blank middle names.
         */
        $employeeName = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $fname . ' ' . $mname . ' ' . $lname
            )
        );
    }


    /*
     * Fallback in case employee name is not found.
     */
    if ($employeeName === "") {
        $employeeName = $employee;
    }


    /* ==========================================================
       ASSIGNED PROFILES
    ========================================================== */

    $assignedSql = "
        SELECT
            E.ID,
            E.EMP_CODE,
            E.PROFILE_ID,
            P.PROFILE_DESC,
            E.EFFEC_FROM,
            E.EFFEC_TO
        FROM HR_EMP_PROFILE E
        LEFT JOIN HR_PROFILES P
            ON P.PROFILE_ID = E.PROFILE_ID
        WHERE E.EMP_CODE = '{$employee}'
        ORDER BY E.EFFEC_FROM DESC, P.PROFILE_DESC
    ";

    $assignedProfiles = multiRec($assignedSql);

    if (!is_array($assignedProfiles)) {
        $assignedProfiles = [];
    }


    /* ==========================================================
       AVAILABLE PROFILES
       
       Exclude profiles which are currently active
       for this employee.
    ========================================================== */

    $availableSql = "
        SELECT
            P.PROFILE_ID,
            P.PROFILE_DESC
        FROM HR_PROFILES P
        WHERE P.PROFILE_ID NOT IN
        (
            SELECT EP.PROFILE_ID
            FROM HR_EMP_PROFILE EP
            WHERE EP.EMP_CODE = '{$employee}'
              AND SYSDATE BETWEEN
                  NVL(
                      EP.EFFEC_FROM,
                      TO_DATE(
                          '01-MAR-2000',
                          'DD-MON-YYYY'
                      )
                  )
                  AND
                  NVL(
                      EP.EFFEC_TO,
                      TO_DATE(
                          '01-MAR-3000',
                          'DD-MON-YYYY'
                      )
                  )
        )
        ORDER BY P.PROFILE_DESC
    ";

    $availableProfiles = multiRec($availableSql);

    if (!is_array($availableProfiles)) {
        $availableProfiles = [];
    }


    /* ==========================================================
       NORMALIZE ASSIGNED PROFILES
    ========================================================== */

    $assignedData = [];

    foreach ($assignedProfiles as $row) {

        $id =
            $row["ID"]
            ?? $row["id"]
            ?? null;

        $profileId =
            $row["PROFILE_ID"]
            ?? $row["profile_id"]
            ?? null;

        $profileDesc =
            $row["PROFILE_DESC"]
            ?? $row["profile_desc"]
            ?? "";

        $effecFrom =
            $row["EFFEC_FROM"]
            ?? $row["effec_from"]
            ?? "";

        $effecTo =
            $row["EFFEC_TO"]
            ?? $row["effec_to"]
            ?? "";

        $assignedData[] = [

            "id" => $id,

            /*
             * IMPORTANT:
             * Return employee NAME instead of EMP_CODE
             * for DataTable display.
             */
            "employee" => $employeeName,

            /*
             * Keep employee code available as well.
             * Useful if you need it later.
             */
            "employeeCode" => $employee,

            "profileId" => $profileId,

            "profile" => $profileDesc,

            "effecFrom" => $effecFrom,

            "effecTo" => $effecTo,

            "active" => empty($effecTo)
        ];
    }


    /* ==========================================================
       NORMALIZE AVAILABLE PROFILES
    ========================================================== */

    $availableData = [];

    foreach ($availableProfiles as $row) {

        $profileId =
            $row["PROFILE_ID"]
            ?? $row["profile_id"]
            ?? null;

        $profileDesc =
            $row["PROFILE_DESC"]
            ?? $row["profile_desc"]
            ?? "";

        if (
            $profileId === null ||
            $profileId === ""
        ) {
            continue;
        }

        $availableData[] = [

            "id" => (string)$profileId,

            "label" => $profileDesc,

            "profileId" => (string)$profileId,

            "profileDesc" => $profileDesc
        ];
    }


    /* ==========================================================
       RESPONSE
    ========================================================== */

    apiResponse(
        true,
        "Employee access fetched successfully.",
        [

            /*
             * Employee information
             */
            "employee" => $employee,

            "employeeName" => $employeeName,

            /*
             * Profile dropdown
             */
            "availableProfiles" => $availableData,

            /*
             * DataTable
             */
            "assignedProfiles" => $assignedData
        ],
        200
    );


} catch (Throwable $e) {

    logOracleError([
        "message" => $e->getMessage(),
        "file"    => $e->getFile(),
        "line"    => $e->getLine()
    ]);

    apiResponse(
        false,
        "Unable to fetch employee access.",
        null,
        500,
        [$e->getMessage()]
    );
}