<?php
/* GET -> every appraisal (Appraisal List tab) and the options of the Create Appraisal form. */
require_once __DIR__ . '/appraisalCommon.php';

appraisalRunRead(static function () {
    $toOptions = static fn(array $map) => array_map(static fn($value, $label) => ['value' => $value, 'label' => $label],
        array_keys($map), $map);
    $rows = multiRec("SELECT ID, DESCR, GET_SHCOMP_NAME(COMP_ID) AS COMP, APPR_TYPE, APPR_BASE, STATUS,
            TO_CHAR(EFFEC_FROM, 'DD-Mon-YYYY') AS EFFEC_FROM, TO_CHAR(EFFEC_TO, 'DD-Mon-YYYY') AS EFFEC_TO,
            TO_CHAR(PUBLISH_DATE, 'DD-Mon-YYYY') AS PUBLISH_DATE, TO_CHAR(CLOSE_DATE, 'DD-Mon-YYYY') AS CLOSE_DATE,
            EFFEC_FROM AS SORT_FROM
        FROM HR_APPRAISAL_SETUP ORDER BY ID DESC") ?: [];

    return ['Appraisals fetched successfully.', [
        'appraisals' => array_map(static fn($row) => [
            'id' => $row['ID'],
            'company' => $row['COMP'] ?? '',
            'description' => $row['DESCR'] ?? '',
            'type' => APPRAISAL_TYPES[$row['APPR_TYPE']] ?? $row['APPR_TYPE'],
            'base' => APPRAISAL_BASES[$row['APPR_BASE']] ?? $row['APPR_BASE'],
            'effectiveFrom' => $row['EFFEC_FROM'] ?? '',
            'effectiveTo' => $row['EFFEC_TO'] ?? '',
            'publishDate' => $row['PUBLISH_DATE'] ?? '',
            'closeDate' => $row['CLOSE_DATE'] ?? '',
            'status' => $row['STATUS'],
            'statusText' => APPRAISAL_STATUSES[$row['STATUS']] ?? $row['STATUS'],
        ], $rows),
        'companies' => array_map(static fn($row) => ['value' => $row['COMP_ID'], 'label' => $row['COMP_DESC']],
            multiRec("SELECT COMP_ID, COMP_DESC FROM HR_COMPANY ORDER BY COMP_ID") ?: []),
        'types' => $toOptions(APPRAISAL_TYPES),
        'bases' => $toOptions(APPRAISAL_BASES),
        'typeMonths' => APPRAISAL_TYPE_MONTHS,
    ]];
}, 'getAppraisalSetups.php');
