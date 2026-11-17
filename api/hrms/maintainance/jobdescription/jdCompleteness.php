<?php

/*
 * JD completeness check shared by getJobDescriptions.php, sendForAuth.php
 * and authorization/masters/authorizeJobDescription.php.
 *
 * Every JD tab must hold data before the JD can be sent for authorization
 * or accepted. Downstream flows depend on it, e.g. exit/joining activity
 * tasks are created per Department Reference (HR_JD_REF_DEPT).
 */

const JD_REQUIRED_TABS = [
    'basic' => 'Basic Details',
    'responsibilities' => 'Responsibilities',
    'kra' => 'KRA',
    'education' => 'Education',
    'skills' => 'Skills',
    'allowances' => 'Allowances/Reimbursement',
    'ctc' => 'CTC Heads',
    'questions' => 'Question Template',
    'deptref' => 'Department Reference',
    'division' => 'Division Mapping',
    'induction' => 'Induction',
];

/**
 * Returns the tabs of a JD that have no data, as [['key' => .., 'label' => ..], ...].
 * Throws when the query cannot run.
 */
function jdMissingTabs($conn, $jdId)
{
    $sql = "SELECT
                CASE WHEN TRIM(J.SH_DESC) IS NOT NULL AND J.DEPT_ID IS NOT NULL
                          AND J.DESIG_ID IS NOT NULL AND J.LVL_ID IS NOT NULL
                     THEN 1 ELSE 0 END AS BASIC,
                (SELECT COUNT(*) FROM HR_JD_DESCRIPTION X WHERE X.JD_ID = J.ID) AS RESPONSIBILITIES,
                (SELECT COUNT(*) FROM HR_JD_KRA X WHERE X.JD_ID = J.ID) AS KRA,
                (SELECT COUNT(*) FROM HR_JD_EDU_DET X WHERE X.JD_ID = J.ID) AS EDUCATION,
                (SELECT COUNT(*) FROM HR_JD_CAPABILITIES X WHERE X.JD_ID = J.ID) AS SKILLS,
                (SELECT COUNT(*) FROM HR_JD_ALLOWANCES X WHERE X.JD_ID = J.ID) AS ALLOWANCES,
                (SELECT COUNT(*) FROM HR_JD_CTC_HEADS X WHERE X.JD_ID = J.ID) AS CTC,
                (SELECT COUNT(*) FROM HR_JD_QUESTIONS X WHERE X.JD_ID = J.ID) AS QUESTIONS,
                (SELECT COUNT(*) FROM HR_JD_REF_DEPT X WHERE X.JD_ID = J.ID) AS DEPTREF,
                (SELECT COUNT(*) FROM HR_JD_DIVSN X WHERE X.JD_ID = J.ID) AS DIVISION,
                (SELECT COUNT(*) FROM HR_JD_INDUCTION X WHERE X.JD_ID = J.ID) AS INDUCTION
            FROM HR_JD J
            WHERE J.ID = :jd_id";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the JD completeness check.');
    }
    $jdIdValue = (string)$jdId;
    oci_bind_by_name($stmt, ':jd_id', $jdIdValue);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        throw new RuntimeException('Unable to run the JD completeness check. ' . ($error['message'] ?? ''));
    }
    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    $missing = [];
    foreach (JD_REQUIRED_TABS as $key => $label) {
        if (!$row || (int)($row[strtoupper($key)] ?? 0) <= 0) {
            $missing[] = ['key' => $key, 'label' => $label];
        }
    }
    return $missing;
}

function jdMissingTabsMessage(array $missing, $prefix)
{
    return $prefix . ' ' . implode(', ', array_column($missing, 'label')) . '.';
}
