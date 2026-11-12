<?php
/*
 * POST { section, action: 'save'|'add'|'delete', data, id } application form sections
 * (legacy careers/application_form_sv.php):
 *   save:   personal, general, ctc, why
 *   add / delete (own rows only): education, experience, family, social, language
 */
require_once __DIR__ . '/careersCommon.php';
require_once __DIR__ . '/profileOptions.php';

const CAREERS_LIST_TABLES = [
    // section => [table, CAN_ID is VARCHAR2]
    'education' => ['HR_CAN_EDUCATION', false],
    'experience' => ['HR_CAN_EXPERIENCE', false],
    'family' => ['HR_CAN_FAMILY_INFO', false],
    'social' => ['HR_CAN_SOCIALMEDIA', true],
    'language' => ['HR_CAN_LANGUAGE', true],
];

function careersPastDate($value, $label, $required = true)
{
    $value = careersIsoDate($value, $label, $required);
    if ($value !== '' && $value > date('Y-m-d')) {
        throw new CareersException("{$label} cannot be in the future.", 422);
    }
    return $value;
}

$input = careersInput();

careersRunAction(static function () use ($input) {
    $candidate = careersCandidate();
    $id = (string)$candidate['ID'];
    $section = trim((string)($input['section'] ?? ''));
    $action = trim((string)($input['action'] ?? 'save'));
    $d = is_array($input['data'] ?? null) ? $input['data'] : [];
    $options = careersProfileOptions();

    if ($action === 'delete') {
        if (!isset(CAREERS_LIST_TABLES[$section])) {
            throw new CareersException('Invalid section.', 422);
        }
        [$table, $textCanId] = CAREERS_LIST_TABLES[$section];
        $deleted = careersExecute("DELETE FROM {$table} WHERE ID = :row_id AND CAN_ID = " . ($textCanId ? 'TO_CHAR(:id)' : ':id'),
            [':row_id' => (string)(int)($input['id'] ?? 0), ':id' => $id], 'Could not delete the entry.');
        if ($deleted !== 1) {
            throw new CareersException('Entry not found.', 404);
        }
        return ['Entry deleted.', null];
    }

    switch ($section . ':' . $action) {
        case 'personal:save':
            $country = careersAssertOption($d['country'] ?? '', $options['countries'], 'country');
            $pincode = careersRequired($d['pincode'] ?? '', 'Pin code', 10);
            if (strcasecmp($country, 'India') === 0 && !preg_match('/^[0-9]{6}$/', $pincode)) {
                throw new CareersException('Pin code must be 6 digits.', 422);
            }
            $aadhar = preg_replace('/\s+/', '', (string)($d['aadhar'] ?? ''));
            if ($aadhar !== '' && !preg_match('/^[0-9]{12}$/', $aadhar)) {
                throw new CareersException('UID (Aadhaar) number must be 12 digits.', 422);
            }
            $dob = careersPastDate($d['dob'] ?? '', 'Date of birth');
            if ($dob > date('Y-m-d', strtotime('-14 years'))) {
                throw new CareersException('Please enter a valid date of birth.', 422);
            }
            $line1 = careersRequired($d['addressLine1'] ?? '', 'Address line 1', 100);
            $line2 = careersOptional($d['addressLine2'] ?? '', 'Address line 2', 300);
            careersExecute("UPDATE HR_CANDIDATE_INFO SET TITLE_ID = :title, CND_NAME = :name, AADHAR_NO = :aadhar,
                    DOB = TO_DATE(:dob, 'YYYY-MM-DD'), GEND_ID = :gender, CND_MARRI_STS = :marital,
                    CND_ADDR_LINE1 = :line1, CND_ADDR_LINE2 = :line2, CND_ADDRESS = :address, CND_LOCALITY = :locality,
                    CND_CITY = :city, CND_STATE = :state, CND_COUNTRY = :country, CND_PINCODE = :pincode,
                    CHG_ON = SYSDATE, CHG_BY = 'ONLINE'
                WHERE ID = :id", [
                ':title' => careersAssertOption($d['titleId'] ?? '', $options['titles'], 'title'),
                ':name' => careersRequired($d['name'] ?? '', 'Full name', 300),
                ':aadhar' => $aadhar,
                ':dob' => $dob,
                ':gender' => careersAssertOption($d['genderId'] ?? '', $options['genders'], 'gender'),
                ':marital' => careersAssertOption($d['maritalStatus'] ?? '', $options['maritalStatus'], 'marital status'),
                ':line1' => $line1,
                ':line2' => $line2,
                ':address' => substr(trim($line1 . ' ' . $line2), 0, 900),
                ':locality' => careersOptional($d['locality'] ?? '', 'Locality', 200),
                ':city' => careersRequired($d['city'] ?? '', 'City', 200),
                ':state' => careersAssertOption($d['state'] ?? '', $options['states'], 'state', strcasecmp($country, 'India') === 0),
                ':country' => $country,
                ':pincode' => $pincode,
                ':id' => $id,
            ], 'Could not save your personal information.');
            return ['Personal information saved.', null];

        case 'general:save':
            $answers = is_array($d['answers'] ?? null) ? $d['answers'] : [];
            $questions = careersRows("SELECT ID, QUS_TYPE FROM HR_CAN_QUESTION");
            careersExecute("DELETE FROM HR_CAN_QUESTION_DETAILS WHERE CAN_ID = TO_CHAR(:id)", [':id' => $id],
                'Could not save your answers.');
            foreach ($questions as $question) {
                $answer = $answers[$question['ID']] ?? [];
                $value = trim((string)($answer['answer'] ?? ''));
                if ($value === '') {
                    throw new CareersException('Please answer every question.', 422);
                }
                if ($question['QUS_TYPE'] === 'BINARY' && !in_array($value, ['YES', 'NO'], true)) {
                    throw new CareersException('Please answer Yes or No.', 422);
                }
                careersAssertLength($value, 500, 'Answer');
                careersExecute("INSERT INTO HR_CAN_QUESTION_DETAILS (CAN_ID, QUES_ID, ANS, DEPEN_ANS, CHG_ON, CHG_BY)
                    VALUES (TO_CHAR(:id), :ques, :ans, :dep, SYSDATE, 'ONLINE')", [
                    ':id' => $id, ':ques' => (string)$question['ID'], ':ans' => $value,
                    ':dep' => careersOptional($answer['followUpAnswer'] ?? '', 'Details', 500),
                ], 'Could not save your answers.');
            }
            return ['General information saved.', null];

        case 'ctc:save':
            $ctcNumber = static function ($value, $label) {
                $value = careersRequired($value, $label, 20);
                if (!preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value) || (float)$value > 1000) {
                    throw new CareersException("{$label} must be in lakhs per annum (for example 3.5).", 422);
                }
                return $value;
            };
            $notice = careersRequired($d['noticePeriod'] ?? '', 'Notice period', 3);
            if (!ctype_digit($notice)) {
                throw new CareersException('Notice period must be a number of days.', 422);
            }
            careersExecute("UPDATE HR_CANDIDATE_INFO SET CURR_CTC = :curr, EXPT_CTC = :expt, CND_NOTICE = :notice,
                    REQREF_ID = :ref, CUR_LOC = :cur_loc, PREF_LOC = :pref_loc, CHG_ON = SYSDATE, CHG_BY = 'ONLINE'
                WHERE ID = :id", [
                ':curr' => $ctcNumber($d['currentCtc'] ?? '', 'Current CTC'),
                ':expt' => $ctcNumber($d['expectedCtc'] ?? '', 'Expected CTC'),
                ':notice' => $notice,
                ':ref' => careersAssertOption($d['referenceId'] ?? '', $options['references'], 'source'),
                ':cur_loc' => careersRequired($d['currentLocation'] ?? '', 'Current location', 100),
                ':pref_loc' => careersRequired($d['preferredLocation'] ?? '', 'Preferred location', 100),
                ':id' => $id,
            ], 'Could not save your location and CTC.');
            return ['Preferred location and CTC saved.', null];

        case 'why:save':
            careersExecute("UPDATE HR_CANDIDATE_INFO SET WHY_1 = :why1, WHY_2 = :why2, CHG_ON = SYSDATE, CHG_BY = 'ONLINE'
                WHERE ID = :id", [
                ':why1' => careersRequired($d['whyJoin'] ?? '', 'Why you want to join us', 3000),
                ':why2' => careersOptional($d['whySelect'] ?? '', 'Why we should select you', 3000),
                ':id' => $id,
            ], 'Could not save your answer.');
            return ['Saved.', null];

        case 'education:add':
            careersExecute("INSERT INTO HR_CAN_EDUCATION (CAN_ID, COURSE, INST_NAME, PASSING_DATE, REMARKS, CHG_ON, CHG_BY)
                VALUES (:id, :course, :inst, TO_DATE(:passing, 'YYYY-MM-DD'), :remarks, SYSDATE, 'ONLINE')", [
                ':id' => $id,
                ':course' => careersAssertOption($d['course'] ?? '', $options['qualifications'], 'qualification'),
                ':inst' => careersRequired($d['institute'] ?? '', 'Institute / University', 200),
                ':passing' => careersPastDate($d['passingDate'] ?? '', 'Passing date'),
                ':remarks' => careersRequired($d['remarks'] ?? '', 'Percentage / Grade', 100),
            ], 'Could not add the qualification.');
            return ['Qualification added.', null];

        case 'experience:add':
            $current = !empty($d['current']);
            $from = careersPastDate($d['from'] ?? '', 'From date');
            $to = $current ? '' : careersPastDate($d['to'] ?? '', 'To date');
            if ($to !== '' && $to < $from) {
                throw new CareersException('To date cannot be before the from date.', 422);
            }
            $salary = careersRequired($d['grossSalary'] ?? '', 'Gross salary', 20);
            if (!preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $salary) || (float)$salary > 100) {
                throw new CareersException('Gross salary must be in lakhs per annum (up to 100).', 422);
            }
            $refEmail = trim((string)($d['referenceEmail'] ?? ''));
            if ($refEmail !== '' && (!filter_var($refEmail, FILTER_VALIDATE_EMAIL) || strlen($refEmail) > 50)) {
                throw new CareersException('Please enter a valid reference email.', 422);
            }
            $refPhone = trim((string)($d['referencePhone'] ?? ''));
            if ($refPhone !== '' && !preg_match('/^\+?[0-9]{10,14}$/', $refPhone)) {
                throw new CareersException('Please enter a valid reference phone number.', 422);
            }
            careersExecute("INSERT INTO HR_CAN_EXPERIENCE (CAN_ID, ORG_NAME, FROM_DATE, TO_DATE, DESIG, GROSS_SALARY,
                    DUTY_NATURE, LEAVE_REASON, ISFRESHER, CURRWORK, REF_REPORT_TO, REF_DESIGNATION, REF_PHONE, REF_YEARS,
                    REF_EMAIL, CHG_ON, CHG_BY)
                VALUES (:id, :org, TO_DATE(:from_d, 'YYYY-MM-DD'), TO_DATE(:to_d, 'YYYY-MM-DD'), :desig, :salary,
                    :duty, :reason, 'N', :current_work, :ref_name, :ref_desig, :ref_phone, :ref_years, :ref_email, SYSDATE, 'ONLINE')", [
                ':id' => $id,
                ':org' => careersRequired($d['organisation'] ?? '', 'Organisation', 100),
                ':from_d' => $from,
                ':to_d' => $to,
                ':desig' => careersRequired($d['designation'] ?? '', 'Designation', 100),
                ':salary' => $salary,
                ':duty' => careersRequired($d['dutyNature'] ?? '', 'Nature of duties', 700),
                ':reason' => $current ? careersOptional($d['leavingReason'] ?? '', 'Reason for leaving', 1000)
                    : careersRequired($d['leavingReason'] ?? '', 'Reason for leaving', 1000),
                ':current_work' => $current ? 'Y' : 'N',
                ':ref_name' => careersOptional($d['referenceName'] ?? '', 'Reference name', 100),
                ':ref_desig' => careersOptional($d['referenceDesignation'] ?? '', 'Reference designation', 100),
                ':ref_phone' => $refPhone,
                ':ref_years' => careersOptional($d['referenceYears'] ?? '', 'Years known', 20),
                ':ref_email' => $refEmail,
            ], 'Could not add the experience.');
            return ['Experience added.', null];

        case 'family:add':
            $age = trim((string)($d['age'] ?? ''));
            if ($age !== '' && (!ctype_digit($age) || (int)$age > 120)) {
                throw new CareersException('Please enter a valid age.', 422);
            }
            careersExecute("INSERT INTO HR_CAN_FAMILY_INFO (CAN_ID, FM_NAME, FM_RELATION, FM_DEP, FM_OCCUPATION, AGE, FM_CONTACT,
                    CHG_ON, CHG_BY)
                VALUES (:id, :name, :relation, :dep, :occupation, :age, :contact, SYSDATE, 'ONLINE')", [
                ':id' => $id,
                ':name' => careersRequired($d['name'] ?? '', 'Name', 100),
                ':relation' => careersAssertOption($d['relation'] ?? '', $options['relations'], 'relation'),
                ':dep' => careersAssertOption($d['dependent'] ?? '', $options['dependent'], 'dependency'),
                ':occupation' => careersOptional($d['occupation'] ?? '', 'Occupation', 100),
                ':age' => $age,
                ':contact' => careersOptional($d['contact'] ?? '', 'Emergency contact', 100),
            ], 'Could not add the family member.');
            return ['Family member added.', null];

        case 'social:add':
            careersExecute("INSERT INTO HR_CAN_SOCIALMEDIA (CAN_ID, SOCIAL_MED, SOCIAL_DET, CHG_ON, CHG_BY)
                VALUES (TO_CHAR(:id), :medium, :detail, SYSDATE, 'ONLINE')", [
                ':id' => $id,
                ':medium' => careersAssertOption($d['medium'] ?? '', $options['socialMedia'], 'social media'),
                ':detail' => careersRequired($d['detail'] ?? '', 'Profile / number', 200),
            ], 'Could not add the social media entry.');
            return ['Social media added.', null];

        case 'language:add':
            $language = careersAssertOption($d['language'] ?? '', $options['languages'], 'language');
            if (empty($d['read']) && empty($d['write']) && empty($d['speak'])) {
                throw new CareersException('Please tick read, write or speak.', 422);
            }
            $exists = careersRow("SELECT ID FROM HR_CAN_LANGUAGE WHERE CAN_ID = TO_CHAR(:id) AND LANGUAGE = :lang AND ROWNUM = 1",
                [':id' => $id, ':lang' => $language]);
            if (!empty($exists['ID'])) {
                throw new CareersException('This language is already added.', 409);
            }
            careersExecute("INSERT INTO HR_CAN_LANGUAGE (CAN_ID, LANGUAGE, READ, WRITE, SPEAK, CHG_ON, CHG_BY)
                VALUES (TO_CHAR(:id), :lang, :r, :w, :s, SYSDATE, TO_CHAR(:id))", [
                ':id' => $id, ':lang' => $language,
                ':r' => !empty($d['read']) ? 'Y' : 'N', ':w' => !empty($d['write']) ? 'Y' : 'N', ':s' => !empty($d['speak']) ? 'Y' : 'N',
            ], 'Could not add the language.');
            return ['Language added.', null];
    }

    throw new CareersException('Invalid section.', 422);
}, 'careers/saveProfile.php');
