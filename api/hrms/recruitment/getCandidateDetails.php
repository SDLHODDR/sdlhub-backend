<?php
/* GET ?id= -> header and tabs of the Candidate Details modal. Legacy candidate_info_sv.php?CanData. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, RECRUITMENT_ALL_PAGES);
    session_write_close();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new RecruitmentRequestException('A valid candidate is required.', 422);
    }
    $bind = [':id' => (string)$id];

    $candidate = recruitmentRow("SELECT A.ID, A.CND_NAME, A.CND_FNAME, A.CND_MNAME, A.CND_LNAME, A.CND_EMAIL, A.CND_MOBILE,
            A.CND_CITY, A.CND_NOTICE, A.CURR_CTC, A.EXPT_CTC, A.IMAGE_PATH, A.DOC_PATH,
            GET_REQREFNM(A.REQREF_ID) AS REFERENCE_NAME, GET_EMPTITLE(A.TITLE_ID) AS TITLE,
            (SELECT NVL(SUM(ABS(NVL(E.TO_DATE, SYSDATE) - E.FROM_DATE)), 0) FROM HR_CAN_EXPERIENCE E WHERE E.CAN_ID = A.ID) AS EXP_DAYS
        FROM HR_CANDIDATE_INFO A WHERE A.ID = :id", $bind);
    if (empty($candidate['ID'])) {
        throw new RecruitmentRequestException('Candidate not found.', 404);
    }

    $name = trim(preg_replace('/\s+/', ' ', ($candidate['CND_FNAME'] ?? '') . ' ' . ($candidate['CND_MNAME'] ?? '') . ' ' . ($candidate['CND_LNAME'] ?? '')));
    $days = (float)$candidate['EXP_DAYS'];
    $years = (int)floor($days / 365);
    $months = (int)floor(($days - $years * 365) / 30);

    $education = multiRec("SELECT COURSE, INST_NAME, TO_CHAR(PASSING_DATE, 'DD-Mon-YYYY') AS PASSING_DATE, REMARKS
        FROM HR_CAN_EDUCATION WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $experience = multiRec("SELECT ORG_NAME, TO_CHAR(FROM_DATE, 'DD-Mon-YYYY') AS FROM_DATE, TO_CHAR(TO_DATE, 'DD-Mon-YYYY') AS TO_DATE,
            DESIG, DUTY_NATURE, LEAVE_REASON, GROSS_SALARY, REF_REPORT_TO, REF_DESIGNATION, REF_YEARS, REF_PHONE
        FROM HR_CAN_EXPERIENCE WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $languages = multiRec("SELECT LANGUAGE, READ, WRITE, SPEAK FROM HR_CAN_LANGUAGE WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $family = multiRec("SELECT FM_NAME, FM_RELATION, FM_DEP, FM_OCCUPATION, AGE FROM HR_CAN_FAMILY_INFO WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $social = multiRec("SELECT SOCIAL_MED, SOCIAL_DET FROM HR_CAN_SOCIALMEDIA WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $applications = multiRec("SELECT ID, REQ_ID, GET_DESIGN_NAME(DESIG_ID) AS DESIGNATION, GET_DEPT_NAME(DEPT_ID) AS DEPARTMENT
        FROM HR_CAN_APPLICATION_DETAILS WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $logs = multiRec("SELECT L.CAN_APP_DET_ID, L.STATUS, TO_CHAR(L.CHG_ON, 'DD-Mon-YYYY HH24:MI') AS CHG_ON,
            TRIM(REGEXP_REPLACE(E.FNAME || ' ' || E.MNAME || ' ' || E.LNAME, ' +', ' ')) AS CHG_BY_NAME, L.CHG_BY
        FROM HR_CAN_APP_DET_LOG L
        LEFT JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = L.CHG_BY
        WHERE L.CAN_ID = :id ORDER BY L.CHG_ON, L.ID", $bind) ?: [];

    $timeline = array_map(static fn($application) => [
        'reqId' => $application['REQ_ID'],
        'department' => $application['DEPARTMENT'] ?? '',
        'designation' => $application['DESIGNATION'] ?? '',
        'events' => array_values(array_map(static fn($log) => [
            'status' => $log['STATUS'] ?? '',
            'on' => $log['CHG_ON'] ?? '',
            'by' => ($log['CHG_BY_NAME'] ?? '') ?: ($log['CHG_BY'] ?? ''),
        ], array_filter($logs, static fn($log) => $log['CAN_APP_DET_ID'] === $application['ID']))),
    ], $applications);

    return ['Candidate fetched successfully.', [
        'candidate' => [
            'id' => $candidate['ID'],
            'title' => $candidate['TITLE'] ?? '',
            'name' => $name !== '' ? $name : ($candidate['CND_NAME'] ?? ''),
            'email' => $candidate['CND_EMAIL'] ?? '',
            'mobile' => $candidate['CND_MOBILE'] ?? '',
            'city' => $candidate['CND_CITY'] ?? '',
            'imagePath' => $candidate['IMAGE_PATH'] ?? '',
            'experience' => "{$years} Years {$months} Months",
            'reference' => $candidate['REFERENCE_NAME'] ?? '',
            'noticeDays' => $candidate['CND_NOTICE'] ?? '',
            'lastCtc' => recruitmentToLakhs($candidate['CURR_CTC'] ?? ''),
            'expectedCtc' => recruitmentToLakhs($candidate['EXPT_CTC'] ?? ''),
        ],
        'education' => array_map(static fn($row) => [
            'course' => $row['COURSE'] ?? '', 'institute' => $row['INST_NAME'] ?? '',
            'passingDate' => $row['PASSING_DATE'] ?? '', 'remarks' => $row['REMARKS'] ?? '',
        ], $education),
        'experience' => array_map(static fn($row) => [
            'organization' => $row['ORG_NAME'] ?? '', 'from' => $row['FROM_DATE'] ?? '', 'to' => $row['TO_DATE'] ?? '',
            'designation' => $row['DESIG'] ?? '', 'duty' => $row['DUTY_NATURE'] ?? '',
            'reason' => $row['LEAVE_REASON'] ?? '', 'salary' => $row['GROSS_SALARY'] ?? '',
        ], $experience),
        'references' => array_map(static fn($row) => [
            'name' => $row['REF_REPORT_TO'] ?? '', 'position' => $row['REF_DESIGNATION'] ?? '',
            'years' => $row['REF_YEARS'] ?? '', 'phone' => $row['REF_PHONE'] ?? '',
        ], $experience),
        'languages' => array_map(static fn($row) => [
            'language' => $row['LANGUAGE'] ?? '', 'read' => ($row['READ'] ?? '') === 'Y',
            'write' => ($row['WRITE'] ?? '') === 'Y', 'speak' => ($row['SPEAK'] ?? '') === 'Y',
        ], $languages),
        'family' => array_map(static fn($row) => [
            'name' => $row['FM_NAME'] ?? '', 'relation' => $row['FM_RELATION'] ?? '', 'dependent' => $row['FM_DEP'] ?? '',
            'occupation' => $row['FM_OCCUPATION'] ?? '', 'age' => $row['AGE'] ?? '',
        ], $family),
        'social' => array_map(static fn($row) => [
            'media' => $row['SOCIAL_MED'] ?? '', 'detail' => $row['SOCIAL_DET'] ?? '',
        ], $social),
        'timeline' => $timeline,
    ]];
}, 'getCandidateDetails.php');
