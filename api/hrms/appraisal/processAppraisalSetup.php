<?php
/* POST { action, ... } -> Appraisal Setup changes (legacy appraisal_setup_sv.php):
 *   save            id?, company, description, type, base, effective_from, effective_to (Ad-hoc), base_days, applied_from
 *   add_questions   id, question_ids[]           delete_question  id, question_id
 *   delete_group    id, group_id                 move_question    id, question_id, direction up|down
 *   add_employees   id, org_id, employees[{code, location_id}]   delete_employee  id, emp_code
 *   publish         id   (appraiser, Employee Appraisal task and question rows per employee)
 *   close           id */
require_once __DIR__ . '/appraisalCommon.php';

function appraisalDateInput(array $input, $key, $label)
{
    $value = trim((string)($input[$key] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new AppraisalRequestException("Please select the {$label}.", 422);
    }
    return $date;
}

/** Questions of one group in display order. */
function appraisalGroupQuestions($appraisalId, $groupId)
{
    return multiRec("SELECT A.ID, A.QUESTION_ID, A.DISP_SEQ FROM HR_APPR_QUESTION A
        INNER JOIN HR_QUESTION_MASTER B ON B.ID = A.QUESTION_ID
        WHERE A.APPR_ID = :id AND B.QGRP_ID = :grp ORDER BY A.DISP_SEQ, A.ID",
        [':id' => (string)$appraisalId, ':grp' => (string)$groupId]) ?: [];
}

/** Number a group's questions 1..n in their current order. */
function appraisalResequence($appraisalId, $groupId, $empCode)
{
    foreach (appraisalGroupQuestions($appraisalId, $groupId) as $index => $row) {
        if ((int)$row['DISP_SEQ'] !== $index + 1) {
            appraisalExecute("UPDATE HR_APPR_QUESTION SET DISP_SEQ = :seq, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
                [':seq' => (string)($index + 1), ':chg_by' => $empCode, ':id' => (string)$row['ID']], 'Could not reorder the questions.');
        }
    }
}

function appraisalQuestionGroup($questionId)
{
    $row = appraisalRow("SELECT QGRP_ID FROM HR_QUESTION_MASTER WHERE ID = :id", [':id' => (string)(int)$questionId]);
    return $row['QGRP_ID'] ?? null;
}

appraisalRunAction(static function () use ($empCode) {
    $input = appraisalInput();
    $action = strtolower(trim((string)($input['action'] ?? '')));

    if ($action === 'save') {
        $id = trim((string)($input['id'] ?? ''));
        if ($id !== '') {
            appraisalRequireNew(appraisalLoad($id));
        }
        $company = trim((string)($input['company'] ?? ''));
        if (!ctype_digit($company) || empty(appraisalRow("SELECT COMP_ID FROM HR_COMPANY WHERE COMP_ID = :id", [':id' => $company])['COMP_ID'])) {
            throw new AppraisalRequestException('Please select the company.', 422);
        }
        $description = trim((string)($input['description'] ?? ''));
        if ($description === '') {
            throw new AppraisalRequestException('Please enter the description.', 422);
        }
        if (strlen($description) > APPRAISAL_DESCR_MAX) {
            throw new AppraisalRequestException('The description is too long (maximum ' . APPRAISAL_DESCR_MAX . ' characters).', 422);
        }
        $type = strtoupper(trim((string)($input['type'] ?? '')));
        if (!isset(APPRAISAL_TYPES[$type])) {
            throw new AppraisalRequestException('Please select the appraisal type.', 422);
        }
        $base = strtoupper(trim((string)($input['base'] ?? '')));
        if (!isset(APPRAISAL_BASES[$base])) {
            throw new AppraisalRequestException('Please select the appraisal base.', 422);
        }
        $from = appraisalDateInput($input, 'effective_from', 'effective from date');
        // Effective To: the end of the period for a fixed type, as entered for Ad-hoc (legacy SaveAppraisal).
        if ($type === 'A') {
            $to = appraisalDateInput($input, 'effective_to', 'effective to date');
            if ($to < $from) {
                throw new AppraisalRequestException('Effective To cannot be before Effective From.', 422);
            }
        } else {
            $to = $from->modify('+' . APPRAISAL_TYPE_MONTHS[$type] . ' months')->modify('-1 day');
        }
        $days = trim((string)($input['base_days'] ?? ''));
        if (!preg_match('/^\d{1,4}$/', $days) || (int)$days <= 0) {
            throw new AppraisalRequestException('Please enter the appraisal base days.', 422);
        }
        $applied = appraisalDateInput($input, 'applied_from', 'appraisal applied from date');

        $binds = [
            ':descr' => $description, ':comp' => $company, ':type' => $type, ':base' => $base, ':days' => $days,
            ':from_dt' => $from->format('Y-m-d'), ':to_dt' => $to->format('Y-m-d'), ':applied' => $applied->format('Y-m-d'),
            ':chg_by' => $empCode,
        ];
        if ($id !== '') {
            appraisalExecute("UPDATE HR_APPRAISAL_SETUP SET DESCR = :descr, COMP_ID = :comp, APPR_TYPE = :type, APPR_BASE = :base,
                    EFFEC_FROM = TO_DATE(:from_dt, 'YYYY-MM-DD'), EFFEC_TO = TO_DATE(:to_dt, 'YYYY-MM-DD'), APPR_DAYS = :days,
                    INCR_EFFEC_FROM = TO_DATE(:applied, 'YYYY-MM-DD'), CHG_ON = SYSDATE, CHG_BY = :chg_by
                WHERE ID = :id AND STATUS = 'N'", $binds + [':id' => $id], 'Could not update the appraisal.');
            return ['Updated Successfully!', ['id' => $id]];
        }
        global $sql___func___con;
        $stmt = oci_parse($sql___func___con, "INSERT INTO HR_APPRAISAL_SETUP (DESCR, COMP_ID, APPR_TYPE, APPR_BASE, EFFEC_FROM, EFFEC_TO,
                APPR_DAYS, INCR_EFFEC_FROM, STATUS, CHG_ON, CHG_BY)
            VALUES (:descr, :comp, :type, :base, TO_DATE(:from_dt, 'YYYY-MM-DD'), TO_DATE(:to_dt, 'YYYY-MM-DD'),
                :days, TO_DATE(:applied, 'YYYY-MM-DD'), 'N', SYSDATE, :chg_by) RETURNING ID INTO :new_id");
        foreach ($binds as $key => $value) {
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }
        $newId = '';
        oci_bind_by_name($stmt, ':new_id', $newId, 40);
        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            logOracleError(oci_error($stmt), 'INSERT HR_APPRAISAL_SETUP');
            throw new RuntimeException('Could not save the appraisal.');
        }
        return ['Saved Successfully!', ['id' => $newId]];
    }

    $appraisal = appraisalLoad($input['id'] ?? 0);
    $id = (string)$appraisal['ID'];

    if ($action === 'close') {
        if ($appraisal['STATUS'] !== 'P') {
            throw new AppraisalRequestException('Only a published appraisal can be closed.', 409);
        }
        appraisalExecute("UPDATE HR_APPRAISAL_SETUP SET STATUS = 'C', CLOSE_DATE = SYSDATE, CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE ID = :id AND STATUS = 'P'", [':id' => $id, ':chg_by' => $empCode], 'Could not close the appraisal.');
        return ['Closed Successfully', null];
    }

    appraisalRequireNew($appraisal);

    if ($action === 'add_questions') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['question_ids'] ?? [])))));
        if (!$ids) {
            throw new AppraisalRequestException('Please select one or more questions.', 422);
        }
        $added = 0;
        foreach ($ids as $questionId) {
            $question = appraisalRow("SELECT Q.ID, Q.QGRP_ID,
                    (SELECT COUNT(*) FROM HR_APPR_QUESTION A WHERE A.APPR_ID = :id AND A.QUESTION_ID = Q.ID) AS ADDED
                FROM HR_QUESTION_MASTER Q WHERE Q.ID = :qid AND Q.STATUS = 'A'", [':id' => $id, ':qid' => (string)$questionId]);
            if (empty($question['ID'])) {
                throw new AppraisalRequestException('A selected question is no longer active.', 422);
            }
            if ((int)$question['ADDED'] > 0) {
                continue;
            }
            // Next display sequence within the question's group.
            $next = count(appraisalGroupQuestions($id, $question['QGRP_ID'])) + 1;
            appraisalInsert('HR_APPR_QUESTION', ['APPR_ID' => $id, 'QUESTION_ID' => $questionId, 'DISP_SEQ' => $next, 'CHG_BY' => $empCode], ['CHG_ON']);
            $added++;
        }
        return [$added ? 'Saved Successfully!' : 'The selected questions are already in the appraisal.', null];
    }

    if ($action === 'delete_question') {
        $questionId = (string)(int)($input['question_id'] ?? 0);
        $groupId = appraisalQuestionGroup($questionId);
        appraisalExecute("DELETE FROM HR_APPR_QUESTION WHERE APPR_ID = :id AND QUESTION_ID = :qid",
            [':id' => $id, ':qid' => $questionId], 'Could not delete the question.');
        if ($groupId !== null) {
            appraisalResequence($id, $groupId, $empCode);
        }
        return ['Deleted Successfully!', null];
    }

    if ($action === 'delete_group') {
        $groupId = (string)(int)($input['group_id'] ?? 0);
        appraisalExecute("DELETE FROM HR_APPR_QUESTION WHERE APPR_ID = :id
            AND QUESTION_ID IN (SELECT ID FROM HR_QUESTION_MASTER WHERE QGRP_ID = :grp)",
            [':id' => $id, ':grp' => $groupId], 'Could not delete the question group.');
        return ['Deleted Successfully!', null];
    }

    if ($action === 'move_question') {
        $questionId = (string)(int)($input['question_id'] ?? 0);
        $direction = (string)($input['direction'] ?? '');
        $groupId = appraisalQuestionGroup($questionId);
        $rows = $groupId === null ? [] : appraisalGroupQuestions($id, $groupId);
        $index = array_search($questionId, array_column($rows, 'QUESTION_ID'), true);
        $other = $index === false ? false : ($direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : false));
        if ($other === false || !isset($rows[$other])) {
            throw new AppraisalRequestException('The question cannot be moved further.', 422);
        }
        // Swap positions, then renumber so the group stays 1..n.
        appraisalExecute("UPDATE HR_APPR_QUESTION SET DISP_SEQ = :seq, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
            [':seq' => (string)($other + 1), ':chg_by' => $empCode, ':id' => (string)$rows[$index]['ID']], 'Could not move the question.');
        appraisalExecute("UPDATE HR_APPR_QUESTION SET DISP_SEQ = :seq, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
            [':seq' => (string)($index + 1), ':chg_by' => $empCode, ':id' => (string)$rows[$other]['ID']], 'Could not move the question.');
        appraisalResequence($id, $groupId, $empCode);
        return ['Sequence updated.', null];
    }

    if ($action === 'add_employees') {
        $orgId = trim((string)($input['org_id'] ?? ''));
        if (!ctype_digit($orgId)) {
            throw new AppraisalRequestException('Please select an organogram.', 422);
        }
        $org = appraisalRow("SELECT O.ID FROM HR_ORGANOGRAM O WHERE O.ID = :org AND O.COMPANY = :comp AND " . appraisalOrgScopeSql('O'),
            [':org' => $orgId, ':comp' => (string)$appraisal['COMP_ID']]);
        if (empty($org['ID'])) {
            throw new AppraisalRequestException('This organogram is not available for the appraisal.', 403);
        }
        if ((int)(appraisalRow("SELECT COUNT(*) AS CNT FROM HR_ORG_APPR_LEVELS WHERE ORG_ID = :org", [':org' => $orgId])['CNT'] ?? 0) === 0) {
            throw new AppraisalRequestException('Please set Appraisal Level!', 409);
        }
        $selected = array_filter((array)($input['employees'] ?? []), 'is_array');
        if (!$selected) {
            throw new AppraisalRequestException('Please select one or more employees!', 422);
        }
        $added = 0;
        foreach ($selected as $employee) {
            $code = trim((string)($employee['code'] ?? ''));
            $locationId = trim((string)($employee['location_id'] ?? ''));
            $valid = appraisalRow("SELECT COUNT(*) AS CNT FROM HR_ORGANOGRAM_LOC L
                INNER JOIN HR_EMP_OFFICE_DET D ON D.ORG_LOC_ID = L.ID AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
                INNER JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = D.EMP_CODE AND E.STATUS = 'A'
                WHERE L.ID = :loc AND L.ORG_ID = :org AND E.EMP_CODE = :code
                  AND EXISTS (SELECT 1 FROM HR_EMP_TENURE T WHERE T.EMP_CODE = E.EMP_CODE AND T.ETYPE_ID IN ('" . implode("','", APPRAISAL_EMP_TYPES) . "'))",
                [':loc' => $locationId, ':org' => $orgId, ':code' => $code]);
            if ((int)($valid['CNT'] ?? 0) === 0) {
                throw new AppraisalRequestException("Employee {$code} is not in this organogram.", 422);
            }
            $already = appraisalRow("SELECT COUNT(*) AS CNT FROM HR_APPR_ORG_EMP WHERE APPR_ID = :id AND ORG_LOC_ID = :loc",
                [':id' => $id, ':loc' => $locationId]);
            if ((int)($already['CNT'] ?? 0) > 0) {
                continue;
            }
            $ctc = appraisalRow("SELECT GET_TOTAL_CTC(:code, SYSDATE) AS VAL FROM DUAL", [':code' => $code]);
            appraisalInsert('HR_APPR_ORG_EMP', [
                'APPR_ID' => $id, 'ORG_ID' => $orgId, 'ORG_LOC_ID' => $locationId, 'EMP_CODE' => $code,
                'CURR_CTC' => $ctc['VAL'] ?? '', 'STATUS' => 'I', 'CHG_BY' => $empCode,
            ], ['CHG_ON']);
            $added++;
        }
        return [$added ? 'Saved Successfully!' : 'The selected employees are already in the appraisal.', null];
    }

    if ($action === 'delete_employee') {
        appraisalExecute("DELETE FROM HR_APPR_ORG_EMP WHERE APPR_ID = :id AND EMP_CODE = :code",
            [':id' => $id, ':code' => trim((string)($input['emp_code'] ?? ''))], 'Could not remove the employee.');
        return ['Deleted Successfully!', null];
    }

    if ($action !== 'publish') {
        throw new AppraisalRequestException('Invalid action.', 422);
    }

    $employees = multiRec("SELECT EMP_CODE FROM HR_APPR_ORG_EMP WHERE APPR_ID = :id ORDER BY ID", [':id' => $id]) ?: [];
    $questions = multiRec("SELECT QUESTION_ID FROM HR_APPR_QUESTION WHERE APPR_ID = :id ORDER BY ID", [':id' => $id]) ?: [];
    if (!$employees || !$questions) {
        throw new AppraisalRequestException('Add at least one question and one employee before publishing.', 422);
    }
    appraisalExecute("UPDATE HR_APPRAISAL_SETUP SET STATUS = 'P', PUBLISH_DATE = SYSDATE, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE ID = :id AND STATUS = 'N'", [':id' => $id, ':chg_by' => $empCode], 'Could not publish the appraisal.');

    $names = appraisalEmployeeNames(array_column($employees, 'EMP_CODE'));
    $withoutAppraiser = [];
    foreach ($employees as $employee) {
        $code = $employee['EMP_CODE'];
        $appraiser = appraisalAppraisers($code)['first'];
        if ($appraiser === null) {
            $withoutAppraiser[] = $code;
        }
        $office = appraisalRow("SELECT E.COMP_ID, D.DIVSN_ID, D.DEPT_ID FROM HR_EMPLOYEE_INFO E
            LEFT JOIN HR_EMP_OFFICE_DET D ON D.EMP_CODE = E.EMP_CODE AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')
            WHERE E.EMP_CODE = :code ORDER BY D.ID DESC FETCH FIRST 1 ROWS ONLY", [':code' => $code]);
        $managerRow = appraisalInsert('HR_APPR_EMP_MGR', [
            'APPR_ID' => $id, 'APPR_LEVEL' => 1, 'EMP_CODE' => $code, 'EMP_CODE_APPR' => $appraiser ?? '', 'STATUS' => 'I', 'CHG_BY' => $empCode,
        ], ['CHG_ON']);
        $taskId = generateTaskHR('EMP_APPR', $code, 'Appraisal for ' . ($names[$code] ?? $code), null, null, 'O', $appraiser, $empCode,
            $office['COMP_ID'] ?? '', $office['DIVSN_ID'] ?? '', $office['DEPT_ID'] ?? '');
        appraisalExecute("UPDATE HR_APPR_EMP_MGR SET TASK_ID = :task WHERE ID = :id", [':task' => (string)$taskId, ':id' => (string)$managerRow],
            'Could not link the appraisal task.');
        foreach ($questions as $question) {
            appraisalInsert('HR_APPR_EMP_APPR', [
                'APPR_ID' => $id, 'EMP_MGR_ID' => $managerRow, 'QUES_ID' => $question['QUESTION_ID'], 'CHG_BY' => $empCode,
            ], ['CHG_ON']);
        }
    }
    $message = 'Publish Successfully!';
    if ($withoutAppraiser) {
        // As in legacy, their appraisal task is created without an appraiser.
        $message .= ' No appraiser was found for ' . implode(', ', $withoutAppraiser) . '; their appraisal task is unassigned.';
    }
    return [$message, ['withoutAppraiser' => $withoutAppraiser]];
}, 'processAppraisalSetup.php');
