<?php
/*
 * POST { rec_id } apply for an open job (legacy application_form_sv.php?ApplyCand).
 * Needs a verified candidate whose profile reaches the required completeness.
 * The application (HR_CAN_APPLICATION_DETAILS, status empty) is what the recruiter
 * picks from in Send Suitable Candidates (task 6).
 */
require_once __DIR__ . '/careersCommon.php';

$input = careersInput();

careersRunAction(static function () use ($input) {
    $candidate = careersCandidate(true);
    if (($candidate['STATUS'] ?? '') === 'B') {
        throw new CareersException('You cannot apply for jobs at the moment. Please contact HR.', 403);
    }
    $recId = (int)($input['rec_id'] ?? 0);
    $job = careersRow("SELECT HR.ID, HR.ORG_ID, HO.DEPT_ID, HO.DESI_ID FROM HR_RECRUITMENT HR
        INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HR.ORG_ID
        WHERE HR.ID = :id AND HR.STATUS IN (" . CAREERS_OPEN_JOB_STATUSES . ")", [':id' => (string)$recId]);
    if (empty($job['ID'])) {
        throw new CareersException('This job is no longer open.', 404);
    }

    $completeness = careersCompleteness($candidate['ID']);
    if ($completeness['score'] < CAREERS_APPLY_MIN_SCORE) {
        throw new CareersException('Please complete your application form before applying (' . $completeness['score']
            . '% of ' . CAREERS_APPLY_MIN_SCORE . '% done).', 422);
    }

    // Lock the candidate row so a double click cannot apply twice.
    careersExecute("SELECT ID FROM HR_CANDIDATE_INFO WHERE ID = :id FOR UPDATE", [':id' => (string)$candidate['ID']],
        'Could not apply for the job.');
    $existing = careersRow("SELECT ID FROM HR_CAN_APPLICATION_DETAILS WHERE CAN_ID = :can AND REQ_ID = :req AND ROWNUM = 1",
        [':can' => (string)$candidate['ID'], ':req' => (string)$recId]);
    if (!empty($existing['ID'])) {
        throw new CareersException('You have already applied for this job.', 409);
    }

    careersExecute("INSERT INTO HR_CAN_APPLICATION_DETAILS (CAN_ID, REQ_ID, DESIG_ID, DEPT_ID, ORG_ID, APL_DATE, POOL_STATUS)
        VALUES (:can, :req, :desig, :dept, :org, SYSDATE, NULL)", [
        ':can' => (string)$candidate['ID'], ':req' => (string)$recId, ':desig' => (string)$job['DESI_ID'],
        ':dept' => (string)$job['DEPT_ID'], ':org' => (string)$job['ORG_ID'],
    ], 'Could not apply for the job.');
    // Latest application on the candidate (legacy swapped department and designation here).
    careersExecute("UPDATE HR_CANDIDATE_INFO SET REQ_ID = :req, DEPT_ID = :dept, DESIG_ID = :desig, APL_DATE = SYSDATE
        WHERE ID = :can", [
        ':req' => (string)$recId, ':dept' => (string)$job['DEPT_ID'], ':desig' => (string)$job['DESI_ID'],
        ':can' => (string)$candidate['ID'],
    ], 'Could not apply for the job.');

    return ['Successfully applied for this job.', ['recId' => $recId]];
}, 'careers/apply.php');
