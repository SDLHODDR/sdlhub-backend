<?php
/*
 * Appraisal CTC Change (47) and CTC Approval (48): the new salary structure from the
 * appraisal's new CTC (legacy appr_chg_letter.php / appr_chg_letter_auth.php and
 * appraisal_setup_sv.php saveCTCBreakup / closeApprCTCTask / saveApprCTCAuth / authAppraisalCTC).
 * Heads come from the JD of the (new) organogram; the structure follows the JD rules
 * (legacy get_ctc_structure). Rows live in HR_EMP_CTC_HEADS with SALARY_TYPE 'A' and
 * REF_ID = HR_APPR_ORG_EMP.ID: N (saved), T (sent for approval), A (approved).
 */
require_once __DIR__ . '/appraisalTaskCommon.php';

const APPRAISAL_CTC_SALARY_TYPE = 'A';

function appraisalCtcCeiling($number, $step)
{
    return is_numeric($step) && (float)$step != 0.0 ? ceil((float)$number / (float)$step) * (float)$step : (float)$number;
}

/** JD salary heads of an organogram, in payroll order. */
function appraisalCtcHeads($orgId)
{
    return multiRec("SELECT H.AD_CODE, H.KEY AS CALC_KEY, H.VAL AS CALC_VAL, B.SH_DESCR, B.AD_TYPE, B.RND_OFF, B.RND_TO
        FROM HR_JD_CTC_HEADS H
        INNER JOIN HR_BCS_ALLW_DEDN B ON B.ID = H.AD_ID
        WHERE H.JD_ID = (SELECT JD_ID FROM HR_ORGANOGRAM WHERE ID = :org_id)
          AND SYSDATE BETWEEN H.EFFEC_FROM AND NVL(H.EFFEC_TO, DATE '3000-03-01')
        ORDER BY B.SEQ_NO", [':org_id' => (string)$orgId]) ?: [];
}

/**
 * Monthly structure of an annual CTC (legacy get_ctc_structure_hr): basic from its rule (rounded
 * up to 50), other heads as % of CTC / % of basic / fixed, rounded up to their step; PF per the
 * PF basis (1: 12% of 15000, 0: 12% of basic); the special allowance (or HRA) takes up the rest.
 */
function appraisalCtcStructure(array $heads, $annualCtc, $pfBasis)
{
    $monthlyCtc = (float)$annualCtc / 12;
    $structure = [];
    $basic = 0.0;
    $hasSpecial = false;
    foreach ($heads as $head) {
        $code = (string)$head['AD_CODE'];
        $key = strtoupper((string)$head['CALC_KEY']);
        $value = (float)$head['CALC_VAL'];
        if ($key === 'V') {
            continue;
        }
        if ($code === 'MSPL') {
            $hasSpecial = true;
            continue;
        }
        if ($code === 'MBAS') {
            $basic = $key === 'C' ? appraisalCtcCeiling($monthlyCtc * $value / 100, 50) : $value;
            $amount = $basic;
        } elseif ($code === 'CPF') {
            $amount = $basic < 15000 ? $basic * 12 / 100 : ((int)$pfBasis === 1 ? $value : $basic * 12 / 100);
        } elseif ($key === 'C') {
            $amount = $monthlyCtc * $value / 100;
        } elseif ($key === 'B') {
            $amount = $basic * $value / 100;
        } else {
            $amount = $value;
        }
        if ($code !== 'MBAS' && $key !== 'F' && (($head['RND_OFF'] ?? 'N') !== 'N' || $code === 'MHRA')) {
            $amount = appraisalCtcCeiling($amount, $head['RND_TO'] ?? 0);
        }
        $structure[$code] = round($amount, 2);
    }
    $net = array_sum($structure);
    if ($net <= $monthlyCtc) {
        if ($hasSpecial) {
            $structure['MSPL'] = round(appraisalCtcCeiling($monthlyCtc - $net, 10), 2);
        } elseif (isset($structure['MHRA'])) {
            $structure['MHRA'] = round(appraisalCtcCeiling($structure['MHRA'] + $monthlyCtc - $net, 10), 2);
        }
    } elseif (isset($structure['MHRA'])) {
        $structure['MHRA'] = round(appraisalCtcCeiling($structure['MHRA'] - ($net - $monthlyCtc), 10), 2);
    }
    return $structure;
}

/** Saved heads of the appraisal (code => row). */
function appraisalCtcSaved($refId, $code)
{
    $rows = [];
    foreach (multiRec("SELECT AD_CODE, AMOUNT_MNTH, AMOUNT_YRLY, STATUS FROM HR_EMP_CTC_HEADS
        WHERE REF_ID = :ref AND EMP_CODE = :code AND SALARY_TYPE = 'A'", [':ref' => (string)$refId, ':code' => (string)$code]) ?: [] as $row) {
        $rows[$row['AD_CODE']] = $row;
    }
    return $rows;
}

/** The accounting period end (YYYY-MM-DD) of a date. */
function appraisalPeriodEnd($isoDate)
{
    $row = appraisalRow("SELECT TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DT FROM HR_BCS_ACCT_PERIOD
        WHERE TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN FR_DATE AND TO_DATE", [':dt' => (string)$isoDate]);
    if (empty($row['TO_DT'])) {
        throw new AppraisalRequestException('No accounting period covers the increment effective date.', 422);
    }
    return $row['TO_DT'];
}

/** The organogram whose JD gives the new structure: the promotion's, else the current one. */
function appraisalCtcOrg(array $appr)
{
    return (string)($appr['NEW_ORG_ID'] ?: $appr['ORG_ID']);
}
