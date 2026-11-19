<?php
/*
 * GET ?user_task_id=[&org_id=] - Employee Promotion (49) / Promotion Auth (50), legacy appr_promo_request.php /
 * appr_promo_req_auth.php: current position, the promotion decision, organograms in the user's scope and the free
 * locations of the chosen organogram on the promotion date. With org_id only the locations are returned.
 */
require_once __DIR__ . '/appraisalTaskCommon.php';

/** Locations of an organogram free on a date (nobody's office on that date), plus $keep. */
function appraisalFreeLocations($orgId, $date, $keep = '')
{
    return array_map(static fn($row) => ['value' => (string)$row['ID'], 'label' => $row['ID'] . ' - ' . $row['GEO_DESC']],
        multiRec("SELECT HOL.ID, HOL.GEO_DESC FROM HR_ORGANOGRAM_LOC HOL
            WHERE HOL.ORG_ID = :org_id
              AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN HOL.EFFEC_FROM AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
              AND (HOL.ID = :keep OR NOT EXISTS (SELECT 1 FROM HR_EMP_OFFICE_DET D
                    WHERE D.ORG_LOC_ID = HOL.ID AND TO_DATE(:dt, 'YYYY-MM-DD') BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')))
            ORDER BY HOL.ID", [':org_id' => (string)$orgId, ':dt' => (string)$date, ':keep' => (string)$keep]) ?: []);
}

employeeAppraisalRunRead(function () use ($empCode) {
    $task = appraisalLoadFollowUpTask($_GET['user_task_id'] ?? 0, $empCode, [49, 50]);
    $appr = $task['APPR'];
    $date = $appr['PROMO_FROM'] ?: ($appr['INCR_FROM'] ?: date('Y-m-d'));

    $orgId = (int)($_GET['org_id'] ?? 0);
    if ($orgId > 0) {
        return ['Locations fetched successfully.', ['locations' => appraisalFreeLocations($orgId, $date,
            (string)$orgId === (string)$appr['NEW_ORG_ID'] ? (string)$appr['NEW_ORG_LOC_ID'] : '')]];
    }

    $code = (string)$task['TRAN_CODE'];
    $employee = appraisalFollowUpEmployee($code);
    $in = static fn($values) => implode(',', array_map('intval', $values ?: [-1]));
    $organograms = multiRec("SELECT ID, GET_SHCOMP_NAME(COMPANY) || ' - ' || GET_DIVISION_NAME(DIVSN_ID) || ' - '
            || GET_DEPT_NAME(DEPT_ID) || ' - ' || GET_DESIGN_NAME(DESI_ID) AS LABEL
        FROM HR_ORGANOGRAM
        WHERE COMPANY IN (" . $in($_SESSION['compId'] ?? []) . ") AND DIVSN_ID IN (" . $in($_SESSION['divId'] ?? []) . ")
          AND DEPT_ID IN (" . $in($_SESSION['deptId'] ?? []) . ")
        ORDER BY ID DESC") ?: [];
    $currentOrg = appraisalRow("SELECT GET_ORG_NAME(:id) AS NM FROM DUAL", [':id' => (string)$employee['ORG_ID']]);
    $approver = appraisalEmployeeNames([(string)($task['MANAGER']['EMP_CODE_APPR'] ?? '')]);

    return ['Promotion fetched successfully.', [
        'taskId' => (string)$task['TASK_ID'],
        'employee' => [
            'code' => $code,
            'name' => (string)$employee['NAME'],
            'doj' => (string)$employee['DOJ'],
            'designation' => (string)$employee['DESIGNATION'],
            'department' => (string)$employee['DEPARTMENT'],
            'division' => (string)$employee['DIVISION'],
            'location' => (string)$employee['LOCATION'],
            'organogram' => (string)($currentOrg['NM'] ?? ''),
        ],
        'promotion' => [
            'approvedBy' => $approver[$task['MANAGER']['EMP_CODE_APPR'] ?? ''] ?? (string)($task['MANAGER']['EMP_CODE_APPR'] ?? ''),
            'promotionFrom' => appraisalDisplayDate($appr['PROMO_FROM']),
            'incrementFrom' => appraisalDisplayDate($appr['INCR_FROM']),
            'remarks' => (string)($task['MANAGER']['APPR_REMARKS'] ?? ''),
            'orgId' => (string)($appr['NEW_ORG_ID'] ?? ''),
            'orgLocId' => (string)($appr['NEW_ORG_LOC_ID'] ?? ''),
        ],
        'organograms' => array_map(static fn($row) => ['value' => (string)$row['ID'], 'label' => (string)$row['LABEL']], $organograms),
        'locations' => $appr['NEW_ORG_ID'] ? appraisalFreeLocations($appr['NEW_ORG_ID'], $date, (string)$appr['NEW_ORG_LOC_ID']) : [],
    ]];
}, 'getAppraisalPromotion.php');
