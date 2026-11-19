<?php
/*
 * POST { user_task_id, action: 'save'|'submit',
 *        answers: { questionId: text | [options] }, remarks: { questionId: text },   (fixed format)
 *        kras: { kraId: { perc, activities: { activityId: remarks } } },               (KRA base)
 *        prop_ctc, reco_promo (Y|N), nxt_appr_due (YYYY-MM-DD), appr_remarks }
 * Employee Appraisal task (46), legacy EmpAppFIX / EmpAppKRA (save) and CloseEmpAppFIX /
 * CloseEmpAppKRA (submit). Save keeps whatever is filled in. Submit needs every question (an
 * Ad-hoc appraisal: at least one) or every KRA with its activity remarks, plus the proposed CTC,
 * promotion recommendation, next appraisal due and final review; it marks this appraiser done
 * and gives task 46 to the next appraiser in the chain, if there is one.
 */
require_once __DIR__ . '/employeeAppraisalCommon.php';

$input = appraisalInput();

employeeAppraisalRunAction(function () use ($input, $empCode) {
    $task = employeeAppraisalLoadTask($input['user_task_id'] ?? 0, $empCode);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new AppraisalRequestException('Invalid action.', 422);
    }
    $manager = $task['MANAGER'];
    $appraisal = $task['APPRAISAL'];
    if ($manager['STATUS'] !== 'I') {
        throw new AppraisalRequestException('This appraisal has already been submitted.', 409);
    }
    $mgrId = (string)$manager['ID'];
    $apprId = (string)$appraisal['ID'];
    $scope = [':mgr' => $mgrId, ':appr' => $apprId];
    $length = static function ($value, $max, $label) {
        if (strlen($value) > $max) {
            throw new AppraisalRequestException("{$label} is too long (maximum {$max} characters).", 422);
        }
    };

    // Decision fields.
    $propCtc = preg_replace('/[,\s]/', '', (string)($input['prop_ctc'] ?? ''));
    if ($propCtc !== '' && !preg_match('/^\d{1,12}$/', $propCtc)) {
        throw new AppraisalRequestException('Please enter the proposed CTC in whole rupees.', 422);
    }
    $recoPromo = strtoupper(trim((string)($input['reco_promo'] ?? '')));
    if ($recoPromo !== '' && !in_array($recoPromo, ['Y', 'N'], true)) {
        throw new AppraisalRequestException('Please choose Yes or No for the promotion recommendation.', 422);
    }
    $nextDue = trim((string)($input['nxt_appr_due'] ?? ''));
    if ($nextDue !== '' && !in_array($nextDue, array_column(employeeAppraisalDueOptions(), 'value'), true)) {
        throw new AppraisalRequestException('Please select a valid next appraisal due date.', 422);
    }
    $review = trim((string)($input['appr_remarks'] ?? ''));
    $length($review, EMPLOYEE_APPRAISAL_REVIEW_MAX, 'Final review');

    $answered = 0;
    $total = 0;
    if ($appraisal['APPR_BASE'] === 'K') {
        $kras = [];
        foreach (multiRec("SELECT B.KRA_ID, B.RESP_PERC FROM HR_EMP_KRA B WHERE B.EMP_CODE = :code
            AND SYSDATE BETWEEN B.EFFEC_FROM AND NVL(B.EFFEC_TO, DATE '3000-03-01')", [':code' => (string)$manager['EMP_CODE']]) ?: [] as $kra) {
            $kras[(string)$kra['KRA_ID']] = [
                'weight' => (string)$kra['RESP_PERC'],
                'acts' => array_column(multiRec("SELECT ID FROM HR_KRA_ACTIVITY WHERE KRA_ID = :kra AND STATUS = 'A'",
                    [':kra' => $kra['KRA_ID']]) ?: [], 'ID'),
            ];
        }
        $posted = (array)($input['kras'] ?? []);
        appraisalExecute("DELETE FROM HR_APPR_EMP_APPR WHERE EMP_MGR_ID = :mgr AND APPR_ID = :appr", $scope, 'Could not save the appraisal.');
        foreach ($kras as $kraId => $kra) {
            $total++;
            $entry = (array)($posted[$kraId] ?? []);
            $perc = trim((string)($entry['perc'] ?? ''));
            if ($perc !== '' && (!preg_match('/^\d{1,3}$/', $perc) || (int)$perc > 100)) {
                throw new AppraisalRequestException('Please enter the appraised percentage as a whole number up to 100.', 422);
            }
            $complete = $perc !== '';
            foreach ($kra['acts'] as $actId) {
                $remark = trim((string)(((array)($entry['activities'] ?? []))[(string)$actId] ?? ''));
                $length($remark, EMPLOYEE_APPRAISAL_KRA_REMARK_MAX, 'An activity remark');
                if ($remark === '') {
                    $complete = false;
                }
                appraisalInsert('HR_APPR_EMP_APPR', [
                    'APPR_ID' => $apprId, 'EMP_MGR_ID' => $mgrId, 'KRA_ID' => $kraId, 'KRA_ACT_ID' => (string)$actId,
                    'RESP_PERC' => $kra['weight'], 'APPR_PERC' => $perc, 'KRA_REMARKS' => $remark, 'CHG_BY' => $empCode,
                ], ['CHG_ON']);
            }
            if ($complete) {
                $answered++;
            }
        }
    } else {
        $questions = [];
        foreach (multiRec("SELECT Q.QUESTION_ID, M.RATING_TYPE FROM HR_APPR_QUESTION Q
            INNER JOIN HR_QUESTION_MASTER M ON M.ID = Q.QUESTION_ID WHERE Q.APPR_ID = :appr", [':appr' => $apprId]) ?: [] as $row) {
            $questions[(string)$row['QUESTION_ID']] = (string)$row['RATING_TYPE'];
        }
        $options = [];
        foreach (multiRec("SELECT QUESTION_ID, OPTS_TEXT FROM HR_QUESTION_OPTS
            WHERE QUESTION_ID IN (SELECT QUESTION_ID FROM HR_APPR_QUESTION WHERE APPR_ID = :appr)", [':appr' => $apprId]) ?: [] as $row) {
            $options[(string)$row['QUESTION_ID']][] = (string)$row['OPTS_TEXT'];
        }
        $answers = (array)($input['answers'] ?? []);
        $remarks = (array)($input['remarks'] ?? []);
        foreach ($questions as $qid => $type) {
            $total++;
            $value = $answers[$qid] ?? '';
            if ($type === 'C') {
                $chosen = array_values(array_unique(array_filter(array_map('strval', (array)$value), static fn($v) => trim($v) !== '')));
                foreach ($chosen as $option) {
                    if (!in_array($option, $options[$qid] ?? [], true)) {
                        throw new AppraisalRequestException('Please choose a listed option for every question.', 422);
                    }
                }
                $text = implode(',', $chosen);
            } else {
                $text = trim((string)(is_array($value) ? reset($value) : $value));
                if ($type === 'R' && $text !== '' && !in_array($text, $options[$qid] ?? [], true)) {
                    throw new AppraisalRequestException('Please choose a listed option for every question.', 422);
                }
            }
            $remark = trim((string)($remarks[$qid] ?? ''));
            $length($text, EMPLOYEE_APPRAISAL_ANSWER_MAX, 'An answer');
            $length($remark, EMPLOYEE_APPRAISAL_REMARK_MAX, 'A remark');
            if ($text !== '') {
                $answered++;
            }
            // Publish creates one row per question; add it if an older appraisal lacks it.
            $updated = appraisalExecute("UPDATE HR_APPR_EMP_APPR SET OPTS_ID = NULL, ANS_TEXT = :ans, QUES_REMARKS = :remark,
                    CHG_BY = :chg_by, CHG_ON = SYSDATE
                WHERE EMP_MGR_ID = :mgr AND APPR_ID = :appr AND QUES_ID = :qid",
                $scope + [':ans' => $text, ':remark' => $remark, ':chg_by' => $empCode, ':qid' => $qid], 'Could not save the answers.');
            if ($updated === 0) {
                appraisalInsert('HR_APPR_EMP_APPR', [
                    'APPR_ID' => $apprId, 'EMP_MGR_ID' => $mgrId, 'QUES_ID' => $qid, 'ANS_TEXT' => $text,
                    'QUES_REMARKS' => $remark, 'CHG_BY' => $empCode,
                ], ['CHG_ON']);
            }
        }
    }

    appraisalExecute("UPDATE HR_APPR_EMP_MGR SET PROP_CTC = :prop_ctc, RECO_PROMO = :reco, NXT_APPR_DUE = TO_DATE(:due, 'YYYY-MM-DD'),
            APPR_REMARKS = :remarks, CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE ID = :mgr AND APPR_ID = :appr", $scope + [
        ':prop_ctc' => $propCtc, ':reco' => $recoPromo, ':due' => $nextDue, ':remarks' => $review, ':chg_by' => $empCode,
    ], 'Could not save the appraisal.');

    if ($action === 'save') {
        return ['Saved Successfully!', null];
    }

    // Submit: legacy needs every question (an Ad-hoc appraisal at least one) and the proposed CTC.
    $base = $appraisal['APPR_BASE'] === 'K' ? 'K' : 'F';
    $pending = [];
    if (($base === 'F' && $appraisal['APPR_TYPE'] === 'A') ? $answered === 0 : $answered < $total) {
        $pending[] = $base === 'K' ? 'every KRA percentage and activity remark' : 'every question';
    }
    if ($total === 0) {
        $pending[] = $base === 'K' ? 'KRAs for the employee (none are set)' : 'questions';
    }
    foreach (['proposed CTC' => $propCtc, 'promotion recommendation' => $recoPromo,
        'next appraisal due' => $nextDue, 'final review' => $review] as $label => $value) {
        if ($value === '') {
            $pending[] = "the {$label}";
        }
    }
    if ($pending) {
        throw new AppraisalRequestException('Please fill in ' . implode(', ', $pending) . ' before submitting.', 422);
    }

    appraisalExecute("UPDATE HR_APPR_EMP_MGR SET STATUS = 'A', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :mgr AND APPR_ID = :appr",
        $scope + [':chg_by' => $empCode], 'Could not submit the appraisal.');
    taskUpdateHR('C', '', $task['ID'], null, null);

    $employeeCode = (string)$manager['EMP_CODE'];
    $next = employeeAppraisalNext($employeeCode, (string)$manager['EMP_CODE_APPR']);
    if ($next === null) {
        return ['Appraisal submitted. This was the last appraiser; the appraisal is ready for Final Appraisal.', null];
    }

    $names = appraisalEmployeeNames([$employeeCode, $next['code']]);
    $office = appraisalRow("SELECT E.COMP_ID, D.DIVSN_ID, D.DEPT_ID FROM HR_EMPLOYEE_INFO E
        LEFT JOIN HR_EMP_OFFICE_DET D ON D.EMP_CODE = E.EMP_CODE AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
        WHERE E.EMP_CODE = :code ORDER BY D.ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => $employeeCode]);
    $nextRow = appraisalInsert('HR_APPR_EMP_MGR', [
        'APPR_ID' => $apprId, 'APPR_LEVEL' => $next['level'], 'EMP_CODE' => $employeeCode, 'EMP_CODE_APPR' => $next['code'],
        'STATUS' => 'I', 'CHG_BY' => $empCode,
    ], ['CHG_ON']);
    $taskId = generateTaskHR('EMP_APPR', $employeeCode, 'Appraisal for ' . ($names[$employeeCode] ?? $employeeCode), null, null, 'O',
        $next['code'], $empCode, $office['COMP_ID'] ?? '', $office['DIVSN_ID'] ?? '', $office['DEPT_ID'] ?? '');
    if (empty($taskId)) {
        throw new RuntimeException('Could not create the next appraisal task.');
    }
    appraisalExecute("UPDATE HR_APPR_EMP_MGR SET TASK_ID = :task WHERE ID = :id", [':task' => (string)$taskId, ':id' => (string)$nextRow],
        'Could not link the appraisal task.');
    if ($base === 'F') {
        foreach (multiRec("SELECT QUESTION_ID FROM HR_APPR_QUESTION WHERE APPR_ID = :appr ORDER BY ID", [':appr' => $apprId]) ?: [] as $question) {
            appraisalInsert('HR_APPR_EMP_APPR', [
                'APPR_ID' => $apprId, 'EMP_MGR_ID' => $nextRow, 'QUES_ID' => $question['QUESTION_ID'], 'CHG_BY' => $empCode,
            ], ['CHG_ON']);
        }
    }
    $nextName = $names[$next['code']] ?? $next['code'];
    return ["Appraisal submitted. Task generated for {$nextName} (level {$next['level']}).", ['nextAppraiser' => $nextName]];
}, 'saveEmployeeAppraisal.php');
