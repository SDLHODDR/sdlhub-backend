<?php

try {
    if (empty($data['saveConfirmation'])) {
        apiResponse(false, 'Invalid tenure assessment request.', null, 400);
    }

    $employeeCode = trim((string)($data['EMP_CODE'] ?? $data['emp_code'] ?? ''));
    if ($employeeCode === '') {
        throw new InvalidArgumentException('Employee code is required.');
    }

    $tenureId = trim((string)($data['tenure_change_id'] ?? $data['TENURE_CHANGE_ID'] ?? ''));
    $tenure = [];
    if ($tenureId !== '') {
        $tenure = singRec(
            "SELECT ID, EMP_CODE, ETYPE_ID, ETYPE_PERIOD, EFF_FROM, NXT_APPR_DUE, REMARKS, CURR_CTC, PROP_CTC FROM HR_EMP_TENURE_CHANGE WHERE ID = :tenure_id AND EMP_CODE = :emp_code AND STATUS IN ('N','T')",
            [':tenure_id' => $tenureId, ':emp_code' => $employeeCode]
        );
    } else {
        $tenure = singRec(
            "SELECT ID, EMP_CODE, ETYPE_ID, ETYPE_PERIOD, EFF_FROM, NXT_APPR_DUE, REMARKS, CURR_CTC, PROP_CTC FROM HR_EMP_TENURE_CHANGE WHERE EMP_CODE = :emp_code AND STATUS IN ('N','T') ORDER BY ID DESC",
            [':emp_code' => $employeeCode]
        );
    }
    if (empty($tenure['ID'])) {
        throw new RuntimeException('No editable tenure change was found for this employee.');
    }

    $effectiveFrom = trim((string)($data['EFF_FROM'] ?? $data['effective_from'] ?? ''));
    $effectiveDate = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom)
        ?: DateTimeImmutable::createFromFormat('!d-M-Y', $effectiveFrom);
    if (!$effectiveDate) {
        throw new InvalidArgumentException('A valid effective date is required.');
    }
    $effectiveOracle = "TO_DATE('" . $effectiveDate->format('Y-m-d') . "','YYYY-MM-DD')";

    $nextDue = trim((string)($data['NXT_APPR_DUE'] ?? $data['next_appraisal_year'] ?? ''));
    if ($nextDue !== '' && preg_match('/^\d{4}$/', $nextDue)) {
        $nextDue = '01-APR-' . substr($nextDue, -2);
    }
    $nextDueOracle = $nextDue === '' ? ($tenure['NXT_APPR_DUE'] ?? '') : "TO_DATE('" . str_replace("'", "''", $nextDue) . "','DD-MON-RR')";

    $answers = $data['question'] ?? $data['answers'] ?? [];
    if (!is_array($answers)) {
        throw new InvalidArgumentException('Assessment answers must be a list.');
    }
    $normalizedAnswers = [];
    foreach ($answers as $key => $item) {
        if (is_array($item) && array_key_exists('ques_id', $item)) {
            $normalizedAnswers[(string)$item['ques_id']] = $item['answer'] ?? '';
        } else {
            $normalizedAnswers[(string)$key] = $item;
        }
    }

    $sqlQuote = static function ($value): string {
        return str_replace("'", "''", trim((string)$value));
    };
    $employeeType = $data['ETYPE_ID'] ?? $data['etype_id'] ?? $tenure['ETYPE_ID'];
    $period = $data['ETYPE_PERIOD'] ?? $data['etype_period'] ?? $tenure['ETYPE_PERIOD'];
    $remarks = $data['REMARKS'] ?? $data['remarks'] ?? $tenure['REMARKS'];
    $currentCtc = $data['CURR_CTC'] ?? $data['curr_ctc'] ?? $tenure['CURR_CTC'];
    $proposedCtc = $data['PROP_CTC'] ?? $data['proposed_ctc'] ?? $tenure['PROP_CTC'];
    if (trim((string)$proposedCtc) === '') {
        $proposedCtc = $currentCtc;
    }

    startQry();
    $updated = execQry([
        'type' => 'update',
        'table' => 'HR_EMP_TENURE_CHANGE',
        'data' => [
            'ETYPE_ID' => $sqlQuote($employeeType),
            'ETYPE_PERIOD' => $sqlQuote($period),
            'EFF_FROM' => $effectiveOracle,
            'CTC_EFF_FROM' => $effectiveOracle,
            'NXT_APPR_DUE' => $nextDueOracle,
            'REMARKS' => $sqlQuote($remarks),
            'CURR_CTC' => $sqlQuote(str_replace(',', '', (string)$currentCtc)),
            'PROP_CTC' => $sqlQuote(str_replace(',', '', (string)$proposedCtc)),
            'STATUS' => 'T',
            'CHG_ON' => 'SYSDATE',
            'CHG_BY' => $sqlQuote($empCode)
        ],
        'where' => ['ID' => $sqlQuote($tenure['ID'])],
        'return' => '',
        'print' => 0
    ]);
    if ($updated === false) {
        throw new RuntimeException('Could not save tenure assessment details.');
    }

    if (executeQry("DELETE FROM HR_TENURE_INTRV WHERE TENURE_CHG_ID='" . $sqlQuote($tenure['ID']) . "'") === false) {
        throw new RuntimeException('Could not replace the existing assessment answers.');
    }
    foreach ($normalizedAnswers as $questionId => $answer) {
        $answerText = is_array($answer)
            ? implode(',', array_map($sqlQuote, $answer))
            : $sqlQuote($answer);
        $inserted = execQry([
            'type' => 'insert',
            'table' => 'HR_TENURE_INTRV',
            'data' => [
                'ID' => '',
                'TENURE_CHG_ID' => $sqlQuote($tenure['ID']),
                'EMP_CODE' => $sqlQuote($employeeCode),
                'ASON_DATE' => 'SYSDATE',
                'EMP_CODE_INTRV' => $sqlQuote($empCode),
                'JD_ID' => $sqlQuote($data['jd_id'] ?? $data['JD_ID'] ?? ''),
                'QUES_ID' => $sqlQuote($questionId),
                'EMP_OPTS_ID' => '',
                'EMP_ANS_TEXT' => $sqlQuote($answerText),
                'CHG_BY' => $sqlQuote($empCode),
                'CHG_ON' => 'SYSDATE'
            ],
            'return' => '',
            'print' => 0
        ]);
        if ($inserted === false) {
            throw new RuntimeException('Could not save an assessment answer.');
        }
    }

    endQry('Saved Successfully');
    apiResponse(true, 'Tenure assessment saved successfully.', ['tenure_change_id' => $tenure['ID']]);
} catch (Throwable $e) {
    if (!empty($sql___func___con)) {
        oci_rollback($sql___func___con);
    }
    logOracleError([
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 'saveConfirmation.php');
    apiResponse(false, $e->getMessage(), null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
