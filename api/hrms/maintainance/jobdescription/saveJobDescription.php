<?php

ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

function sqlValue($value)
{
    if ($value === null || $value === '') {
        return "NULL";
    }

    return "'" . addslashes((string)$value) . "'";
}

try {
    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    }

    if (!$conn) {
        apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    $input = readJsonInput();
    $input = array_merge($input, $_POST);

    $jobId = trim($input['id'] ?? $input['ID'] ?? '');
    $shdesc = trim($input['shdesc'] ?? $input['SH_DESC'] ?? '');
    $descr = trim($input['desc'] ?? $input['DESCR'] ?? '');
    $deptId = trim($input['deptid'] ?? $input['DEPT_ID'] ?? '');
    $desigId = trim($input['desigid'] ?? $input['DESIG_ID'] ?? '');
    $lvlId = trim($input['lvlid'] ?? $input['LVL_ID'] ?? '');
    $exp = trim($input['exp'] ?? $input['EXP'] ?? '');
    $minExp = trim($input['minexp'] ?? $input['MIN_EXP'] ?? '');
    $maxExp = trim($input['maxexp'] ?? $input['MAX_EXP'] ?? '');
    $minAge = trim($input['minage'] ?? $input['MIN_AGE'] ?? '');
    $maxAge = trim($input['maxage'] ?? $input['MAX_AGE'] ?? '');
    $minQual = trim($input['minqual'] ?? $input['MIN_QUAL'] ?? '');
    $maxQual = trim($input['maxqual'] ?? $input['MAX_QUAL'] ?? '');
    $ageRange = trim($input['Age_Range'] ?? $input['AGE_RANGE'] ?? '');
    $minSal = trim($input['minsal'] ?? $input['MIN_SAL'] ?? '');
    $maxSal = trim($input['maxsal'] ?? $input['MAX_SAL'] ?? '');
    $repJdId = trim($input['rep_jdid'] ?? $input['REPT_JDID'] ?? '');
    $locId = trim($input['locid'] ?? $input['LOC_ID'] ?? '');
    $status = trim($input['status'] ?? $input['STATUS'] ?? 'A');

    /*
=========================================================
CHILD TAB DATA
=========================================================
*/

$responsibilities = trim($input['responsibilities'] ?? '');

// $kraData = json_decode(
//     $input['kra'] ?? '[]',
//     true
// );

// $educationData = json_decode(
//     $input['education'] ?? '{}',
//     true
// );

// $skillsData = json_decode(
//     $input['skills'] ?? '[]',
//     true
// );

// $allowancesData = json_decode(
//     $input['allowances'] ?? '[]',
//     true
// );

// $ctcHeadsData = json_decode(
//     $input['ctc_heads'] ?? '[]',
//     true
// );

// $questionTemplateData = json_decode(
//     $input['question_template'] ?? '[]',
//     true
// );

// $deptReferencesData = json_decode(
//     $input['dept_references'] ?? '[]',
//     true
// );

// $divisionMappingData = json_decode(
//     $input['division_mapping'] ?? '[]',
//     true
// );

// $inductionData = json_decode(
//     $input['induction'] ?? '{}',
//     true
// );

function parseJsonOrArray($value, $default = [])
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : $default;
}

$kraData = parseJsonOrArray(
    $input['kra'] ?? '[]',
    []
);

$educationData = parseJsonOrArray(
    $input['education'] ?? '{}',
    []
);

$skillsData = parseJsonOrArray(
    $input['skills'] ?? '[]',
    []
);

$allowancesData = parseJsonOrArray(
    $input['allowances'] ?? '[]',
    []
);

$ctcHeadsData = parseJsonOrArray(
    $input['ctc_heads'] ?? '[]',
    []
);

$questionTemplateData = parseJsonOrArray(
    $input['question_template'] ?? '[]',
    []
);

$deptReferencesData = parseJsonOrArray(
    $input['dept_references'] ?? '[]',
    []
);

$divisionMappingData = parseJsonOrArray(
    $input['division_mapping'] ?? '[]',
    []
);

$inductionData = parseJsonOrArray(
    $input['induction'] ?? '{}',
    []
);

/*
Make sure invalid JSON doesn't become NULL.
*/

if (!is_array($kraData)) {
    $kraData = [];
}

if (!is_array($educationData)) {
    $educationData = [];
}

if (!is_array($skillsData)) {
    $skillsData = [];
}

if (!is_array($allowancesData)) {
    $allowancesData = [];
}

if (!is_array($ctcHeadsData)) {
    $ctcHeadsData = [];
}

if (!is_array($questionTemplateData)) {
    $questionTemplateData = [];
}

if (!is_array($deptReferencesData)) {
    $deptReferencesData = [];
}

if (!is_array($divisionMappingData)) {
    $divisionMappingData = [];
}

if (!is_array($inductionData)) {
    $inductionData = [];
}

    if ($shdesc === '') {
        apiResponse(false, "JD Label is required.", null, 400);
    }
    if ($deptId === '') {
        apiResponse(false, "Department is required.", null, 400);
    }
    if ($desigId === '') {
        apiResponse(false, "Designation is required.", null, 400);
    }
    if ($lvlId === '') {
        apiResponse(false, "Band/Level is required.", null, 400);
    }

    startQry();

    $loginId = $_SESSION['loginId'] ?? $_SESSION['emp_code'] ?? 'SYSTEM';

    if ($jobId !== '') {
        $sql = "UPDATE HR_JD SET
                    SH_DESC='" . addslashes($shdesc) . "',
                    DESCR='" . addslashes($descr) . "',
                    DEPT_ID='" . addslashes($deptId) . "',
                    LVL_ID='" . addslashes($lvlId) . "',
                    DESIG_ID='" . addslashes($desigId) . "',
                    MIN_SAL='" . addslashes($minSal) . "',
                    MAX_SAL='" . addslashes($maxSal) . "',
                    MIN_EXP='" . addslashes($minExp) . "',
                    MAX_EXP='" . addslashes($maxExp) . "',
                    MIN_AGE='" . addslashes($minAge) . "',
                    MAX_AGE='" . addslashes($maxAge) . "',
                    MIN_QUALI='" . addslashes($minQual) . "',
                    MAX_QUALI='" . addslashes($maxQual) . "',
                    REPT_JDID='" . addslashes($repJdId) . "',
                    AGE_RANGE='" . addslashes($ageRange) . "',
                    EXP='" . addslashes($exp) . "',
                    STATUS='" . addslashes($status) . "',
                    CHG_ON=SYSDATE,
                    CHG_BY='" . addslashes($loginId) . "'
                WHERE ID='" . addslashes($jobId) . "'";

        $ok = executeQry($sql);

        // if ($ok) {
        //     endQry('Updated');
        //     apiResponse(true, "Job description updated successfully.", ['id' => $jobId], 200);
        // }

        // endQry();
        // apiResponse(false, "Unable to update job description.", null, 500);

        if (!$ok) {
    endQry();
    apiResponse(
        false,
        "Unable to update job description.",
        null,
        500
    );
}


/*
=========================================================
DELETE OLD CHILD DATA
=========================================================
*/

$deleteTables = [
    "HR_JD_KRA",
    "HR_JD_EDU_DET",
    "HR_JD_CAPABILITIES",
    "HR_JD_ALLOWANCES",
    "HR_JD_CTC_HEADS",
    "HR_JD_QUESTIONS",
    "HR_JD_REF_DEPT",
    "HR_JD_DIVSN",
    "HR_JD_INDUCTION"
];

foreach ($deleteTables as $table) {

    $deleteSql = "
        DELETE FROM {$table}
        WHERE JD_ID = '" . addslashes($jobId) . "'
    ";

    executeQry($deleteSql);
}


/*
=========================================================
INSERT CHILD DATA
=========================================================
*/

$loginIdSql = sqlValue($loginId);


/*
-----------------------------
KRA
-----------------------------
*/

foreach ($kraData as $row) {

    // $kraId = $row['KRA_ID']
    //     ?? $row['kra_id']
    //     ?? $row['value']
    //     ?? '';

    // $respPerc = $row['RESP_PERC']
    //     ?? $row['resp_perc']
    //     ?? $row['RESP_PERC']
    //     ?? '';

    // MultiSelect sends only KRA IDs:
    // ["1127", "1128"]

    // Keep backward compatibility if an object is sent.
    if (is_array($row)) {
        $kraId = $row['KRA_ID']
            ?? $row['kra_id']
            ?? $row['value']
            ?? '';

        $respPerc = $row['RESP_PERC']
            ?? $row['resp_perc']
            ?? '';
    } else {
        $kraId = $row;
        $respPerc = '';
    }

    if ($kraId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_KRA"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_KRA
        (
            ID,
            JD_ID,
            KRA_ID,
            RESP_PERC,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($kraId) . "',
            " . sqlValue($respPerc) . ",
            {$loginIdSql},
            SYSDATE
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
EDUCATION
-----------------------------
*/

$qualificationId =
    $educationData['QUA_ID']
    ?? $educationData['qualification']
    ?? $educationData['QUALIFICATION']
    ?? '';

$comments =
    $educationData['COMMENTS']
    ?? $educationData['comments']
    ?? '';

if ($qualificationId !== '') {

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_EDU_DET"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_EDU_DET
        (
            ID,
            JD_ID,
            QUA_ID,
            COMMENTS,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($qualificationId) . "',
            " . sqlValue($comments) . ",
            {$loginIdSql},
            SYSDATE
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
SKILLS
-----------------------------
*/

foreach ($skillsData as $row) {

    $capaId =
        $row['CAPA_ID']
        ?? $row['capa_id']
        ?? $row['code']
        ?? '';

    $capaLevelId =
        $row['CAPALVL_ID']
        ?? $row['capalvl_id']
        ?? $row['level']
        ?? '';

    if ($capaId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_CAPABILITIES"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_CAPABILITIES
        (
            ID,
            JD_ID,
            CAPA_ID,
            CAPALVL_ID,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($capaId) . "',
            " . sqlValue($capaLevelId) . ",
            SYSDATE,
            {$loginIdSql}
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
ALLOWANCES
-----------------------------
*/

foreach ($allowancesData as $row) {

    $allowId =
        $row['ALLOW_ID']
        ?? $row['allow_id']
        ?? $row['listing']
        ?? '';

    $amount =
        $row['ALLOW_AMOUNT']
        ?? $row['allowAmount']
        ?? '';

    $addInfo =
        $row['ADD_INFO']
        ?? $row['appliedLocation']
        ?? '';

    $fromDate =
        $row['FROMDT']
        ?? $row['from']
        ?? '';

    $toDate =
        $row['TODT']
        ?? $row['to']
        ?? '';

    $expType =
        $row['EXP_TYPE']
        ?? $row['frequency']
        ?? '';

    if ($allowId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_ALLOWANCES"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_ALLOWANCES
        (
            ID,
            JD_ID,
            ALLOW_ID,
            ALLOW_AMOUNT,
            ADD_INFO,
            FROMDT,
            TODT,
            EXP_TYPE,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($allowId) . "',
            " . sqlValue($amount) . ",
            " . sqlValue($addInfo) . ",
            " . (
                $fromDate !== ''
                    ? "TO_DATE('" . addslashes($fromDate) . "','YYYY-MM-DD')"
                    : "NULL"
            ) . ",
            " . (
                $toDate !== ''
                    ? "TO_DATE('" . addslashes($toDate) . "','YYYY-MM-DD')"
                    : "NULL"
            ) . ",
            " . sqlValue($expType) . ",
            SYSDATE,
            {$loginIdSql}
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
CTC HEADS
-----------------------------
*/

foreach ($ctcHeadsData as $row) {

    $adId =
        $row['AD_ID']
        ?? $row['ad_id']
        ?? $row['head']
        ?? '';

    $adCode =
        $row['AD_CODE']
        ?? $row['ad_code']
        ?? '';

    $key =
        $row['KEY']
        ?? $row['key']
        ?? '';

    $tempVal =
        $row['TEMPVAL']
        ?? $row['tempval']
        ?? $row['formula']
        ?? '';

    $val =
        $row['VAL']
        ?? $row['value']
        ?? '';

    $effFrom =
        $row['EFFEC_FROM']
        ?? $row['from']
        ?? '';

    $effTo =
        $row['EFFEC_TO']
        ?? $row['to']
        ?? '';

    if ($adId === '' && $adCode === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_CTC_HEADS"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_CTC_HEADS
        (
            ID,
            JD_ID,
            AD_ID,
            AD_CODE,
            CHG_ON,
            CHG_BY,
            EFFEC_FROM,
            EFFEC_TO,
            KEY,
            TEMPVAL,
            VAL
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            " . sqlValue($adId) . ",
            " . sqlValue($adCode) . ",
            SYSDATE,
            {$loginIdSql},
            " . (
                $effFrom !== ''
                    ? "TO_DATE('" . addslashes($effFrom) . "','YYYY-MM-DD')"
                    : "NULL"
            ) . ",
            " . (
                $effTo !== ''
                    ? "TO_DATE('" . addslashes($effTo) . "','YYYY-MM-DD')"
                    : "NULL"
            ) . ",
            " . sqlValue($key) . ",
            " . sqlValue($tempVal) . ",
            " . sqlValue($val) . "
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
QUESTION TEMPLATE
-----------------------------
*/

foreach ($questionTemplateData as $row) {

    $qgrpId =
        $row['QGRP_ID']
        ?? $row['qgrp_id']
        ?? '';

    $qgrpType =
        $row['QGRP_TYPE']
        ?? $row['qgrp_type']
        ?? '';

    $qsgrpId =
        $row['QSGRP_ID']
        ?? $row['qsgrp_id']
        ?? '';

    $questionId =
        $row['QUESTION_ID']
        ?? $row['question_id']
        ?? $row['value']
        ?? '';

    $dispSeq =
        $row['DISP_SEQ']
        ?? $row['disp_seq']
        ?? '';

    if ($questionId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_QUESTIONS"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_QUESTIONS
        (
            ID,
            JD_ID,
            QGRP_ID,
            QGRP_TYPE,
            QSGRP_ID,
            QUESTION_ID,
            DISP_SEQ,
            CHG_BY,
            CHG_ON,
            EFF_FROM,
            EFF_TO
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            " . sqlValue($qgrpId) . ",
            " . sqlValue($qgrpType) . ",
            " . sqlValue($qsgrpId) . ",
            '" . addslashes($questionId) . "',
            " . sqlValue($dispSeq) . ",
            {$loginIdSql},
            SYSDATE,
            SYSDATE,
            NULL
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
DEPARTMENT REFERENCE
-----------------------------
*/

foreach ($deptReferencesData as $row) {

    $deptId =
        $row['DEPT_ID']
        ?? $row['dept_id']
                ?? $row['deptId']
        ?? $row['value']
        ?? '';

    if ($deptId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_REF_DEPT"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_REF_DEPT
        (
            ID,
            JD_ID,
            DEPT_ID,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($deptId) . "',
            {$loginIdSql},
            SYSDATE
        )
    ";

    if (!$ok) {
        endQry();

        apiResponse(
            false,
            "Unable to insert department reference.",
            [
                'sql' => $sql,
                'dept_id' => $deptId,
                'jd_id' => $jobId
            ],
            500
        );
    }

    executeQry($sql);
}


/*
-----------------------------
DIVISION MAPPING
-----------------------------
*/

foreach ($divisionMappingData as $row) {

    // $divisionId =
    //     $row['DIVSN_ID']
    //     ?? $row['divsn_id']
    //     ?? $row['value']
    //     ?? '';

    $divisionId = is_array($row)
    ? ($row['DIVSN_ID'] ?? $row['divsn_id'] ?? $row['value'] ?? '')
    : trim($row);

    if ($divisionId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_DIVSN"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_DIVSN
        (
            ID,
            JD_ID,
            DIVSN_ID,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($divisionId) . "',
            SYSDATE,
            {$loginIdSql}
        )
    ";

    executeQry($sql);
}


/*
-----------------------------
INDUCTION
-----------------------------
*/

$inducId =
    $inductionData['INDUC_ID']
    ?? $inductionData['induc_id']
    ?? '';

$orgId =
    $inductionData['ORG_ID']
    ?? $inductionData['org_id']
    ?? '';

$orgLocId =
    $inductionData['ORG_LOC_ID']
    ?? $inductionData['org_loc_id']
    ?? '';

$dispSeq =
    $inductionData['DISP_SEQ']
    ?? $inductionData['disp_seq']
    ?? '';

if ($inducId !== '') {

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_INDUCTION"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_INDUCTION
        (
            ID,
            JD_ID,
            INDUC_ID,
            ORG_ID,
            ORG_LOC_ID,
            DISP_SEQ,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($jobId) . "',
            '" . addslashes($inducId) . "',
            " . sqlValue($orgId) . ",
            " . sqlValue($orgLocId) . ",
            " . sqlValue($dispSeq) . ",
            {$loginIdSql},
            SYSDATE
        )
    ";

    executeQry($sql);
}


endQry('Updated');

apiResponse(
    true,
    "Job description updated successfully.",
    ['id' => $jobId],
    200
);

exit;
    }

    $last = singRec("SELECT MAX(ID) AS ID FROM HR_JD");
    $newId = '1';
    if (!empty($last['ID'])) {
        $newId = (string)(intval($last['ID']) + 1);
    }

    if ($locId === '') {
        $locId = '1';
    }

    $sql = "INSERT INTO HR_JD (ID, SH_DESC, DESCR, DEPT_ID, LVL_ID, DESIG_ID, STATUS, MIN_SAL, MAX_SAL,MIN_EXP,
 MAX_EXP,
 MIN_AGE,
 MAX_AGE,
 MIN_QUALI,
 MAX_QUALI, CHG_BY, CHG_ON, REPT_JDID, AGE_RANGE, EXP)
            VALUES ('" . addslashes($newId) . "',
                    '" . addslashes($shdesc) . "',
                    '" . addslashes($descr) . "',
                    '" . addslashes($deptId) . "',
                    '" . addslashes($lvlId) . "',
                    '" . addslashes($desigId) . "',
                    '" . addslashes($status) . "',
                    '" . addslashes($minSal) . "',
                    '" . addslashes($maxSal) . "',
                    '" . addslashes($minExp) . "',
                    '" . addslashes($maxExp) . "',
                    '" . addslashes($minAge) . "',
                    '" . addslashes($maxAge) . "',
                    '" . addslashes($minQual) . "',
                    '" . addslashes($maxQual) . "',
                    '" . addslashes($loginId) . "',
                    SYSDATE,
                    '" . addslashes($repJdId) . "',
                    '" . addslashes($ageRange) . "',
                    '" . addslashes($exp) . "')";

    $ok = executeQry($sql);

    // if ($ok) {
    //     endQry('Inserted');
    //     apiResponse(true, "Job description inserted successfully.", ['id' => $newId], 201);
    // }
    // endQry();
    // apiResponse(false, "Unable to save job description.", null, 500);
    if (!$ok) {

    endQry();

    apiResponse(
        false,
        "Unable to save job description.",
        null,
        500
    );
}


/*
=========================================================
INSERT CHILD DATA FOR NEW JD
=========================================================
*/

/*
KRA
*/
foreach ($kraData as $row) {

    // $kraId =
    //     $row['KRA_ID']
    //     ?? $row['kra_id']
    //     ?? $row['value']
    //     ?? '';

    // $respPerc =
    //     $row['RESP_PERC']
    //     ?? $row['resp_perc']
    //     ?? '';

    // MultiSelect sends only KRA IDs:
    // ["1127", "1128"]

    // Keep backward compatibility if an object is sent.
    if (is_array($row)) {
        $kraId =
            $row['KRA_ID']
            ?? $row['kra_id']
            ?? $row['value']
            ?? '';

        $respPerc =
            $row['RESP_PERC']
            ?? $row['resp_perc']
            ?? '';
    } else {
        $kraId = $row;
        $respPerc = '';
    }

    if ($kraId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_KRA"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_KRA
        (
            ID,
            JD_ID,
            KRA_ID,
            RESP_PERC,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($kraId) . "',
            " . sqlValue($respPerc) . ",
            " . sqlValue($loginId) . ",
            SYSDATE
        )
    ";

    executeQry($sql);
}


/*
EDUCATION
*/
$qualificationId =
    $educationData['QUA_ID']
    ?? $educationData['qualification']
    ?? '';

$comments =
    $educationData['COMMENTS']
    ?? $educationData['comments']
    ?? '';

if ($qualificationId !== '') {

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_EDU_DET"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_EDU_DET
        (
            ID,
            JD_ID,
            QUA_ID,
            COMMENTS,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($qualificationId) . "',
            " . sqlValue($comments) . ",
            " . sqlValue($loginId) . ",
            SYSDATE
        )
    ";

    executeQry($sql);
}


/*
SKILLS
*/
foreach ($skillsData as $row) {

    $capaId =
        $row['CAPA_ID']
        ?? $row['capa_id']
        ?? $row['code']
        ?? '';

    $capaLevelId =
        $row['CAPALVL_ID']
        ?? $row['capalvl_id']
        ?? $row['level']
        ?? '';

    if ($capaId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_CAPABILITIES"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_CAPABILITIES
        (
            ID,
            JD_ID,
            CAPA_ID,
            CAPALVL_ID,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($capaId) . "',
            " . sqlValue($capaLevelId) . ",
            SYSDATE,
            " . sqlValue($loginId) . "
        )
    ";

    executeQry($sql);
}


/*
ALLOWANCES
*/
foreach ($allowancesData as $row) {

    $allowId =
        $row['ALLOW_ID']
        ?? $row['allow_id']
        ?? $row['listing']
        ?? '';

    $amount =
        $row['ALLOW_AMOUNT']
        ?? $row['allowAmount']
        ?? '';

    $addInfo =
        $row['ADD_INFO']
        ?? $row['appliedLocation']
        ?? '';

    $expType =
        $row['EXP_TYPE']
        ?? $row['frequency']
        ?? '';

    if ($allowId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_ALLOWANCES"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_ALLOWANCES
        (
            ID,
            JD_ID,
            ALLOW_ID,
            ALLOW_AMOUNT,
            ADD_INFO,
            EXP_TYPE,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($allowId) . "',
            " . sqlValue($amount) . ",
            " . sqlValue($addInfo) . ",
            " . sqlValue($expType) . ",
            SYSDATE,
            " . sqlValue($loginId) . "
        )
    ";

    executeQry($sql);
}


/*
CTC HEADS
*/
foreach ($ctcHeadsData as $row) {

    $adId =
        $row['AD_ID']
        ?? $row['ad_id']
        ?? $row['head']
        ?? '';

    $adCode =
        $row['AD_CODE']
        ?? $row['ad_code']
        ?? '';

    $key =
        $row['KEY']
        ?? $row['key']
        ?? '';

    $tempVal =
        $row['TEMPVAL']
        ?? $row['tempval']
        ?? $row['formula']
        ?? '';

    $val =
        $row['VAL']
        ?? $row['value']
        ?? '';

    if ($adId === '' && $adCode === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_CTC_HEADS"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_CTC_HEADS
        (
            ID,
            JD_ID,
            AD_ID,
            AD_CODE,
            CHG_ON,
            CHG_BY,
            KEY,
            TEMPVAL,
            VAL
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            " . sqlValue($adId) . ",
            " . sqlValue($adCode) . ",
            SYSDATE,
            " . sqlValue($loginId) . ",
            " . sqlValue($key) . ",
            " . sqlValue($tempVal) . ",
            " . sqlValue($val) . "
        )
    ";

    executeQry($sql);
}


/*
QUESTION TEMPLATE
*/
foreach ($questionTemplateData as $row) {

    $qgrpId =
        $row['QGRP_ID']
        ?? $row['qgrp_id']
        ?? '';

    $qgrpType =
        $row['QGRP_TYPE']
        ?? $row['qgrp_type']
        ?? '';

    $qsgrpId =
        $row['QSGRP_ID']
        ?? $row['qsgrp_id']
        ?? '';

    $questionId =
        $row['QUESTION_ID']
        ?? $row['question_id']
        ?? $row['value']
        ?? '';

    $dispSeq =
        $row['DISP_SEQ']
        ?? $row['disp_seq']
        ?? '';

    if ($questionId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_QUESTIONS"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_QUESTIONS
        (
            ID,
            JD_ID,
            QGRP_ID,
            QGRP_TYPE,
            QSGRP_ID,
            QUESTION_ID,
            DISP_SEQ,
            CHG_BY,
            CHG_ON,
            EFF_FROM,
            EFF_TO
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            " . sqlValue($qgrpId) . ",
            " . sqlValue($qgrpType) . ",
            " . sqlValue($qsgrpId) . ",
            '" . addslashes($questionId) . "',
            " . sqlValue($dispSeq) . ",
            " . sqlValue($loginId) . ",
            SYSDATE,
            SYSDATE,
            NULL
        )
    ";

    executeQry($sql);
}


/*
DEPARTMENT REFERENCE
*/
foreach ($deptReferencesData as $row) {

    $deptId =
        $row['DEPT_ID']
        ?? $row['dept_id']
        ?? $row['value']
        ?? '';

    if ($deptId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_REF_DEPT"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_REF_DEPT
        (
            ID,
            JD_ID,
            DEPT_ID,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($deptId) . "',
            " . sqlValue($loginId) . ",
            SYSDATE
        )
    ";

    executeQry($sql);
}


/*
DIVISION MAPPING
*/
foreach ($divisionMappingData as $row) {

    $divisionId =
        $row['DIVSN_ID']
        ?? $row['divsn_id']
        ?? $row['value']
        ?? '';

    if ($divisionId === '') {
        continue;
    }

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_DIVSN"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_DIVSN
        (
            ID,
            JD_ID,
            DIVSN_ID,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($divisionId) . "',
            SYSDATE,
            " . sqlValue($loginId) . "
        )
    ";

    executeQry($sql);
}


/*
INDUCTION
*/
$inducId =
    $inductionData['INDUC_ID']
    ?? $inductionData['induc_id']
    ?? '';

$orgId =
    $inductionData['ORG_ID']
    ?? $inductionData['org_id']
    ?? '';

$orgLocId =
    $inductionData['ORG_LOC_ID']
    ?? $inductionData['org_loc_id']
    ?? '';

$dispSeq =
    $inductionData['DISP_SEQ']
    ?? $inductionData['disp_seq']
    ?? '';

if ($inducId !== '') {

    $last = singRec(
        "SELECT NVL(MAX(ID),0)+1 AS ID FROM HR_JD_INDUCTION"
    );

    $childId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_INDUCTION
        (
            ID,
            JD_ID,
            INDUC_ID,
            ORG_ID,
            ORG_LOC_ID,
            DISP_SEQ,
            CHG_BY,
            CHG_ON
        )
        VALUES
        (
            '" . addslashes($childId) . "',
            '" . addslashes($newId) . "',
            '" . addslashes($inducId) . "',
            " . sqlValue($orgId) . ",
            " . sqlValue($orgLocId) . ",
            " . sqlValue($dispSeq) . ",
            " . sqlValue($loginId) . ",
            SYSDATE
        )
    ";

    executeQry($sql);
}


endQry('Inserted');

apiResponse(
    true,
    "Job description inserted successfully.",
    ['id' => $newId],
    201
);

exit;

// } catch (Throwable $e) {
//     logOracleError($e);
//     apiResponse(false, "Unable to process request.", null, 500);
// }

} catch (Throwable $e) {
    error_log(
        "saveJobDescription.php ERROR: " .
        $e->getMessage() .
        " | File: " .
        $e->getFile() .
        " | Line: " .
        $e->getLine()
    );

    apiResponse(
        false,
        "Unable to process request: " . $e->getMessage(),
        null,
        500
    );
}
