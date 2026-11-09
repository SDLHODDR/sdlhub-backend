<?php
/* GET ?id= -> the Employment Application Form. Legacy careers/emp_form.php. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, RECRUITMENT_ALL_PAGES);
    session_write_close();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new RecruitmentRequestException('A valid candidate is required.', 422);
    }
    $bind = [':id' => (string)$id];

    $c = recruitmentRow("SELECT A.ID, A.CND_NAME, A.CND_ADDR_LINE1, A.CND_ADDR_LINE2, A.CND_ADDR_LINE3, A.CND_CITY, A.CND_STATE,
            A.CND_MOBILE, A.CND_EMAIL, A.GEND_ID, A.CND_MARRI_STS, TO_CHAR(A.DOB, 'DD-Mon-YYYY') AS DOB,
            TRUNC(MONTHS_BETWEEN(SYSDATE, A.DOB) / 12) AS AGE, A.CND_COUNTRY, A.IMAGE_PATH, A.WHY_1, A.WHY_2,
            GET_CANDIDATE_LANGUAGE(A.ID, 'S') AS LANG_SPEAK, GET_CANDIDATE_LANGUAGE(A.ID, 'R') AS LANG_READ,
            GET_CANDIDATE_LANGUAGE(A.ID, 'W') AS LANG_WRITE
        FROM HR_CANDIDATE_INFO A WHERE A.ID = :id", $bind);
    if (empty($c['ID'])) {
        throw new RecruitmentRequestException('Candidate not found.', 404);
    }

    // Latest application decides the position and the company on the form.
    $application = recruitmentRow("SELECT * FROM (
            SELECT GET_DESIGN_NAME(A.DESIG_ID) AS APPLIED_FOR, O.FINENT
            FROM HR_CAN_APPLICATION_DETAILS A
            LEFT JOIN HR_ORGANOGRAM O ON O.ID = A.ORG_ID
            WHERE A.CAN_ID = :id ORDER BY A.ID DESC)
        WHERE ROWNUM = 1", $bind);
    $companies = [
        'SDL' => 'Shree Dhootapapeshwar Limited',
        'SHL' => 'Solumiks Herbaceutical Limited',
        'OPL' => 'Om PharmaCeuticals Limited',
    ];

    $family = multiRec("SELECT LOWER(TRIM(FM_RELATION)) AS RELATION, FM_NAME, FM_OCCUPATION
        FROM HR_CAN_FAMILY_INFO WHERE CAN_ID = :id ORDER BY ID", $bind) ?: [];
    $relative = static function ($relations) use ($family) {
        foreach ($family as $row) {
            if (in_array($row['RELATION'], $relations, true)) {
                return $row;
            }
        }
        return [];
    };
    $spouseApplies = ($c['GEND_ID'] ?? '') === '2' && strtolower((string)($c['CND_MARRI_STS'] ?? '')) === 'yes';
    $guardian = $spouseApplies ? $relative(['spouse', 'husband']) : $relative(['father']);

    $questions = multiRec("SELECT Q.QUESTION, Q.DEPENDENT, Q.DEPEN_DESC, D.ANS, D.DEPEN_ANS
        FROM HR_CAN_QUESTION_DETAILS D
        INNER JOIN HR_CAN_QUESTION Q ON Q.ID = D.QUES_ID
        WHERE D.CAN_ID = :id ORDER BY Q.ID", $bind) ?: [];
    $education = multiRec("SELECT INST_NAME, TO_CHAR(PASSING_DATE, 'Mon-YYYY') AS PASSING, COURSE, REMARKS
        FROM HR_CAN_EDUCATION WHERE CAN_ID = :id ORDER BY PASSING_DATE DESC", $bind) ?: [];
    $experience = multiRec("SELECT ORG_NAME, TO_CHAR(FROM_DATE, 'DD-Mon-YYYY') AS FROM_DATE, TO_CHAR(TO_DATE, 'DD-Mon-YYYY') AS TO_DATE,
            DESIG, GROSS_SALARY, DUTY_NATURE, LEAVE_REASON, REF_REPORT_TO, REF_DESIGNATION, REF_PHONE, REF_EMAIL, REF_YEARS
        FROM HR_CAN_EXPERIENCE WHERE CAN_ID = :id ORDER BY FROM_DATE DESC", $bind) ?: [];

    $answer = static function ($row) {
        $main = ucfirst(strtolower((string)($row['ANS'] ?? '')));
        if (($row['DEPENDENT'] ?? '') === 'YES' && ($row['ANS'] ?? '') === 'YES') {
            $detail = (string)($row['DEPEN_ANS'] ?? '');
            return $main . ', ' . ($row['DEPEN_DESC'] ?? '') . ' ' . ($detail !== '' ? ucfirst(strtolower($detail)) : 'Not Provided');
        }
        return $main;
    };
    $address = trim(preg_replace('/\s+/', ' ', ($c['CND_ADDR_LINE1'] ?? '') . ' ' . ($c['CND_ADDR_LINE2'] ?? '') . ' ' . ($c['CND_ADDR_LINE3'] ?? '')));
    $married = strtolower((string)($c['CND_MARRI_STS'] ?? ''));

    return ['Application form fetched successfully.', [
        'company' => $companies[$application['FINENT'] ?? ''] ?? '',
        'appliedFor' => $application['APPLIED_FOR'] ?? '',
        'name' => strtoupper((string)($c['CND_NAME'] ?? '')),
        'guardianName' => strtoupper((string)($guardian['FM_NAME'] ?? '')),
        'address' => implode(', ', array_filter([$address, $c['CND_CITY'] ?? ''])),
        'state' => $c['CND_STATE'] ?? '',
        'mobile' => $c['CND_MOBILE'] ?? '',
        'email' => $c['CND_EMAIL'] ?? '',
        'gender' => ['1' => 'Male', '2' => 'Female'][$c['GEND_ID'] ?? ''] ?? '',
        'maritalStatus' => ['yes' => 'Married', 'no' => 'Unmarried'][$married] ?? '',
        'dob' => $c['DOB'] ?? '',
        'age' => $c['AGE'] ?? '',
        'nationality' => $c['CND_COUNTRY'] ?? '',
        'imagePath' => $c['IMAGE_PATH'] ?? '',
        'fatherOccupation' => $relative(['father'])['FM_OCCUPATION'] ?? '',
        'motherOccupation' => $relative(['mother'])['FM_OCCUPATION'] ?? '',
        'languagesSpeak' => $c['LANG_SPEAK'] ?? '',
        'languagesRead' => $c['LANG_READ'] ?? '',
        'languagesWrite' => $c['LANG_WRITE'] ?? '',
        'suitability' => $c['WHY_1'] ?? '',
        'otherInformation' => $c['WHY_2'] ?? '',
        'questions' => array_map(static fn($row) => ['question' => $row['QUESTION'] ?? '', 'answer' => $answer($row)], $questions),
        'education' => array_map(static fn($row) => [
            'institute' => $row['INST_NAME'] ?? '', 'passing' => $row['PASSING'] ?? '',
            'course' => $row['COURSE'] ?? '', 'grade' => $row['REMARKS'] ?? '',
        ], $education),
        'experience' => array_map(static fn($row) => [
            'organization' => $row['ORG_NAME'] ?? '', 'from' => $row['FROM_DATE'] ?? '', 'to' => $row['TO_DATE'] ?? '',
            'designation' => $row['DESIG'] ?? '', 'salary' => $row['GROSS_SALARY'] ?? '',
            'duty' => $row['DUTY_NATURE'] ?? '', 'reason' => $row['LEAVE_REASON'] ?? '',
        ], $experience),
        'references' => array_map(static fn($row) => [
            'name' => $row['REF_REPORT_TO'] ?? '', 'position' => $row['REF_DESIGNATION'] ?? '',
            'phone' => $row['REF_PHONE'] ?? '', 'email' => $row['REF_EMAIL'] ?? '', 'years' => $row['REF_YEARS'] ?? '',
        ], $experience),
    ]];
}, 'getCandidateForm.php');
