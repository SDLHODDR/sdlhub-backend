<?php

ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

try {

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    if (!$conn) {
        apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    $type = strtolower(trim($_GET['type'] ?? ''));

    if ($type === '') {
        apiResponse(false, "Master type is required.", null, 400);
    }


    /*
    =========================================================
    KRA
    =========================================================
    */
    if ($type === 'kra') {

        $sql = "
            SELECT
                KRA_ID,
                KRA_DESC
            FROM HR_KRA_MASTER
            ORDER BY KRA_DESC
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "KRA list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    QUALIFICATION
    =========================================================
    */
    if ($type === 'qualification') {

        $sql = "
            SELECT
                QUA_ID,
                QUA_DESC
            FROM HR_QUALIFICATIONS
            ORDER BY QUA_DESC
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Qualification list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    SKILLS
    =========================================================
    */
    if ($type === 'skill') {

        $sql = "
            SELECT
                CAPA_ID,
                CAPA_CODE,
                CAPA_DESC
            FROM HR_CAPABILITIES
            ORDER BY CAPA_CODE, CAPA_DESC
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Skill list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    EXPERTISE LEVEL
    =========================================================
    */
    if ($type === 'expertise') {

        $sql = "
            SELECT
                CAPALVL_ID,
                CAPALVL_DESC
            FROM HR_CAPA_LEVEL
            ORDER BY CAPALVL_ID
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Expertise level list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    ALLOWANCE
    =========================================================
    */
    if ($type === 'allowance') {

        $sql = "
            SELECT
                ALLOW_ID,
                ALLOW_DESC
            FROM HR_ALLOWANCES
            ORDER BY ALLOW_DESC
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Allowance list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    FREQUENCY
    =========================================================

    There is currently no dedicated HR Job Description
    frequency master table identified in the DB information
    provided.

    HR_JD_ALLOWANCES contains EXP_TYPE, but that appears to
    be a JD allowance record field rather than a master table.
    Therefore we return existing distinct EXP_TYPE values.
    =========================================================
    */
    if ($type === 'frequency') {

        $sql = "
            SELECT DISTINCT
                EXP_TYPE
            FROM HR_JD_ALLOWANCES
            WHERE EXP_TYPE IS NOT NULL
            ORDER BY EXP_TYPE
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Frequency list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    DIVISION
    =========================================================
    */
    if ($type === 'division') {

        $sql = "
            SELECT
                DIVSN_ID,
                DIVSN_DESC
            FROM HR_DIVISIONS
            ORDER BY DIVSN_DESC
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Division list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    INDUCTION
    =========================================================
    */
    if ($type === 'induction') {

        $sql = "
            SELECT
                INDUC_ID,
                INDUC_DESC
            FROM HR_INDUCTION_AREAS
            ORDER BY INDUC_DESC
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Induction list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    ORGANOGRAM
    =========================================================
    */
    if ($type === 'organogram') {

        $sql = "
            SELECT
                ID AS ORG_ID,
                LABEL,
                DEPT_ID,
                DESI_ID,
                DIVSN_ID,
                JD_ID,
                PARENT_ORGID,
                STATUS
            FROM HR_ORGANOGRAM
            WHERE STATUS IS NULL
               OR STATUS = 'A'
            ORDER BY LABEL, ID
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Organogram list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    QUESTION TEMPLATE
    =========================================================

    Question Template consists of:
        HR_QUESTION_GROUP
        HR_QUESTION_SGROUP
        HR_QUESTION_MASTER
        HR_QUESTION_OPTS

    We return the complete question hierarchy in one result.
    =========================================================
    */
    if ($type === 'question_template') {

        $sql = "
            SELECT
                q.ID AS QUESTION_ID,
                q.QUESTION,
                q.RATING_TYPE,

                q.QGRP_ID,
                g.QGRP_DESC,
                g.QGRP_TYPE,

                q.QSGRP_ID,
                sg.QSGRP_DESC,

                o.ID AS OPTION_ID,
                o.OPTS_TEXT,
                o.OPTS_SEQ

            FROM HR_QUESTION_MASTER q

            LEFT JOIN HR_QUESTION_GROUP g
                ON g.QGRP_ID = q.QGRP_ID

            LEFT JOIN HR_QUESTION_SGROUP sg
                ON sg.QSGRP_ID = q.QSGRP_ID

            LEFT JOIN HR_QUESTION_OPTS o
                ON o.QUESTION_ID = q.ID

            WHERE q.STATUS IS NULL
               OR q.STATUS = 'A'

            ORDER BY
                q.QGRP_ID,
                q.QSGRP_ID,
                q.ID,
                o.OPTS_SEQ
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Question template list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    /*
    =========================================================
    FORMULA
    =========================================================

    No dedicated formula master table was identified from
    the DB structures provided.

    HR_EMP_CTC_HEADS contains CALC_TYPE, but CALC_TYPE is a
    code/value, not a confirmed formula master.

    So currently return distinct CALC_TYPE values.
    =========================================================
    */
    if ($type === 'formula') {

        $sql = "
            SELECT DISTINCT
                CALC_TYPE
            FROM HR_EMP_CTC_HEADS
            WHERE CALC_TYPE IS NOT NULL
            ORDER BY CALC_TYPE
        ";

        $rows = multiRec($sql);

        apiResponse(
            true,
            "Formula list fetched successfully.",
            ['data' => $rows],
            200
        );
    }


    apiResponse(
        false,
        "Invalid master type.",
        null,
        400
    );

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(
        false,
        "Unable to fetch Job Description master data.",
        null,
        500
    );
}