<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(
            false,
            "Unauthorized access",
            null,
            401
        );
    }

    /* ===========================================
       READ INPUT
    =========================================== */

    $data = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($data)) {
        apiResponse(
            false,
            "Invalid request data.",
            null,
            400
        );
    }

    /* ===========================================
       INPUT VALUES
    =========================================== */

    $id = (int)($data['id'] ?? 0);

    $name = trim(
        $data['name'] ?? ''
    );

    $relation = trim(
        $data['relation'] ?? ''
    );

    $dob = trim(
        $data['dob'] ?? ''
    );

    $aadhaar = trim(
        $data['aadhaar'] ?? ''
    );

    $dependent = trim(
        $data['dependent'] ?? ''
    );

    $occupation = trim(
        $data['occupation'] ?? ''
    );

    /* ===========================================
       REQUIRED VALIDATION
    =========================================== */

    if (
        $name === '' ||
        $relation === ''
    ) {
        apiResponse(
            false,
            "Name and Relation are required.",
            null,
            400
        );
    }

    /* ===========================================
       DOB VALIDATION
       
       Expected:
       YYYY-MM-DD
       
       Example:
       2026-09-08
    =========================================== */

    $dobDate = null;

    if ($dob !== '') {

        $dobDate = DateTime::createFromFormat(
            'Y-m-d',
            $dob
        );

        $dateErrors =
            DateTime::getLastErrors();

        if (
            !$dobDate ||
            (
                $dateErrors !== false &&
                (
                    $dateErrors['warning_count'] > 0 ||
                    $dateErrors['error_count'] > 0
                )
            ) ||
            $dobDate->format('Y-m-d') !== $dob
        ) {
            apiResponse(
                false,
                "Invalid date of birth. Expected format: YYYY-MM-DD.",
                null,
                400
            );
        }

        /* -----------------------------------------
           DOB CANNOT BE FUTURE DATE
        ----------------------------------------- */

        $today = new DateTime();
        $today->setTime(0, 0, 0);

        $dobCheck = clone $dobDate;
        $dobCheck->setTime(0, 0, 0);

        if ($dobCheck > $today) {
            apiResponse(
                false,
                "Date of birth cannot be a future date.",
                null,
                400
            );
        }
    }

    /* ===========================================
       CALCULATE AGE
    =========================================== */

    $age = '';

    if ($dobDate !== null) {
        $age = calculateAgeFromDob(
            $dobDate
        );
    }
    /* ===========================================
       AADHAAR VALIDATION
    =========================================== */

    if ($aadhaar !== '') {

        if (!preg_match(
            '/^\d{12}$/',
            $aadhaar
        )) {
            apiResponse(
                false,
                "Aadhaar must contain exactly 12 digits.",
                null,
                400
            );
        }

        /*
         * Aadhaar should not start with 0 or 1
         */
        if (
            preg_match(
                '/^[01]/',
                $aadhaar
            )
        ) {
            apiResponse(
                false,
                "Invalid Aadhaar number.",
                null,
                400
            );
        }
    }

    /* ===========================================
       ESCAPE VALUES
    =========================================== */

    $nameEsc = str_replace(
        "'",
        "''",
        ucfirst($name)
    );

    $relationEsc = str_replace(
        "'",
        "''",
        $relation
    );

    $aadhaarEsc = str_replace(
        "'",
        "''",
        $aadhaar
    );

    $dependentEsc = str_replace(
        "'",
        "''",
        $dependent
    );

    $occupationEsc = str_replace(
        "'",
        "''",
        $occupation
    );

    $empCodeEsc = str_replace(
        "'",
        "''",
        $empCode
    );

    /* ===========================================
       START QUERY TRANSACTION
    =========================================== */

    startQry();

    /* ===========================================
       UPDATE EXISTING FAMILY MEMBER
    =========================================== */

    if ($id > 0) {

        /*
         * Build DOB SQL safely.
         *
         * If DOB is empty, store NULL.
         */

        $dobSql = "NULL";

        if ($dob !== '') {
            $dobSql =
                "TO_DATE(
                    '{$dob}',
                    'YYYY-MM-DD'
                )";
        }

        executeQry("
            UPDATE EPT_HR_EMP_FAMILY_INFO
            SET
                FM_NAME = '{$nameEsc}',
                FM_RELATION = '{$relationEsc}',
                DOB = {$dobSql},
                AADHAAR = '{$aadhaarEsc}',
                FM_DEP = '{$dependentEsc}',
                FM_OCCUPATION = '{$occupationEsc}',
                AGE = '{$age}',
                CHG_ON = SYSDATE,
                CHG_BY = '{$empCodeEsc}'
            WHERE ID = {$id}
        ");

        if ($qry_____result != 0) {

            forceRollback(
                "Failed to update family member."
            );
        }

        endQry();

        apiResponse(
            true,
            "Family member updated successfully.",
            [
                "id" => $id
            ]
        );
    }

    /* ===========================================
       INSERT NEW FAMILY MEMBER
    =========================================== */

    $dobSql = "NULL";

    if ($dob !== '') {
        $dobSql =
            "TO_DATE(
                '{$dob}',
                'YYYY-MM-DD'
            )";
    }

    executeQry("
        INSERT INTO EPT_HR_EMP_FAMILY_INFO
        (
            EMP_CODE,
            FM_NAME,
            FM_RELATION,
            FM_DEP,
            DOB,
            AADHAAR,
            FM_OCCUPATION,
            AGE,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '{$empCodeEsc}',
            '{$nameEsc}',
            '{$relationEsc}',
            '{$dependentEsc}',
            {$dobSql},
            '{$aadhaarEsc}',
            '{$occupationEsc}',
            '{$age}',
            SYSDATE,
            '{$empCodeEsc}'
        )
    ");

    if ($qry_____result != 0) {

        forceRollback(
            "Failed to add family member."
        );
    }

    endQry();

    apiResponse(
        true,
        "Family member added successfully."
    );

} catch (Throwable $e) {

    forceRollback(
        "Save family member failed."
    );

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveFamilyMember.php"
    );

    apiResponse(
        false,
        "Unable to save family member.",
        null,
        500
    );
}