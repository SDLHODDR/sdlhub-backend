<?php

/*
|--------------------------------------------------------------------------
| File        : saveProfileAccess.php
| Module      : HRMS
| Description : Save Profile Menu / Company / Division /
|               Department / Task / Dashboard Access
|--------------------------------------------------------------------------
{
  "profileId": "1",
  "accessType": "task",
  "taskIds": ["10", "11", "12"]
}
*/

ob_start();

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

/*
|--------------------------------------------------------------------------
| HRMS DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

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
       REQUEST METHOD VALIDATION
    ========================================================== */

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    /* ==========================================================
       READ RAW JSON
    ========================================================== */

    $rawInput = file_get_contents("php://input");

    if ($rawInput === false || trim($rawInput) === "") {
        apiResponse(false, "Empty request payload.", null, 400);
    }

    /* ==========================================================
       DECODE JSON
    ========================================================== */

    $request = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
        apiResponse(false, "Invalid JSON request payload.", null, 400);
    }

    /* ==========================================================
       PROFILE ID
    ========================================================== */

    $profileId = trim((string)($request["profileId"] ?? ""));

    if ($profileId === "") {
        apiResponse(false, "Profile is required.", null, 400);
    }

    if (!ctype_digit($profileId)) {
        apiResponse(false, "Invalid profile.", null, 400);
    }

    /* ==========================================================
       ACCESS TYPE
    ========================================================== */

    $accessType = strtolower(trim((string)($request["accessType"] ?? "")));

    $allowedAccessTypes = [
        "menu",
        "company",
        "division",
        "department",
        "task",
        "dashboard"
    ];

    if (!in_array($accessType, $allowedAccessTypes, true)) {

        /*
         * Keep this message explicit while testing.
         * This will immediately tell us what PHP received.
         */

        apiResponse(
            false,
            "Invalid access type.",
            [
                "receivedAccessType" => $accessType,
                "allowedAccessTypes" => $allowedAccessTypes
            ],
            400
        );
    }

    /* ==========================================================
       LOGGED-IN EMPLOYEE
    ========================================================== */

    $empCode = trim((string)$_SESSION["emp_code"]);

    if ($empCode === "") {
        apiResponse(false, "Invalid logged-in employee.", null, 401);
    }

    /* ==========================================================
       HELPER - GET ARRAY FROM REQUEST
    ========================================================== */

    $getRequestArray = function (
        array $request,
        string $key
    ): array {

        if (
            !isset($request[$key]) ||
            !is_array($request[$key])
        ) {
            return [];
        }

        return $request[$key];
    };


    /* ==========================================================
       NORMALIZE IDS
    ========================================================== */

    $normalizeIds = function (
        array $ids
    ): array {

        $result = [];

        foreach ($ids as $id) {

            if (
                $id === null ||
                $id === ""
            ) {
                continue;
            }

            $id = trim((string)$id);

            /*
             * IDs should be numeric.
             */

            if (!ctype_digit($id)) {
                continue;
            }

            $result[] = $id;
        }

        return array_values(
            array_unique($result)
        );
    };


    /* ==========================================================
       GET ONLY REQUIRED ARRAY
    ========================================================== */

    $selectedIds = [];


    switch ($accessType) {

        case "menu":

            $selectedIds = $normalizeIds(
                $getRequestArray(
                    $request,
                    "subMenuIds"
                )
            );

            break;


        case "company":

            $selectedIds = $normalizeIds(
                $getRequestArray(
                    $request,
                    "companyIds"
                )
            );

            break;


        case "division":

            $selectedIds = $normalizeIds(
                $getRequestArray(
                    $request,
                    "divisionIds"
                )
            );

            break;


        case "department":

            $selectedIds = $normalizeIds(
                $getRequestArray(
                    $request,
                    "departmentIds"
                )
            );

            break;


        case "task":

            $selectedIds = $normalizeIds(
                $getRequestArray(
                    $request,
                    "taskIds"
                )
            );

            break;


        case "dashboard":

            $selectedIds = $normalizeIds(
                $getRequestArray(
                    $request,
                    "dashboardIds"
                )
            );

            break;


        default:

            apiResponse(
                false,
                "Invalid access type.",
                null,
                400
            );
    }


    /* ==========================================================
       DATABASE CONFIGURATION
    ========================================================== */

    $saveConfig = [

        /*
        |--------------------------------------------------------------------------
        | MENU
        |--------------------------------------------------------------------------
        */

        "menu" => [

            "table" => "HR_PROFILE_MENU",

            "idColumn" => "SUB_MENU_ID",

            "deleteSql" => "
                DELETE FROM HR_PROFILE_MENU
                WHERE PROFILE_ID = '{$profileId}'
            ",

            "extraColumns" => [
                "STATUS" => "A"
            ]
        ],


        /*
        |--------------------------------------------------------------------------
        | COMPANY
        |--------------------------------------------------------------------------
        */

        "company" => [

            "table" => "HR_PROFILE_COMPANY",

            "idColumn" => "COMP_ID",

            "deleteSql" => "
                DELETE FROM HR_PROFILE_COMPANY
                WHERE PROFILE_ID = '{$profileId}'
            ",

            "extraColumns" => [
                "STATUS" => "A"
            ]
        ],


        /*
        |--------------------------------------------------------------------------
        | DIVISION
        |--------------------------------------------------------------------------
        */

        "division" => [

            "table" => "HR_PROFILE_DIVISIONS",

            "idColumn" => "DIVISION_ID",

            "deleteSql" => "
                DELETE FROM HR_PROFILE_DIVISIONS
                WHERE PROFILE_ID = '{$profileId}'
            ",

            "extraColumns" => [
                "STATUS" => "A"
            ]
        ],


        /*
        |--------------------------------------------------------------------------
        | DEPARTMENT
        |--------------------------------------------------------------------------
        */

        "department" => [

            "table" => "HR_PROFILE_DEPARTMENT",

            "idColumn" => "DEPT_ID",

            "deleteSql" => "
                DELETE FROM HR_PROFILE_DEPARTMENT
                WHERE PROFILE_ID = '{$profileId}'
            ",

            "extraColumns" => [
                "STATUS" => "A"
            ]
        ],


        /*
        |--------------------------------------------------------------------------
        | TASK
        |--------------------------------------------------------------------------
        */

        "task" => [

            "table" => "HR_PROFILE_TASK",

            "idColumn" => "TASK_ID",

            "deleteSql" => "
                DELETE FROM HR_PROFILE_TASK
                WHERE PROFILE_ID = '{$profileId}'
            ",

            "extraColumns" => []
        ],


        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        "dashboard" => [

            "table" => "HR_PROFILE_DASHBOARD",

            "idColumn" => "DASH_ID",

            "deleteSql" => "
                DELETE FROM HR_PROFILE_DASHBOARD
                WHERE PROFILE_ID = '{$profileId}'
            ",

            "extraColumns" => [
                "STATUS" => "A"
            ]
        ]

    ];


    /* ==========================================================
       VERIFY CONFIG
    ========================================================== */

    if (!isset($saveConfig[$accessType])) {

        throw new Exception(
            "Invalid access configuration."
        );
    }


    $config = $saveConfig[$accessType];


    /* ==========================================================
       START TRANSACTION
    ========================================================== */

    startQry();


    /* ==========================================================
       DELETE EXISTING ACCESS
    ========================================================== */

    $deleteResult = executeQry(
        $config["deleteSql"]
    );


    if ($deleteResult === false) {

        throw new Exception(
            "Failed to delete existing {$accessType} access."
        );
    }


    /* ==========================================================
       INSERT SELECTED ACCESS
    ========================================================== */

    foreach ($selectedIds as $id) {

        /*
         * ID is intentionally NULL because the existing
         * BEFORE INSERT trigger generates the ID.
         */

        $columns = [
            "ID",
            "PROFILE_ID",
            $config["idColumn"]
        ];

        $values = [
            "NULL",
            "'{$profileId}'",
            "'{$id}'"
        ];


        /* ======================================================
           EXTRA COLUMNS
        ====================================================== */

        if (!empty($config["extraColumns"])) {

            foreach (
                $config["extraColumns"]
                as $column => $value
            ) {

                /*
                 * Escape single quotes.
                 */

                $safeValue = str_replace(
                    "'",
                    "''",
                    (string)$value
                );

                $columns[] = $column;
                $values[] = "'{$safeValue}'";
            }
        }


        /* ======================================================
           AUDIT COLUMNS
        ====================================================== */

        $safeEmpCode = str_replace(
            "'",
            "''",
            $empCode
        );


        $columns[] = "CHG_ON";
        $columns[] = "CHG_BY";

        $values[] = "SYSDATE";
        $values[] = "'{$safeEmpCode}'";


        /* ======================================================
           BUILD INSERT SQL
        ====================================================== */

        $sql = "
            INSERT INTO {$config["table"]}
            (
                " . implode(", ", $columns) . "
            )
            VALUES
            (
                " . implode(", ", $values) . "
            )
        ";


        /* ======================================================
           EXECUTE INSERT
        ====================================================== */

        $insertResult = executeQry($sql);


        if ($insertResult === false) {

            throw new Exception(
                "Failed to insert {$accessType} access for ID {$id}."
            );
        }
    }


    /* ==========================================================
       COMMIT
    ========================================================== */

    endQry(
        "Profile {$accessType} access saved successfully."
    );


    /* ==========================================================
       CLOSE CONNECTION
    ========================================================== */

    if (isset($sql___func___con)) {

        @oci_close(
            $sql___func___con
        );
    }


    /* ==========================================================
       SUCCESS RESPONSE
    ========================================================== */

    apiResponse(
        true,
        "Profile {$accessType} access saved successfully.",
        [
            "profileId" => $profileId,
            "accessType" => $accessType,
            "savedCount" => count($selectedIds)
        ],
        200
    );


} catch (Throwable $e) {


    /* ==========================================================
       ROLLBACK
    ========================================================== */

    if (isset($sql___func___con)) {

        @oci_rollback(
            $sql___func___con
        );
    }


    /* ==========================================================
       LOG ORACLE ERROR
    ========================================================== */

    try {

        logOracleError([
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ]);

    } catch (Throwable $logException) {

        /*
         * Do not allow logging failure to hide
         * the original database error.
         */
    }


    /* ==========================================================
       ERROR RESPONSE
    ========================================================== */

    apiResponse(
        false,
        "Unable to save profile access.",
        [
            "error" => $e->getMessage()
        ],
        500
    );
}