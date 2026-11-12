<?php
/*
 * GET the logged-in candidate's application form (legacy careers/application_form.php):
 * every section, the dropdown options, profile completeness and applied jobs.
 */
require_once __DIR__ . '/careersCommon.php';
require_once __DIR__ . '/profileOptions.php';

careersRunRead(static function () {
    $candidate = careersCandidate();
    $id = (string)$candidate['ID'];
    $bind = [':id' => $id];

    $c = careersRow("SELECT ID, TITLE_ID, CND_NAME, CND_MOBILE, CND_EMAIL, AADHAR_NO, TO_CHAR(DOB, 'YYYY-MM-DD') AS DOB,
            GEND_ID, CND_MARRI_STS, CND_ADDR_LINE1, CND_ADDR_LINE2, CND_LOCALITY, CND_CITY, CND_STATE, CND_COUNTRY,
            CND_PINCODE, CURR_CTC, EXPT_CTC, CND_NOTICE, REQREF_ID, CUR_LOC, PREF_LOC, WHY_1, WHY_2, DOC_PATH, IMAGE_PATH
        FROM HR_CANDIDATE_INFO WHERE ID = :id", $bind);

    $text = static fn($value) => careersText($value ?? '');

    $questions = careersRows("SELECT Q.ID, Q.QUESTION, Q.QUS_TYPE, Q.DEPEN_QUES, D.ANS, D.DEPEN_ANS
        FROM HR_CAN_QUESTION Q
        LEFT JOIN HR_CAN_QUESTION_DETAILS D ON D.QUES_ID = Q.ID AND D.CAN_ID = TO_CHAR(:id)
        ORDER BY Q.ID", $bind);

    return ['Application form fetched.', [
        'personal' => [
            'titleId' => (string)($c['TITLE_ID'] ?? ''),
            'name' => $text($c['CND_NAME']),
            'mobile' => (string)($c['CND_MOBILE'] ?? ''),
            'email' => (string)($c['CND_EMAIL'] ?? ''),
            'aadhar' => (string)($c['AADHAR_NO'] ?? ''),
            'dob' => (string)($c['DOB'] ?? ''),
            'genderId' => (string)($c['GEND_ID'] ?? ''),
            'maritalStatus' => (string)($c['CND_MARRI_STS'] ?? ''),
            'addressLine1' => $text($c['CND_ADDR_LINE1']),
            'addressLine2' => $text($c['CND_ADDR_LINE2']),
            'locality' => $text($c['CND_LOCALITY']),
            'city' => $text($c['CND_CITY']),
            'state' => (string)($c['CND_STATE'] ?? ''),
            'country' => (string)($c['CND_COUNTRY'] ?? ''),
            'pincode' => (string)($c['CND_PINCODE'] ?? ''),
        ],
        'education' => array_map(static fn($r) => [
            'id' => $r['ID'], 'course' => $text($r['COURSE']), 'institute' => $text($r['INST_NAME']),
            'passingDate' => $r['PASSING'] ?? '', 'remarks' => $text($r['REMARKS']),
        ], careersRows("SELECT ID, COURSE, INST_NAME, TO_CHAR(PASSING_DATE, 'DD-Mon-YYYY') AS PASSING, REMARKS
            FROM HR_CAN_EDUCATION WHERE CAN_ID = :id ORDER BY PASSING_DATE DESC NULLS LAST, ID", $bind)),
        'experience' => array_map(static fn($r) => [
            'id' => $r['ID'], 'organisation' => $text($r['ORG_NAME']), 'designation' => $text($r['DESIG']),
            'from' => $r['FROM_D'] ?? '', 'to' => $r['TO_D'] ?? '', 'current' => ($r['CURRWORK'] ?? '') === 'Y',
            'grossSalary' => $text($r['GROSS_SALARY']), 'dutyNature' => $text($r['DUTY_NATURE']),
            'leavingReason' => $text($r['LEAVE_REASON']), 'referenceName' => $text($r['REF_REPORT_TO']),
            'referenceDesignation' => $text($r['REF_DESIGNATION']), 'referencePhone' => $text($r['REF_PHONE']),
            'referenceYears' => $text($r['REF_YEARS']), 'referenceEmail' => $text($r['REF_EMAIL']),
        ], careersRows("SELECT ID, ORG_NAME, DESIG, TO_CHAR(FROM_DATE, 'DD-Mon-YYYY') AS FROM_D,
                TO_CHAR(TO_DATE, 'DD-Mon-YYYY') AS TO_D, CURRWORK, GROSS_SALARY, DUTY_NATURE, LEAVE_REASON,
                REF_REPORT_TO, REF_DESIGNATION, REF_PHONE, REF_YEARS, REF_EMAIL
            FROM HR_CAN_EXPERIENCE WHERE CAN_ID = :id ORDER BY FROM_DATE DESC NULLS LAST, ID", $bind)),
        'general' => array_map(static fn($r) => [
            'id' => $r['ID'], 'question' => $text($r['QUESTION']), 'type' => ($r['QUS_TYPE'] ?? '') === 'BINARY' ? 'yesno' : 'text',
            'followUp' => $text($r['DEPEN_QUES']), 'answer' => $text($r['ANS']), 'followUpAnswer' => $text($r['DEPEN_ANS']),
        ], $questions),
        'ctc' => [
            'currentCtc' => $text($c['CURR_CTC']), 'expectedCtc' => $text($c['EXPT_CTC']),
            'noticePeriod' => $text($c['CND_NOTICE']), 'referenceId' => (string)($c['REQREF_ID'] ?? ''),
            'currentLocation' => $text($c['CUR_LOC']), 'preferredLocation' => $text($c['PREF_LOC']),
        ],
        'documents' => [
            'hasCv' => trim((string)($c['DOC_PATH'] ?? '')) !== '',
            'cvName' => basename((string)($c['DOC_PATH'] ?? '')),
            'hasPhoto' => trim((string)($c['IMAGE_PATH'] ?? '')) !== '',
        ],
        'family' => array_map(static fn($r) => [
            'id' => $r['ID'], 'name' => $text($r['FM_NAME']), 'relation' => $text($r['FM_RELATION']),
            'dependent' => $text($r['FM_DEP']), 'occupation' => $text($r['FM_OCCUPATION']), 'age' => $r['AGE'] ?? '',
            'contact' => $text($r['FM_CONTACT']),
        ], careersRows("SELECT ID, FM_NAME, FM_RELATION, FM_DEP, FM_OCCUPATION, AGE, FM_CONTACT
            FROM HR_CAN_FAMILY_INFO WHERE CAN_ID = :id ORDER BY ID", $bind)),
        'social' => array_map(static fn($r) => [
            'id' => $r['ID'], 'medium' => $text($r['SOCIAL_MED']), 'detail' => $text($r['SOCIAL_DET']),
        ], careersRows("SELECT ID, SOCIAL_MED, SOCIAL_DET FROM HR_CAN_SOCIALMEDIA WHERE CAN_ID = TO_CHAR(:id) ORDER BY ID", $bind)),
        'why' => ['whyJoin' => $text($c['WHY_1']), 'whySelect' => $text($c['WHY_2'])],
        'languages' => array_map(static fn($r) => [
            'id' => $r['ID'], 'language' => $text($r['LANGUAGE']), 'read' => trim((string)$r['READ']) === 'Y',
            'write' => trim((string)$r['WRITE']) === 'Y', 'speak' => trim((string)$r['SPEAK']) === 'Y',
        ], careersRows("SELECT ID, LANGUAGE, READ, WRITE, SPEAK FROM HR_CAN_LANGUAGE WHERE CAN_ID = TO_CHAR(:id) ORDER BY ID", $bind)),
        'applied' => array_map(static fn($r) => [
            'recId' => $r['REQ_ID'], 'designation' => $text($r['DESIG']), 'department' => $text($r['DEPT']),
            'location' => $text($r['GEO_DESC']), 'appliedOn' => $r['APL'] ?? '',
        ], careersRows("SELECT A.REQ_ID, GET_DESIGN_NAME(A.DESIG_ID) AS DESIG, GET_DEPT_NAME(A.DEPT_ID) AS DEPT,
                HOL.GEO_DESC, TO_CHAR(A.APL_DATE, 'DD-Mon-YYYY') AS APL
            FROM HR_CAN_APPLICATION_DETAILS A
            LEFT JOIN HR_RECRUITMENT HR ON HR.ID = A.REQ_ID
            LEFT JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
            WHERE A.CAN_ID = :id ORDER BY A.APL_DATE DESC NULLS LAST, A.ID DESC", $bind)),
        'completeness' => careersCompleteness($id),
        'options' => careersProfileOptions(),
    ]];
}, 'careers/profile.php');
