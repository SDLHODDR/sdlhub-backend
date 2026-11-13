<?php
/*
 * Generate Offer (10), Annexure Approval (21), Print Offer Letter (22) and Update Offer Acceptance (12).
 * Legacy offer_generate.php / offer_generate_auth.php and recruitment_sv.php?saveCTC / CloseGOTask /
 * saveCTCAuth / sendCTCMail / authCTC / ClosePrintTask / updateAcct / CloseUpdOffTask.
 * An offer turned down is only recorded (application 'G'); no revised-CTC task is raised.
 */

const RECRUITMENT_AMOUNT_MAX = 99999999;

/** Legacy ceiling(): round up to a multiple of $step (no step = unchanged). */
function recruitmentFlowCeil($amount, $step)
{
    $step = (float)$step;
    return $step > 0 ? ceil($amount / $step) * $step : $amount;
}

/** The JD's current CTC heads in payslip order. */
function recruitmentFlowCtcHeads($jdId)
{
    return multiRec("SELECT A.AD_ID, A.AD_CODE, A.KEY, A.VAL, B.SH_DESCR AS DESCR, B.AD_TYPE, B.RND_OFF, B.RND_TO
        FROM HR_JD_CTC_HEADS A
        INNER JOIN HR_BCS_ALLW_DEDN B ON B.ID = A.AD_ID
        WHERE A.JD_ID = :jd_id AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
        ORDER BY B.SEQ_NO, A.ID", [':jd_id' => (string)(int)$jdId]) ?: [];
}

/**
 * Suggested monthly amount per head (legacy offer_generate.php). KEY: C = % of monthly CTC,
 * B = % of basic, F = fixed, V = not part of the structure. Basic is rounded up to 50; PF is
 * 12% of basic below 15,000. Special allowance (or HRA when there is none) takes up the difference.
 */
function recruitmentFlowSuggestedCtc(array $heads, $ctcLakhs)
{
    $monthlyCtc = ((float)$ctcLakhs * 100000) / 12;
    $amounts = [];
    $basic = 0;
    foreach ($heads as $head) {
        if ($head['AD_CODE'] === 'MBAS' && $head['KEY'] !== 'V') {
            $basic = recruitmentFlowCeil($monthlyCtc * (float)$head['VAL'] / 100, 50);
        }
    }
    foreach ($heads as $head) {
        $code = $head['AD_CODE'];
        $key = (string)$head['KEY'];
        $value = (float)$head['VAL'];
        if ($key === 'V' || $code === 'MSPL') {
            continue;
        }
        if ($code === 'MBAS') {
            $amounts[$code] = $basic;
            continue;
        }
        if ($code === 'CPF' && $basic < 15000) {
            $amount = $basic * 12 / 100;
        } elseif ($key === 'C') {
            $amount = $monthlyCtc * $value / 100;
        } elseif ($key === 'B') {
            $amount = $basic * $value / 100;
        } elseif ($key === 'F') {
            $amount = $value;
        } else {
            $amount = 0;
        }
        if ($head['RND_OFF'] !== 'N' || $code === 'MHRA') {
            $amount = recruitmentFlowCeil($amount, $head['RND_TO']);
        }
        $amounts[$code] = $amount;
    }

    $net = array_sum($amounts);
    $hasSpecial = (bool)array_filter($heads, static fn($head) => $head['AD_CODE'] === 'MSPL');
    if ($net < $monthlyCtc) {
        if ($hasSpecial) {
            $amounts['MSPL'] = recruitmentFlowCeil($monthlyCtc - $net, 10);
        } else {
            $amounts['MHRA'] = recruitmentFlowCeil(($amounts['MHRA'] ?? 0) + ($monthlyCtc - $net), 10);
        }
    } else {
        $amounts['MHRA'] = recruitmentFlowCeil(($amounts['MHRA'] ?? 0) - ($net - $monthlyCtc), 10);
        $amounts['MSPL'] = 0;
    }
    return array_map(static fn($amount) => round($amount, 2), $amounts);
}

/** Latest offer of the interview, with its heads keyed by code. */
function recruitmentFlowOffer(array $interview)
{
    $offer = recruitmentRow("SELECT ID, CTC_OFFER, OFFR_DOC_REF, OFFR_ACCEPTED, TO_CHAR(OFFR_ACCEPT_DATE, 'YYYY-MM-DD') AS ACCEPT_DAY,
            TO_CHAR(CHG_ON, 'DD-Mon-YYYY') AS CHG_ON_TEXT
        FROM HR_OFFERS WHERE INTRV_DATE_ID = :id ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY", [':id' => (string)$interview['ID']]);
    if (empty($offer['ID'])) {
        return [[], []];
    }
    $amounts = [];
    foreach (multiRec("SELECT AD_CODE, AMOUNT_MNTH, AMOUNT_YRLY FROM HR_OFFERS_CTC_HEADS WHERE OFFR_ID = :id ORDER BY ID",
        [':id' => (string)$offer['ID']]) ?: [] as $row) {
        $amounts[$row['AD_CODE']] = ['monthly' => (float)$row['AMOUNT_MNTH'], 'annual' => (float)$row['AMOUNT_YRLY']];
    }
    return [$offer, $amounts];
}

/** Monthly amounts entered for the given head codes: numbers, none negative. */
function recruitmentFlowAmountsInput(array $input, array $codes)
{
    $entered = (array)($input['amounts'] ?? []);
    $amounts = [];
    foreach ($codes as $code) {
        $value = trim((string)($entered[$code] ?? ''));
        if ($value === '' || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value) || (float)$value > RECRUITMENT_AMOUNT_MAX) {
            throw new RecruitmentRequestException('Please enter a valid monthly amount for every CTC head.', 422);
        }
        $amounts[$code] = $value;
    }
    if (array_sum(array_map('floatval', $amounts)) <= 0) {
        throw new RecruitmentRequestException('The offered CTC cannot be zero.', 422);
    }
    return $amounts;
}

/** CTC breakup e-mail / preview (legacy sendCTCMail, without the accept / reject links). */
function recruitmentFlowCtcMailBody(array $context, array $heads, array $amounts)
{
    $e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $money = static fn($value) => number_format((float)$value, 0, '.', '');
    $interview = $context['interview'];
    $company = recruitmentRow("SELECT GET_COMP_NAME(COMPANY) AS CMP FROM HR_ORGANOGRAM WHERE ID = :id",
        [':id' => (string)$context['request']['ORG_ID']]);

    $cell = 'style="border:1px solid #ddd;padding:6px"';
    $right = 'style="border:1px solid #ddd;padding:6px;text-align:right"';
    $rows = '';
    $count = 1;
    $total = 0;
    foreach (['A' => 'Earnings', 'D' => 'Deductions'] as $type => $label) {
        $rows .= '<tr><td colspan="4" ' . $cell . '><b>' . $label . ':</b></td></tr>';
        $sum = 0;
        foreach ($heads as $head) {
            $monthly = $amounts[$head['AD_CODE']]['monthly'] ?? 0;
            if ($head['AD_TYPE'] !== $type || (float)$monthly === 0.0) {
                continue;
            }
            $sum += $monthly;
            $rows .= '<tr><td ' . $cell . '>' . ($count++) . '</td><td ' . $cell . '>' . $e($head['DESCR']) . '</td><td ' . $right . '>'
                . $money($monthly) . '</td><td ' . $right . '>' . $money($monthly * 12) . '</td></tr>';
        }
        $total += $sum;
        if ($type === 'A') {
            $rows .= '<tr><td colspan="2" ' . $cell . '><b>Gross Salary:</b></td><td ' . $right . '><b>' . $money($sum)
                . '</b></td><td ' . $right . '><b>' . $money($sum * 12) . '</b></td></tr>';
        }
    }
    $rows .= '<tr><td colspan="2" ' . $cell . '><b>CTC:</b></td><td ' . $right . '><b>' . $money($total)
        . '</b></td><td ' . $right . '><b>' . $money($total * 12) . '</b></td></tr>';

    $head = 'style="border:1px solid #ddd;padding:6px;background:#006CB5;color:#fff"';
    return '<p>Dear ' . $e(recruitmentFlowCandidateName($context['candidate'])) . ',</p>'
        . '<p>As per our earlier discussion, please find below details :</p>'
        . '<p>Offered Designation: <b>' . $e($context['request']['DESIG'] ?? '') . '</b><br>'
        . 'Offered CTC : <b>' . $e($interview['CTC_OFFER'] ?? '') . ' (LPA Annual)</b><br>'
        . 'Tentative Joining Date : <b>' . $e($interview['TNT_JOIN_TEXT'] ?? '') . '</b></p>'
        . '<table style="border-collapse:collapse;font-family:Arial,sans-serif;width:700px"><thead><tr>'
        . '<th ' . $head . '>No</th><th ' . $head . '>Description</th><th ' . $head . '>Monthly (Rs.)</th><th ' . $head . '>Annual (Rs.)</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p><em>Note : This is a system generated email, please dont revert..in case of any queries get in touch with HR..</em></p>'
        . '<p>Thanks and Regards,<br>HR,<br>' . $e($company['CMP'] ?? '') . '</p>';
}

function recruitmentFlowLoadOfferTask(array $task, array $context)
{
    $interview = $context['interview'];
    $heads = recruitmentFlowCtcHeads($context['request']['JD_ID']);
    [$offer, $amounts] = recruitmentFlowOffer($interview);
    $workflowTaskId = (int)$task['TASK_ID'];

    $offerView = $offer ? [
        'id' => $offer['ID'],
        'reference' => $offer['OFFR_DOC_REF'] ?? '',
        'accepted' => trim((string)($offer['OFFR_ACCEPTED'] ?? '')),
        'acceptDate' => $offer['ACCEPT_DAY'] ?? '',
    ] : null;

    if ($workflowTaskId === RECRUITMENT_TASK_GENERATE_OFFER) {
        $suggested = recruitmentFlowSuggestedCtc($heads, $interview['CTC_OFFER'] ?? 0);
        $rows = [];
        foreach ($heads as $head) {
            $suggestion = $suggested[$head['AD_CODE']] ?? 0;
            if ((float)$suggestion === 0.0) {
                continue;
            }
            $rows[] = [
                'code' => $head['AD_CODE'],
                'description' => $head['DESCR'],
                'suggested' => $suggestion,
                'monthly' => isset($amounts[$head['AD_CODE']]) ? $amounts[$head['AD_CODE']]['monthly'] : null,
            ];
        }
        return ['heads' => $rows, 'offer' => $offerView, 'monthlyCtc' => round(((float)($interview['CTC_OFFER'] ?? 0) * 100000) / 12, 2)];
    }

    if (!$offer) {
        throw new RecruitmentRequestException('No offer has been generated for this candidate.', 409);
    }
    $data = ['offer' => $offerView];
    if ($workflowTaskId === RECRUITMENT_TASK_ANNEXURE_APPROVAL) {
        $data['heads'] = array_values(array_map(static fn($head) => [
            'code' => $head['AD_CODE'],
            'description' => $head['DESCR'],
            'type' => $head['AD_TYPE'],
            'monthly' => $amounts[$head['AD_CODE']]['monthly'],
        ], array_filter($heads, static fn($head) => isset($amounts[$head['AD_CODE']]))));
        $data['emailPreview'] = recruitmentFlowCtcMailBody($context, $heads, $amounts);
        $data['emailTo'] = RECRUITMENT_CTC_MAIL_TO;
        $data['emailCc'] = RECRUITMENT_CTC_MAIL_CC;
    }
    return $data;
}

function recruitmentFlowOfferAction(array $task, array $context, $action, array $input, $empCode)
{
    $request = $context['request'];
    $interview = $context['interview'];
    $application = $context['application'];
    $candidateName = recruitmentFlowCandidateName($context['candidate']);
    $userTaskId = (string)$task['ID'];
    $workflowTaskId = (int)$task['TASK_ID'];
    $heads = recruitmentFlowCtcHeads($request['JD_ID']);
    [$offer, $amounts] = recruitmentFlowOffer($interview);

    if ($workflowTaskId === RECRUITMENT_TASK_GENERATE_OFFER) {
        if (!in_array($action, ['save', 'submit'], true)) {
            throw new RecruitmentRequestException('Invalid action.', 422);
        }
        if ($action === 'submit' && empty($input['amounts'])) {
            if (!$offer) {
                throw new RecruitmentRequestException('Please save the CTC before submitting.', 422);
            }
        } else {
            $suggested = recruitmentFlowSuggestedCtc($heads, $interview['CTC_OFFER'] ?? 0);
            $shown = array_values(array_filter($heads, static fn($head) => (float)($suggested[$head['AD_CODE']] ?? 0) !== 0.0));
            if (!$shown) {
                throw new RecruitmentRequestException('The job description has no CTC structure.', 409);
            }
            $entered = recruitmentFlowAmountsInput($input, array_column($shown, 'AD_CODE'));

            // Legacy deletes the previous offer of the interview and saves a new one.
            $old = multiRec("SELECT ID FROM HR_OFFERS WHERE REQ_ID = :req_id AND CAN_ID = :can_id AND INTRV_DATE_ID = :intrv_id", [
                ':req_id' => (string)$request['ID'], ':can_id' => (string)$application['CAN_ID'], ':intrv_id' => (string)$interview['ID'],
            ]) ?: [];
            foreach ($old as $row) {
                recruitmentExecute("DELETE FROM HR_OFFERS_CTC_HEADS WHERE OFFR_ID = :id", [':id' => (string)$row['ID']], 'Could not save the offer.');
                recruitmentExecute("DELETE FROM HR_OFFERS WHERE ID = :id", [':id' => (string)$row['ID']], 'Could not save the offer.');
            }
            $offerId = recruitmentFlowInsert('HR_OFFERS', [
                'REQ_ID' => $request['ID'],
                'ORG_ID' => $request['ORG_ID'],
                'ORG_LOC_ID' => $request['ORG_LOC_ID'],
                'CAN_ID' => $application['CAN_ID'],
                'OFFR_TYPE' => 'I',
                'INTRV_DATE_ID' => $interview['ID'],
                'CTC_OFFER' => $interview['CTC_OFFER'] ?? '',
                'ETYPE_ID' => $interview['ETYPE_ID'] ?? '',
                'ETYPE_PERIOD' => $interview['ETYPE_PERIOD'] ?? '',
                'OFFR_PRPR_BY' => $empCode,
                'CHG_BY' => $empCode,
            ], ['OFFR_PRPR_ON', 'CHG_ON']);
            recruitmentExecute("UPDATE HR_OFFERS SET TNT_JOIN_DATE = TO_DATE(:join_date, 'YYYY-MM-DD') WHERE ID = :id",
                [':join_date' => (string)($interview['TNT_JOIN_DAY'] ?? ''), ':id' => (string)$offerId], 'Could not save the offer.');
            $headIds = array_column($heads, 'AD_ID', 'AD_CODE');
            foreach ($entered as $code => $monthly) {
                recruitmentFlowInsert('HR_OFFERS_CTC_HEADS', [
                    'OFFR_ID' => $offerId,
                    'AD_ID' => $headIds[$code] ?? '',
                    'AD_CODE' => $code,
                    'AMOUNT_MNTH' => $monthly,
                    'AMOUNT_YRLY' => (string)round((float)$monthly * 12, 2),
                    'CHG_BY' => $empCode,
                ], ['CHG_ON']);
            }
            recruitmentFlowSetApplicationStatus($application, 'O', $userTaskId, $empCode);
        }
        if ($action === 'save') {
            return ['CTC saved.', null];
        }
        recruitmentCloseTask($userTaskId, $empCode);
        recruitmentFlowCreateTask('ANNEX_AUTH', $interview['ID'], 'Annexure Approval ' . $candidateName,
            RECRUITMENT_ANNEXURE_APPROVER, $empCode, $request);
        return ['Offer submitted for annexure approval.', null];
    }

    if (!$offer) {
        throw new RecruitmentRequestException('No offer has been generated for this candidate.', 409);
    }

    if ($workflowTaskId === RECRUITMENT_TASK_ANNEXURE_APPROVAL) {
        if ($action === 'save' || $action === 'authorize') {
            if ($action === 'save' || !empty($input['amounts'])) {
                $entered = recruitmentFlowAmountsInput($input, array_keys($amounts));
                foreach ($entered as $code => $monthly) {
                    recruitmentExecute("UPDATE HR_OFFERS_CTC_HEADS SET AMOUNT_MNTH = :monthly, AMOUNT_YRLY = :annual, CHG_ON = SYSDATE, CHG_BY = :chg_by
                        WHERE OFFR_ID = :offer_id AND AD_CODE = :code", [
                        ':monthly' => $monthly, ':annual' => (string)round((float)$monthly * 12, 2), ':chg_by' => $empCode,
                        ':offer_id' => (string)$offer['ID'], ':code' => $code,
                    ], 'Could not save the CTC.');
                }
            }
            if ($action === 'save') {
                return ['CTC saved.', null];
            }
            recruitmentCloseTask($userTaskId, $empCode);
            recruitmentFlowCreateTask('OFFER_SEND', $interview['ID'], 'Offer Printing for ' . $candidateName, null, $empCode, $request);
            return ['Annexure approved. A Print Offer Letter task has been generated.', null];
        }
        if ($action !== 'email') {
            throw new RecruitmentRequestException('Invalid action.', 422);
        }
        if (!recruitmentFlowQueueMail(RECRUITMENT_CTC_MAIL_TO, RECRUITMENT_CTC_MAIL_CC, 'CTC Breakup',
            recruitmentFlowCtcMailBody($context, $heads, $amounts), 'HR Admin')) {
            throw new RuntimeException('Could not queue the CTC breakup e-mail.');
        }
        return ['CTC breakup e-mail queued.', null];
    }

    if ($workflowTaskId === RECRUITMENT_TASK_PRINT_OFFER) {
        if ($action !== 'close') {
            throw new RecruitmentRequestException('Invalid action.', 422);
        }
        recruitmentCloseTask($userTaskId, $empCode);
        recruitmentFlowCreateTask('UPDATE_OFFER_ACCEP', $interview['ID'], 'Update Offer Acceptance  ' . $candidateName,
            $request['ASSIGNED_TO'], $empCode, $request);
        return ['Task closed. An Update Offer Acceptance task has been generated for the recruiter.', null];
    }

    // Update Offer Acceptance (12).
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new RecruitmentRequestException('Invalid action.', 422);
    }
    if ($action === 'save') {
        $accepted = strtoupper(trim((string)($input['accepted'] ?? '')));
        if (!in_array($accepted, ['Y', 'N'], true)) {
            throw new RecruitmentRequestException('Please select the offer acceptance.', 422);
        }
        $date = trim((string)($input['date'] ?? ''));
        recruitmentAssertDate($date, 'Tentative joining date');
        recruitmentExecute("UPDATE HR_OFFERS SET OFFR_ACCEPTED = :accepted, OFFR_ACCEPT_DATE = TO_DATE(:dt, 'YYYY-MM-DD'),
                CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
            [':accepted' => $accepted, ':dt' => $date, ':chg_by' => $empCode, ':id' => (string)$offer['ID']], 'Could not save the acceptance.');
        // Legacy logs a declined offer as 'C'.
        recruitmentFlowSetApplicationStatus($application, $accepted === 'Y' ? 'J' : 'G', $userTaskId, $empCode, $accepted === 'Y' ? 'J' : 'C');
        return ['Offer acceptance saved.', null];
    }
    if (trim((string)($offer['OFFR_ACCEPTED'] ?? '')) === '') {
        throw new RecruitmentRequestException('Please save the offer acceptance before submitting.', 422);
    }
    recruitmentCloseTask($userTaskId, $empCode);
    return [trim((string)$offer['OFFR_ACCEPTED']) === 'Y'
        ? 'Task closed. The candidate is now listed under New Joinee.'
        : 'Task closed.', null];
}
