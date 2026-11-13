<?php
/*
 * Final Appraisal (legacy final_appraisal.php, appraisal_setup_sv.php?SaveFinalAppr / SubmitFinalAppr).
 * An employee reaches it once an appraiser has approved their appraisal (HR_APPR_EMP_MGR 'A'); the latest
 * approved review holds the recommendation. HR saves the final promotion, monthly increment, effective
 * dates and remarks onto that review (as legacy, replacing the appraiser's figures) and HR_APPR_ORG_EMP,
 * then Submit marks the employee 'A' and raises the CTC Change (47) and, when promoted, Promotion (49) tasks.
 */
define('APPRAISAL_PAGE', 'final');
require_once __DIR__ . '/appraisalCommon.php';

const FINAL_APPRAISAL_REMARK_MAX = 4000;
const FINAL_APPRAISAL_INCREMENT_MAX = 9999999;

/** Employees of the appraisal with their latest approved review and appraisal row, keyed by employee code. */
function finalAppraisalEmployees($appraisalId)
{
    $rows = multiRec("SELECT M.ID AS MGR_ID, M.EMP_CODE, M.EMP_CODE_APPR, M.PROP_CTC, M.RECO_PROMO, M.APPR_REMARKS, M.TASK_ID,
            O.ID AS ORG_EMP_ID, O.STATUS AS ORG_STATUS, O.CURR_CTC,
            TO_CHAR(O.INCR_EFFEC_FROM, 'YYYY-MM-DD') AS INCR_FROM, TO_CHAR(O.PROMO_EFFEC_FROM, 'YYYY-MM-DD') AS PROMO_FROM
        FROM (SELECT X.*, ROW_NUMBER() OVER (PARTITION BY X.EMP_CODE ORDER BY X.ID DESC) AS RN
              FROM HR_APPR_EMP_MGR X WHERE X.APPR_ID = :id AND X.STATUS = 'A') M
        INNER JOIN HR_APPR_ORG_EMP O ON O.APPR_ID = M.APPR_ID AND O.EMP_CODE = M.EMP_CODE
        WHERE M.RN = 1", [':id' => (string)$appraisalId]) ?: [];
    $list = [];
    foreach ($rows as $row) {
        $list[$row['EMP_CODE']] = $row;
    }
    return $list;
}

/** First day of the month the increment may start from (legacy month picker minimum). */
function finalAppraisalMinMonth(array $appraisal)
{
    $applied = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($appraisal['INCR_EFFEC_FROM'] ?? ''));
    return $applied ? $applied->modify('first day of this month') : null;
}
