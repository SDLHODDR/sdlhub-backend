<?php
/*
 * GET ?user_task_id= -> everything the Employee Information tabs of a joining
 * detail task show: saved rows per tab, and the options that depend on them.
 */
require_once __DIR__ . '/joiningDetailCommon.php';

try {
    $task = joiningLoadDetailTask($_GET['user_task_id'] ?? 0, $empCode);
    $code = $task['TRAN_CODE'];
    $binds = [':emp_code' => $code];

    $basic = joiningRecord("SELECT B.ID, B.ADHAR_NO, B.PAN_NO, B.DRIV_LICE_NO, B.PASSPORT_NO, B.ESI_NO,
            B.NATIONALITY, B.CITIZEN_NO, B.M_STATUS, B.MOTHER_LANG, B.RELIGION, B.PF_NO, B.FPF_NO,
            B.PF_NOMINEE, B.UAN_NO, B.MEMBER_ID, B.RETIRE_AGE, TO_CHAR(B.DOA, 'YYYY-MM-DD') AS DOA
        FROM HR_EMP_BASIC_INFO B WHERE B.EMP_CODE = :emp_code AND ROWNUM = 1", $binds);
    $profile = joiningRecord("SELECT TO_CHAR(ADD_MONTHS(DOJ, 60), 'DD-Mon-YYYY') AS GRATUITY_DATE, GENDER, COUNTRY
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", $binds);

    $data = [
        'basic' => (object)$basic,
        'gratuityDate' => $profile['GRATUITY_DATE'] ?? '',
        'gender' => $profile['GENDER'] ?? '',
        'ifscLength' => in_array(strtoupper($profile['COUNTRY'] ?? ''), ['NP', 'NEP'], true) ? 3 : 11,
        'tenure' => joiningRecords("SELECT T.ID, T.ETYPE_ID, M.EMP_TYPE AS ETYPE_NAME, T.ETYPE_PERIOD,
                TO_CHAR(T.EFF_FROM, 'YYYY-MM-DD') AS EFF_FROM, TO_CHAR(T.EFF_TO, 'YYYY-MM-DD') AS EFF_TO
            FROM HR_EMP_TENURE T INNER JOIN HR_EMP_TYPE M ON M.ETYPE_ID = T.ETYPE_ID
            WHERE T.EMP_CODE = :emp_code ORDER BY T.ID DESC", $binds),
        'bank' => joiningRecords("SELECT ID, BANK_NAME, BANK_BRANCH, BANK_ACNO, BANK_IFSC, BANK_NOMINEE, STATUS
            FROM HR_EMP_BANK_DET WHERE EMP_CODE = :emp_code ORDER BY ID DESC", $binds),
        'qualification' => joiningRecords("SELECT ID, INST_NAME, COURSE, PERGRADE, REMARKS,
                TO_CHAR(FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE, TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DATE
            FROM HR_EMP_EDUCATION WHERE EMP_CODE = :emp_code ORDER BY ID DESC", $binds),
        'experience' => joiningRecords("SELECT ID, ORG_NAME, DESIG, GROSS_SALARY, DUTY_NATURE, LEAVE_REASON,
                TO_CHAR(FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE, TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DATE
            FROM HR_EMP_EXPERIENCE WHERE EMP_CODE = :emp_code ORDER BY ID DESC", $binds),
        'family' => joiningRecords("SELECT ID, FM_NAME, FM_RELATION, FM_DEP, FM_OCCUPATION, AADHAAR, FM_CONTACT,
                TO_CHAR(DOB, 'YYYY-MM-DD') AS FM_DOB
            FROM HR_EMP_FAMILY_INFO WHERE EMP_CODE = :emp_code ORDER BY ID DESC", $binds),
        'kra' => joiningRecords("SELECT K.ID, K.KRA_ID, M.KRA_DESC, K.RESP_PERC,
                TO_CHAR(K.EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM, TO_CHAR(K.EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO
            FROM HR_EMP_KRA K INNER JOIN HR_KRA_MASTER M ON M.KRA_ID = K.KRA_ID
            WHERE K.EMP_CODE = :emp_code ORDER BY K.ID DESC", $binds),
        'kraOptions' => joiningRecords("SELECT KRA_ID AS VALUE, KRA_DESC AS LABEL FROM HR_KRA_MASTER ORDER BY KRA_DESC"),
        'apps' => joiningRecords("SELECT ID AS VALUE, APP AS LABEL FROM EPPLIVE.BCS_APP ORDER BY APP"),
        'appAccess' => array_column(joiningRecords("SELECT APP_ID FROM HR_BCS_APP_ACCESS WHERE EMP_CODE = :emp_code", $binds), 'APP_ID'),
    ];

    // Salary data is limited to whoever is working on the CTC task.
    if ($task['SECTION'] === 'ctc') {
        $heads = joiningCtcHeads($code);
        $saved = joiningSavedCtc($code);
        $ctc = '';
        foreach ($saved as $row) {
            if ((string)$row['CTC'] !== '') { $ctc = $row['CTC']; break; }
        }
        if ($ctc === '') {
            $offer = singRec("SELECT MAX(B.CTC_OFFER) AS CTC FROM HR_CAN_APPLICATION_DETAILS A
                INNER JOIN HR_OFFERS B ON A.REQ_ID = B.REQ_ID AND A.CAN_ID = B.CAN_ID
                WHERE A.EMP_CODE = :emp_code", $binds);
            $ctc = $offer['CTC'] ?? '';
        }
        $data['ctc'] = [
            'heads' => $heads,
            'saved' => (object)$saved,
            'ctc' => $ctc,
            'hasSaved' => !empty($saved),
        ];
    }

    apiResponse(true, 'Joining details fetched successfully.', $data, 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getJoiningDetails.php');
    apiResponse(false, 'Unable to load the joining details.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
