<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";
require_once __DIR__ . "/../../config/hr_func.php";

header("Content-Type: application/json; charset=UTF-8");

/* ===========================================
   HANDLE PREFLIGHT (CORS)
=========================================== */
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

try {
    /* ===========================================
       VALIDATE INPUTS & SESSION
    =========================================== */
    $empCode = trim($_REQUEST['REC_ID'] ?? $_REQUEST['emp_code'] ?? '');
    $taskId  = trim($_REQUEST['TASK_ID'] ?? $_REQUEST['task_id'] ?? '');
    $interviewerEmpCode = $_SESSION['EmpCode'] ?? $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Employee code (REC_ID) is required.", null, 400);
        exit;
    }

    /* ===========================================
       FETCH ORGANOGRAM & JD DETAILS
    =========================================== */
    $jdSql = "
        SELECT * 
        FROM HR_ORGANOGRAM 
        WHERE ID IN (
            SELECT ORG_ID 
            FROM HR_EMP_OFFICE_DET 
            WHERE EMP_CODE = :emp_code 
              AND SYSDATE BETWEEN EFFEC_FROM AND NVL(EFFEC_TO, TO_DATE('01-MAR-3000', 'DD-MON-YYYY'))
        )
    ";
    $jd_id = singRec($jdSql, [':emp_code' => $empCode]);
    $currentJdId = $jd_id['JD_ID'] ?? null;

    /* ===========================================
       EMPLOYEE OFFICE & GENERAL INFO
    =========================================== */
    $res_emp = function_exists('getEmpOffice') ? getEmpOffice($empCode) : [];
    $empName = function_exists('getEmpInfoByCode') ? getEmpInfoByCode($empCode) : '';

    /* ===========================================
       CURRENT CTC & LATEST TENURE
    =========================================== */
    $ctcSql = "SELECT GET_TOTAL_CTC(:emp_code, SYSDATE) AS VAL FROM DUAL";
    $ctcRow = singRec($ctcSql, [':emp_code' => $empCode]);
    $currentCtcVal = isset($ctcRow['VAL']) ? (float)$ctcRow['VAL'] : 0;

    $reqSql = "
        SELECT * FROM (
            SELECT 
                C.EMP_TYPE,
                C.ETYPE_ID,
                A.ETYPE_PERIOD,
                DDMONYYYY(A.EFF_TO) AS TDATE,
                A.EFF_TO
            FROM HR_EMP_TENURE A 
            INNER JOIN HR_EMP_TYPE C ON C.ETYPE_ID = A.ETYPE_ID 
            WHERE A.EMP_CODE = :emp_code 
            ORDER BY A.EFF_FROM DESC
        ) WHERE ROWNUM = 1
    ";
    $req = singRec($reqSql, [':emp_code' => $empCode]);

    /* ===========================================
       ACTIVE/PENDING TENURE CHANGE RECORD
    =========================================== */
    $tenureChgSql = "
        SELECT 
            ID,
            EMP_CODE,
            ETYPE_ID,
            ETYPE_PERIOD,
            PROP_CTC,
            TO_CHAR(EFF_FROM, 'YYYY-MM-DD') AS EFF_FROM_FORMATTED,
            REMARKS,
            STATUS
        FROM HR_EMP_TENURE_CHANGE 
        WHERE EMP_CODE = :emp_code 
          AND STATUS IN ('N', 'T')
    ";
    $tenure_chg = singRec($tenureChgSql, [':emp_code' => $empCode]);

    /* ===========================================
       CALCULATE DATES (MATCHING OLD LOGIC)
    =========================================== */
    $tenureDueDate = $req['TDATE'] ?? '';
    $defaultEffectiveDate = '';
    $firstDayOfNextMonth  = '';

    if (!empty($tenureDueDate)) {
        $timestamp = strtotime($tenureDueDate);
        $defaultEffectiveDate = date('Y-m-d', strtotime('+1 day', $timestamp));
        // Legacy: 'first day of current month' based on TDATE
        $firstDayOfNextMonth  = date('Y-m-01', $timestamp);
    } else {
        $defaultEffectiveDate = date('Y-m-d');
        $firstDayOfNextMonth  = date('Y-m-01');
    }

    /* ===========================================
       FETCH EMPLOYEE TYPES (DROPDOWN OPTIONS)
    =========================================== */
    $empTypeOptions = multiRec("SELECT ETYPE_ID AS VALUE, EMP_TYPE AS LABEL FROM HR_EMP_TYPE ORDER BY ETYPE_ID ASC");

    /* ===========================================
       FETCH QUESTIONS, OPTIONS & SAVED ANSWERS
    =========================================== */
    $questionsData = [];
    $allAreFilled = true;
    $tenureChgId = trim((string)($tenure_chg['ID'] ?? ''));

    if (!empty($currentJdId)) {
        $questionsSql = "
            SELECT 
                A.QUESTION_ID AS JD_QUES_ID,
                A.DISP_SEQ,
                A.QGRP_TYPE,
                B.ID AS QUESTION_ID,
                B.QUESTION,
                B.RATING_TYPE,
                B.QGRP_ID,
                B.QSGRP_ID,
                SG.QSGRP_DESC,
                SSG.QSSGRP_DESC
            FROM HR_JD_QUESTIONS A
            RIGHT JOIN HR_QUESTION_MASTER B ON A.QUESTION_ID = B.ID
            LEFT JOIN HR_QUESTION_SGROUP SG ON B.QGRP_ID = SG.QSGRP_ID
            LEFT JOIN HR_QUESTION_SSGROUP SSG ON B.QSGRP_ID = SSG.QSSGRP_ID
            WHERE A.JD_ID = :jd_id 
              AND A.QGRP_TYPE = 'T'
            ORDER BY B.QGRP_ID ASC, A.DISP_SEQ ASC
        ";

        $questionsList = multiRec($questionsSql, [':jd_id' => $currentJdId]);

        if (empty($questionsList)) {
            // If no questions exist for this JD, check if tenure change record has an ETYPE_ID set (matching legacy check)
            $allAreFilled = !empty($tenure_chg['ETYPE_ID']);
        } else {
            foreach ($questionsList as $q) {
                $quesId = $q['QUESTION_ID'];

                // Fetch Saved Answer
                $ansSql = "
                    SELECT EMP_ANS_TEXT 
                    FROM HR_TENURE_INTRV 
                    WHERE QUES_ID = :ques_id 
                      AND EMP_CODE = :emp_code 
                      AND EMP_CODE_INTRV = :interviewer 
                      AND TENURE_CHG_ID = :tenure_chg_id
                ";
                $ansRow = singRec($ansSql, [
                    ':ques_id'        => $quesId,
                    ':emp_code'       => $empCode,
                    ':interviewer'    => $interviewerEmpCode,
                    ':tenure_chg_id'  => $tenureChgId
                ]);

                $savedAnswer = trim((string)($ansRow['EMP_ANS_TEXT'] ?? ''));

                if ($savedAnswer === '') {
                    $allAreFilled = false;
                }

                // Fetch Options
                $optsSql = "SELECT OPTS_TEXT FROM HR_QUESTION_OPTS WHERE QUESTION_ID = :ques_id ORDER BY ID ASC";
                $optsList = multiRec($optsSql, [':ques_id' => $quesId]);
                $options = array_column($optsList, 'OPTS_TEXT');

                $type = 'text';
                if ($q['RATING_TYPE'] === 'R') {
                    $type = 'radio';
                } elseif ($q['RATING_TYPE'] === 'C') {
                    $type = 'checkbox';
                }

                $questionsData[] = [
                    'id'            => (int)$quesId,
                    'disp_seq'      => (int)$q['DISP_SEQ'],
                    'group'         => !empty($q['QSGRP_DESC']) ? strtoupper($q['QSGRP_DESC']) : 'GENERAL',
                    'subgroup'      => !empty($q['QSSGRP_DESC']) ? strtoupper($q['QSSGRP_DESC']) : 'PERSONAL',
                    'question'      => $q['QUESTION'],
                    'rating_type'   => $q['RATING_TYPE'],
                    'input_type'    => $type,
                    'options'       => $options,
                    'saved_answer'  => $savedAnswer,
                    'selected_opts' => ($type === 'checkbox' && $savedAnswer !== '') ? array_map('trim', explode(',', $savedAnswer)) : []
                ];
            }
        }
    } else {
        $allAreFilled = !empty($tenure_chg['ETYPE_ID']);
    }

    $canSubmit = ($allAreFilled && !empty($tenureChgId));

    /* ===========================================
       CONSTRUCT RESPONSE PAYLOAD
    =========================================== */
    $response = [
        'employee' => [
            'emp_code'         => $empCode,
            'name'             => $empName,
            'doj'              => $res_emp['DOJ'] ?? '-',
            'designation'      => $res_emp['DESIG'] ?? '-',
            'department'       => $res_emp['DEPT'] ?? '-',
            'current_emp_type' => $req['EMP_TYPE'] ?? '-',
            'tenure_due_date'  => $tenureDueDate,
            'current_ctc'      => $currentCtcVal,
        ],
        'tenure_assessment' => [
            'tenure_change_id'   => $tenureChgId ?: null,
            'task_id'            => $taskId,
            'jd_id'              => $currentJdId,
            'etype_id'           => $tenure_chg['ETYPE_ID'] ?? '',
            'etype_period'       => $tenure_chg['ETYPE_PERIOD'] ?? '',
            'proposed_ctc'       => !empty($tenure_chg['PROP_CTC']) ? (float)$tenure_chg['PROP_CTC'] : '',
            'effective_from'     => !empty($tenure_chg['EFF_FROM_FORMATTED']) ? $tenure_chg['EFF_FROM_FORMATTED'] : $defaultEffectiveDate,
            'min_effective_date' => $firstDayOfNextMonth,
            'remarks'            => $tenure_chg['REMARKS'] ?? '',
            'can_submit'         => $canSubmit,
        ],
        'employee_type_options' => $empTypeOptions,
        'questions'             => $questionsData,
    ];

    apiResponse(true, "Tenure details loaded successfully.", $response, 200);

} catch (Throwable $e) {
    if (function_exists('logOracleError')) {
        logOracleError([
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ], "get_tenure_change_details.php");
    }

    apiResponse(false, "Server error while fetching tenure details: " . $e->getMessage(), null, 500);
}