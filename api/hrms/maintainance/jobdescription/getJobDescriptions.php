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

    $jobId = trim($_GET['id'] ?? '');

    $where = '';
    if ($jobId !== '') {
        $jobId = addslashes($jobId);
        $where = "WHERE a.ID = '" . $jobId . "'";
    }

    $sql = "SELECT a.ID,
               a.SH_DESC,
               a.DESCR,

               a.DEPT_CODE,
               a.DEPT_ID,
               get_dept_name(a.DEPT_ID) AS DEPT_NAME,

               a.DESIG_ID,
               get_design_name(a.DESIG_ID) AS DESIG_NAME,

               a.LVL_ID,

               a.MIN_EXP,
               a.MAX_EXP,

               a.MIN_AGE,
               a.MAX_AGE,
               a.AGE_RANGE,

               a.MIN_SAL,
               a.MAX_SAL,

               a.MIN_QUALI,
               a.MAX_QUALI,

               a.JD_DOC_PATH,

               a.EXP,
               a.REPT_JDID,
               a.STATUS,

               (
    SELECT LISTAGG(dv.DIVSN_DESC, ', ')
           WITHIN GROUP (ORDER BY dv.DIVSN_DESC)
    FROM HR_JD_DIVSN jdv
    INNER JOIN HR_DIVISIONS dv
        ON dv.DIVSN_ID = jdv.DIVSN_ID
    WHERE jdv.JD_ID = a.ID
) AS DIVISION_NAMES,

               rpt.SH_DESC AS REPORTS_TO_LABEL,
               COALESCE(get_dept_name(rpt.DEPT_ID), '') AS REPORTS_TO_DEPT,
               COALESCE(get_design_name(rpt.DESIG_ID), '') AS REPORTS_TO_DESIG

        FROM HR_JD a

        LEFT JOIN HR_JD rpt
            ON rpt.ID = a.REPT_JDID

        " . $where . "

        ORDER BY a.SH_DESC, a.ID";

    $rows = multiRec($sql);

    if ($jobId !== '') {
        if (empty($rows)) {
            apiResponse(false, "Job description not found.", null, 404);
        }

        $item = $rows[0];

$item['REPORTS_TO'] = trim(
    $item['REPORTS_TO_LABEL'] .
    ' (' .
    $item['REPORTS_TO_DEPT'] .
    ' - ' .
    $item['REPORTS_TO_DESIG'] .
    ')'
);


/*
=========================================================
KRA
=========================================================
*/
$kraSql = "
    SELECT
        ID,
        JD_ID,
        KRA_ID,
        RESP_PERC
    FROM HR_JD_KRA
    WHERE JD_ID = '" . addslashes($jobId) . "'
    ORDER BY ID
";

$item['KRA_LIST'] = multiRec($kraSql);


/*
=========================================================
EDUCATION
=========================================================
*/
$educationSql = "
    SELECT
        e.ID,
        e.JD_ID,
        e.QUA_ID,
        q.QUA_DESC,
        e.COMMENTS
    FROM HR_JD_EDU_DET e
    LEFT JOIN HR_QUALIFICATIONS q
        ON q.QUA_ID = e.QUA_ID
    WHERE e.JD_ID = '" . addslashes($jobId) . "'
    ORDER BY e.ID
";

$item['EDUCATION_LIST'] = multiRec($educationSql);


/*
=========================================================
SKILLS
=========================================================
*/
$skillsSql = "
    SELECT
        c.ID,
        c.JD_ID,
        c.CAPA_ID,
        cap.CAPA_CODE,
        cap.CAPA_DESC,
        c.CAPALVL_ID,
        lvl.CAPALVL_DESC
    FROM HR_JD_CAPABILITIES c
    LEFT JOIN HR_CAPABILITIES cap
        ON cap.CAPA_ID = c.CAPA_ID
    LEFT JOIN HR_CAPA_LEVEL lvl
        ON lvl.CAPALVL_ID = c.CAPALVL_ID
    WHERE c.JD_ID = '" . addslashes($jobId) . "'
    ORDER BY c.ID
";

$item['SKILLS_LIST'] = multiRec($skillsSql);


/*
=========================================================
ALLOWANCES
=========================================================
*/
$allowanceSql = "
    SELECT
        a.ID,
        a.JD_ID,
        a.ALLOW_ID,
        al.ALLOW_DESC,
        a.ALLOW_AMOUNT,
        a.ADD_INFO,
        a.FROMDT,
        a.TODT,
        a.EXP_TYPE
    FROM HR_JD_ALLOWANCES a
    LEFT JOIN HR_ALLOWANCES al
        ON al.ALLOW_ID = a.ALLOW_ID
    WHERE a.JD_ID = '" . addslashes($jobId) . "'
    ORDER BY a.ID
";

$item['ALLOWANCES_LIST'] = multiRec($allowanceSql);


/*
=========================================================
CTC HEADS
=========================================================
*/
// $ctcSql = "
//     SELECT
//         ID,
//         JD_ID,
//         AD_ID,
//         AD_CODE,
//         EFFEC_FROM,
//         EFFEC_TO,
//         KEY,
//         TEMPVAL,
//         VAL
//     FROM HR_JD_CTC_HEADS
//     WHERE JD_ID = '" . addslashes($jobId) . "'
//     ORDER BY ID
// ";

$ctcSql = "
    SELECT
        ID,
        JD_ID,
        AD_ID,
        AD_CODE,
        KEY,
        TEMPVAL,
        VAL,
        TO_CHAR(EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM,
        TO_CHAR(EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO
    FROM HR_JD_CTC_HEADS
    WHERE JD_ID = '" . addslashes($jobId) . "'
    ORDER BY ID
";

$item['CTC_HEADS_LIST'] = multiRec($ctcSql);


/*
=========================================================
QUESTION TEMPLATE
=========================================================
*/
$questionSql = "
    SELECT
        j.ID,
        j.JD_ID,
        j.QGRP_ID,
        g.QGRP_DESC,
        j.QGRP_TYPE,
        j.QSGRP_ID,
        sg.QSGRP_DESC,
        j.QUESTION_ID,
        q.QUESTION,
        q.RATING_TYPE,
        j.DISP_SEQ,
        j.EFF_FROM,
        j.EFF_TO
    FROM HR_JD_QUESTIONS j

    LEFT JOIN HR_QUESTION_GROUP g
        ON g.QGRP_ID = j.QGRP_ID

    LEFT JOIN HR_QUESTION_SGROUP sg
        ON sg.QSGRP_ID = j.QSGRP_ID

    LEFT JOIN HR_QUESTION_MASTER q
        ON q.ID = j.QUESTION_ID

    WHERE j.JD_ID = '" . addslashes($jobId) . "'

    ORDER BY
        j.DISP_SEQ,
        j.ID
";

$item['QUESTION_TEMPLATE_LIST'] = multiRec($questionSql);


/*
=========================================================
DEPARTMENT REFERENCE
=========================================================
*/
$deptRefSql = "
    SELECT
        r.ID,
        r.JD_ID,
        r.DEPT_ID,
        d.DEPT_CODE,
        d.DEPT_DESC
    FROM HR_JD_REF_DEPT r
    LEFT JOIN HR_DEPARTMENT d
        ON d.DEPT_ID = r.DEPT_ID
    WHERE r.JD_ID = '" . addslashes($jobId) . "'
    ORDER BY r.ID
";

$item['DEPT_REFERENCE_LIST'] = multiRec($deptRefSql);


/*
=========================================================
DIVISION MAPPING
=========================================================
*/
$divisionSql = "
    SELECT
        j.ID,
        j.JD_ID,
        j.DIVSN_ID,
        d.DIVSN_DESC
    FROM HR_JD_DIVSN j
    LEFT JOIN HR_DIVISIONS d
        ON d.DIVSN_ID = j.DIVSN_ID
    WHERE j.JD_ID = '" . addslashes($jobId) . "'
    ORDER BY j.ID
";

$item['DIVISION_MAPPING_LIST'] = multiRec($divisionSql);


/*
=========================================================
INDUCTION
=========================================================
*/
$inductionSql = "
    SELECT
        j.ID,
        j.JD_ID,
        j.INDUC_ID,
        i.INDUC_DESC,
        j.ORG_ID,
        o.LABEL AS ORG_LABEL,
        j.ORG_LOC_ID,
        l.LOC_LABEL,
        j.DISP_SEQ
    FROM HR_JD_INDUCTION j

    LEFT JOIN HR_INDUCTION_AREAS i
        ON i.INDUC_ID = j.INDUC_ID

    LEFT JOIN HR_ORGANOGRAM o
        ON o.ID = j.ORG_ID

    LEFT JOIN HR_ORGANOGRAM_LOC l
        ON l.ID = j.ORG_LOC_ID

    WHERE j.JD_ID = '" . addslashes($jobId) . "'

    ORDER BY
        j.DISP_SEQ,
        j.ID
";

$item['INDUCTION_LIST'] = multiRec($inductionSql);

/*
=========================================================
RESPONSIBILITIES
=========================================================
*/
$responsibilitySql = "
    SELECT
        ID,
        JD_ID,
        DESCR,
        CHG_ON,
        CHG_BY
    FROM HR_JD_DESCRIPTION
    WHERE JD_ID = '" . addslashes($jobId) . "'
    ORDER BY ID
";

$item['RESPONSIBILITIES_LIST'] = multiRec($responsibilitySql);

/*
=========================================================
RETURN COMPLETE JD
=========================================================
*/

apiResponse(
    true,
    "Job description fetched successfully.",
    ['jobDescription' => $item],
    200
);

exit;
    }

    $results = [];
    foreach ($rows as $row) {
        $row['REPORTS_TO'] = trim($row['REPORTS_TO_LABEL'] . ' (' . $row['REPORTS_TO_DEPT'] . ' - ' . $row['REPORTS_TO_DESIG'] . ')');
        $results[] = $row;
    }

    apiResponse(true, "Job descriptions fetched successfully.", ['jobDescriptions' => $results], 200);
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch job descriptions.", null, 500);
}
