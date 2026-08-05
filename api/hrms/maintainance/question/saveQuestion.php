<?php

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}

$questionText = trim(
    is_array($data['QUES_DESCR'] ?? null)
        ? ($data['QUES_DESCR'][0] ?? '')
        : ($data['QUES_DESCR'] ?? '')
);
$ratingType = trim($data['rateyn'] ?? ($data['RATING_TYPE'] ?? ''));

if (empty($ratingType) && !empty($data['answer_type'])) {
    $answerType = strtoupper(trim($data['answer_type']));
    $map = [
        'TEXT' => 'T',
        'TEXT BOX' => 'T',
        'SELECT' => 'S',
        'SELECT BOX' => 'S',
        'RADIO' => 'R',
        'RADIO BUTTON' => 'R',
        'CHECK' => 'C',
        'CHECK BOX' => 'C',
        'CHECKBOX' => 'C',
        'YES/NO' => 'Y',
        'Y/N' => 'Y',
    ];
    $ratingType = $map[$answerType] ?? $answerType;
}

$qgrpId = trim($data['QGRP_ID'] ?? '');
$qsgpId = trim($data['QSGRP_ID'] ?? '');
$questionId = trim($data['ID'] ?? ($data['QUESTION_ID'] ?? ''));
$options = [];

if (!empty($data['OPTIONS']) && is_array($data['OPTIONS'])) {
    $options = $data['OPTIONS'];
} elseif (!empty($data['OPTIONS'])) {
    $options = array_map('trim', explode(',', $data['OPTIONS']));
}

if (empty($options) && !empty($data['noopts'])) {
    for ($i = 1; $i <= (int)$data['noopts']; $i++) {
        if (!empty($data['opts_' . $i])) {
            $options[] = trim($data['opts_' . $i]);
        }
    }
}

$options = array_values(array_filter(array_map('trim', $options), fn($opt) => $opt !== ''));

try {
    startQry();

    $escapedQuestion = str_replace("'", "''", $questionText);
    $escapedRatingType = str_replace("'", "''", $ratingType);
    $escapedQgrpId = str_replace("'", "''", $qgrpId);
    $escapedQsgpId = str_replace("'", "''", $qsgpId);
    $escapedEmpCode = str_replace("'", "''", $empCode);

    if (!empty($questionId)) {
        $escapedQuestionId = str_replace("'", "''", $questionId);

        $masterUpdate = executeQry(
            "update HR_QUESTION_MASTER set
                QUESTION='" . $escapedQuestion . "',
                RATING_TYPE='" . $escapedRatingType . "',
                QGRP_ID='" . $escapedQgrpId . "',
                QSGRP_ID='" . $escapedQsgpId . "',
                CHG_BY='" . $escapedEmpCode . "',
                CHG_ON=sysdate
             where ID='" . $escapedQuestionId . "'"
        );

        if ($masterUpdate === false) {
            throw new RuntimeException('Unable to update question master.');
        }

        executeQry("delete from HR_QUESTION_OPTS where QUESTION_ID='" . $escapedQuestionId . "'");

        foreach ($options as $seq => $optText) {
            $optTextClean = str_replace("'", "''", $optText);
            executeQry(
                "insert into HR_QUESTION_OPTS (ID, QUESTION_ID, OPTS_TEXT, OPTS_SEQ, CHG_ON, CHG_BY)
                 values ('', '" . $escapedQuestionId . "', '" . $optTextClean . "', " . (int)$seq . ", sysdate, '" . $escapedEmpCode . "')"
            );
        }

        endQry('Updated');

        apiResponse(
            true,
            "Question updated successfully.",
            ["ID" => (int)$questionId]
        );
    } else {
        $exists = singRec(
            "select ID from HR_QUESTION_MASTER
             where QUESTION='" . $escapedQuestion . "'
               and QSGRP_ID='" . $escapedQsgpId . "'
               and STATUS!='D'"
        );

        if (!empty($exists)) {
            endQry('Record Already Exists!');
            apiResponse(false, "Record already exists.", null, 200);
            exit;
        }

        $newQuestionId = executeQry(
            "insert into HR_QUESTION_MASTER (ID, QUESTION, RATING_TYPE, QGRP_ID, QSGRP_ID, CHG_ON, CHG_BY, STATUS)
             values ('', '" . $escapedQuestion . "', '" . $escapedRatingType . "', '" . $escapedQgrpId . "', '" . $escapedQsgpId . "', sysdate, '" . $escapedEmpCode . "', 'A')
             returning ID into :newQuestionId",
            'newQuestionId'
        );

        if ($newQuestionId === false) {
            throw new RuntimeException('Unable to insert question master.');
        }

        foreach ($options as $seq => $optText) {
            $optTextClean = str_replace("'", "''", $optText);
            executeQry(
                "insert into HR_QUESTION_OPTS (ID, QUESTION_ID, OPTS_TEXT, OPTS_SEQ, CHG_ON, CHG_BY)
                 values ('', '" . $newQuestionId . "', '" . $optTextClean . "', " . (int)$seq . ", sysdate, '" . $escapedEmpCode . "')"
            );
        }

        endQry('Saved Successfully');

        apiResponse(
            true,
            "Question saved successfully.",
            ["ID" => (int)$newQuestionId]
        );
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "saveQuestion.php"
    );

    apiResponse(false, "Unable to save question.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
