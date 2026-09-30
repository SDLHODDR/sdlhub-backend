<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

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

    /*
=========================================================
RESPONSIBILITY ACTIONS
=========================================================
*/

$action = trim($input['action'] ?? '');

if ($action === 'save_responsibility') {

    $jdId = trim($input['jd_id'] ?? '');
    $responsibilityId = trim($input['responsibility_id'] ?? '');
    $description = trim($input['description'] ?? '');

    if ($jdId === '') {
        apiResponse(false, "Job description ID is required.", null, 400);
    }

    if ($description === '') {
        apiResponse(false, "Responsibility is required.", null, 400);
    }

    startQry();

    $loginId = $_SESSION['loginId'] ?? $_SESSION['emp_code'] ?? 'SYSTEM';
    $loginIdSql = sqlValue($loginId);

    if ($responsibilityId !== '') {

        /*
        EDIT EXISTING RESPONSIBILITY
        */

        $sql = "
            UPDATE HR_JD_DESCRIPTION
            SET
                DESCR = " . sqlValue($description) . ",
                CHG_ON = SYSDATE,
                CHG_BY = " . sqlValue($loginId) . "
            WHERE ID = '" . addslashes($responsibilityId) . "'
              AND JD_ID = '" . addslashes($jdId) . "'
        ";

        $ok = executeQry($sql);

        if (!$ok) {
            endQry();

            apiResponse(
                false,
                "Unable to update responsibility.",
                null,
                500
            );
        }

        endQry();

        apiResponse(
            true,
            "Responsibility updated successfully.",
            [
                'id' => $responsibilityId
            ],
            200
        );
    }

    /*
    ADD NEW RESPONSIBILITY
    */

    $last = singRec("
        SELECT NVL(MAX(ID), 0) + 1 AS ID
        FROM HR_JD_DESCRIPTION
    ");

    $newId = $last['ID'];

    $sql = "
        INSERT INTO HR_JD_DESCRIPTION
        (
            ID,
            JD_ID,
            DESCR,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            '" . addslashes($newId) . "',
            '" . addslashes($jdId) . "',
            " . sqlValue($description) . ",
            SYSDATE,
            " . sqlValue($loginId) . "
        )
    ";

    $ok = executeQry($sql);

    if (!$ok) {
        endQry();

        apiResponse(
            false,
            "Unable to save responsibility.",
            null,
            500
        );
    }

    endQry();

    apiResponse(
        true,
        "Responsibility saved successfully.",
        [
            'id' => $newId
        ],
        201
    );
}


if ($action === 'delete_responsibility') {

    $jdId = trim($input['jd_id'] ?? '');
    $responsibilityId = trim($input['responsibility_id'] ?? '');

    if ($jdId === '' || $responsibilityId === '') {
        apiResponse(
            false,
            "Job description ID and responsibility ID are required.",
            null,
            400
        );
    }

    startQry();

    $sql = "
        DELETE FROM HR_JD_DESCRIPTION
        WHERE ID = '" . addslashes($responsibilityId) . "'
          AND JD_ID = '" . addslashes($jdId) . "'
    ";

    $ok = executeQry($sql);

    if (!$ok) {
        endQry();

        apiResponse(
            false,
            "Unable to delete responsibility.",
            null,
            500
        );
    }

    endQry();

    apiResponse(
        true,
        "Responsibility deleted successfully.",
        null,
        200
    );
}

    $jobId = trim($input['id'] ?? $input['ID'] ?? '');
    $tab = strtolower(trim($input['tab'] ?? ''));
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

/*
=========================================================
TAB-SPECIFIC SAVE FOR EXISTING JOB DESCRIPTION
=========================================================
*/

if ($jobId !== '' && $tab !== '' && $tab !== 'basic') {

    $loginId = $_SESSION['loginId'] ?? $_SESSION['emp_code'] ?? 'SYSTEM';

    startQry();

    /*
     * =====================================================
     * KRA
     * =====================================================
     */
    if ($tab === 'kra') {

        $kraData = parseJsonOrArray(
            $input['kra'] ?? '[]',
            []
        );

        if (!is_array($kraData)) {
            $kraData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_KRA
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($kraData as $row) {

            if (is_array($row)) {
                $kraId =
                    $row['KRA_ID'] ??
                    $row['kra_id'] ??
                    $row['value'] ??
                    '';

                $respPerc =
                    $row['RESP_PERC'] ??
                    $row['resp_perc'] ??
                    '';
            } else {
                $kraId = $row;
                $respPerc = '';
            }

            if ($kraId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_KRA"
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
                    " . sqlValue($loginId) . ",
                    SYSDATE
                )
            ";

            executeQry($sql);
        }
    }

    /*
     * =====================================================
     * EDUCATION
     * =====================================================
     */
    elseif ($tab === 'education') {

        $educationData = parseJsonOrArray(
            $input['education'] ?? '[]',
            []
        );

        if (!is_array($educationData)) {
            $educationData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_EDU_DET
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($educationData as $row) {

            if (!is_array($row)) {
                continue;
            }

            $qualificationId =
                $row['QUA_ID'] ??
                $row['qualification'] ??
                $row['QUALIFICATION'] ??
                '';

            $comments =
                $row['COMMENTS'] ??
                $row['comments'] ??
                '';

            if ($qualificationId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_EDU_DET"
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
                    " . sqlValue($loginId) . ",
                    SYSDATE
                )
            ";

            executeQry($sql);
        }
    }

    /*
     * =====================================================
     * SKILLS
     * =====================================================
     */
    elseif ($tab === 'skills') {

        $skillsData = parseJsonOrArray(
            $input['skills'] ?? '[]',
            []
        );

        if (!is_array($skillsData)) {
            $skillsData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_CAPABILITIES
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($skillsData as $row) {

            if (!is_array($row)) {
                continue;
            }

            $capaId =
                $row['CAPA_ID'] ??
                $row['capa_id'] ??
                $row['code'] ??
                '';

            $capaLevelId =
                $row['CAPALVL_ID'] ??
                $row['capalvl_id'] ??
                $row['level'] ??
                '';

            if ($capaId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_CAPABILITIES"
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
                    " . sqlValue($loginId) . "
                )
            ";

            executeQry($sql);
        }
    }

    /*
     * =====================================================
     * ALLOWANCES
     * =====================================================
     */
    elseif ($tab === 'allowances') {

        $allowancesData = parseJsonOrArray(
            $input['allowances'] ?? '[]',
            []
        );

        if (!is_array($allowancesData)) {
            $allowancesData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_ALLOWANCES
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($allowancesData as $row) {

            if (!is_array($row)) {
                continue;
            }

            $allowId =
                $row['ALLOW_ID'] ??
                $row['allow_id'] ??
                $row['listing'] ??
                '';

            $amount =
                $row['ALLOW_AMOUNT'] ??
                $row['allowAmount'] ??
                '';

            $addInfo =
                $row['ADD_INFO'] ??
                $row['add_info'] ??
                $row['frequency'] ??
                '';

            $expType =
                $row['EXP_TYPE'] ??
                $row['exp_type'] ??
                $row['expenseType'] ??
                '';

            if ($allowId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_ALLOWANCES"
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
                    '" . addslashes($jobId) . "',
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
    }

    /*
     * =====================================================
     * CTC HEADS
     * =====================================================
     */
    elseif ($tab === 'ctc') {

        $ctcHeadsData = parseJsonOrArray(
            $input['ctc_heads'] ?? '[]',
            []
        );

        if (!is_array($ctcHeadsData)) {
            $ctcHeadsData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_CTC_HEADS
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($ctcHeadsData as $row) {

            if (!is_array($row)) {
                continue;
            }

            $adId =
                $row['AD_ID'] ??
                $row['ad_id'] ??
                $row['head'] ??
                '';

            $adCode =
                $row['AD_CODE'] ??
                $row['ad_code'] ??
                '';

            $key =
                $row['KEY'] ??
                $row['key'] ??
                '';

            $tempVal =
                $row['TEMPVAL'] ??
                $row['tempval'] ??
                $row['formula'] ??
                '';

            $val =
                $row['VAL'] ??
                $row['value'] ??
                '';

            if ($adId === '' && $adCode === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_CTC_HEADS"
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
                    '" . addslashes($jobId) . "',
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
    }

    /*
     * =====================================================
     * QUESTION TEMPLATE
     * =====================================================
     */
    elseif ($tab === 'questions') {

        $questionTemplateData = parseJsonOrArray(
            $input['question_template'] ?? '[]',
            []
        );

        if (!is_array($questionTemplateData)) {
            $questionTemplateData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_QUESTIONS
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($questionTemplateData as $row) {

            if (!is_array($row)) {
                continue;
            }

            $qgrpId =
                $row['QGRP_ID'] ??
                $row['qgrp_id'] ??
                '';

            $qgrpType =
                $row['QGRP_TYPE'] ??
                $row['qgrp_type'] ??
                '';

            $qsgrpId =
                $row['QSGRP_ID'] ??
                $row['qsgrp_id'] ??
                '';

            $questionId =
                $row['QUESTION_ID'] ??
                $row['question_id'] ??
                $row['value'] ??
                '';

            $dispSeq =
                $row['DISP_SEQ'] ??
                $row['disp_seq'] ??
                '';

            if ($questionId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_QUESTIONS"
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
                    " . sqlValue($loginId) . ",
                    SYSDATE,
                    SYSDATE,
                    NULL
                )
            ";

            executeQry($sql);
        }
    }

    /*
     * =====================================================
     * DEPARTMENT REFERENCE
     * =====================================================
     */
    elseif ($tab === 'deptref') {

        $deptReferencesData = parseJsonOrArray(
            $input['dept_references'] ?? '[]',
            []
        );

        if (!is_array($deptReferencesData)) {
            $deptReferencesData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_REF_DEPT
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($deptReferencesData as $row) {

            if (!is_array($row)) {
                continue;
            }

            $deptId =
                $row['DEPT_ID'] ??
                $row['dept_id'] ??
                $row['value'] ??
                $row['deptId'] ??
                '';

            if ($deptId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_REF_DEPT"
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
                    " . sqlValue($loginId) . ",
                    SYSDATE
                )
            ";

            executeQry($sql);
        }
    }

    /*
     * =====================================================
     * DIVISION MAPPING
     * =====================================================
     */
    elseif ($tab === 'division') {

        $divisionMappingData = parseJsonOrArray(
            $input['division_mapping'] ?? '[]',
            []
        );

        if (!is_array($divisionMappingData)) {
            $divisionMappingData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_DIVSN
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        foreach ($divisionMappingData as $row) {

            $divisionId = '';

            if (is_array($row)) {
                $divisionId =
                    $row['DIVSN_ID'] ??
                    $row['divsn_id'] ??
                    $row['value'] ??
                    '';
            } else {
                $divisionId = $row;
            }

            if ($divisionId === '') {
                continue;
            }

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_DIVSN"
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
                    " . sqlValue($loginId) . "
                )
            ";

            executeQry($sql);
        }
    }

    /*
     * =====================================================
     * INDUCTION
     * =====================================================
     */
    elseif ($tab === 'induction') {

        $inductionData = parseJsonOrArray(
            $input['induction'] ?? '{}',
            []
        );

        if (!is_array($inductionData)) {
            $inductionData = [];
        }

        executeQry(
            "DELETE FROM HR_JD_INDUCTION
             WHERE JD_ID='" . addslashes($jobId) . "'"
        );

        $inducId =
            $inductionData['INDUC_ID'] ??
            $inductionData['induc_id'] ??
            '';

        $orgId =
            $inductionData['ORG_ID'] ??
            $inductionData['org_id'] ??
            '';

        $orgLocId =
            $inductionData['ORG_LOC_ID'] ??
            $inductionData['org_loc_id'] ??
            '';

        $dispSeq =
            $inductionData['DISP_SEQ'] ??
            $inductionData['disp_seq'] ??
            '';

        if ($inducId !== '') {

            $last = singRec(
                "SELECT NVL(MAX(ID),0)+1 AS ID
                 FROM HR_JD_INDUCTION"
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
                    " . sqlValue($loginId) . ",
                    SYSDATE
                )
            ";

            executeQry($sql);
        }
    }

    else {
        endQry();

        apiResponse(
            false,
            "Invalid Job Description tab.",
            null,
            400
        );

        exit;
    }

    endQry('Updated');

    apiResponse(
        true,
        ucfirst($tab) . " saved successfully.",
        ['id' => $jobId],
        200
    );

    exit;
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
    $loginIdSql = sqlValue($loginId);

// if ($jobId !== '') {
if ($jobId !== '' && ($tab === '' || $tab === 'basic')) {

    /*
     * =========================================================
     * UPDATE EXISTING JOB DESCRIPTION
     * =========================================================
     */

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

    if (!$ok) {
        endQry();

        apiResponse(
            false,
            "Unable to update job description.",
            null,
            500
        );
                exit;
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

/* =========================================================
 * INSERT NEW JOB DESCRIPTION
 * ========================================================= */


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
    
    if (!$ok) {

    endQry();

    apiResponse(
        false,
        "Unable to save job description.",
        null,
        500
    );
}

$targetJobId = $jobId !== '' ? $jobId : $newId;

/*
=========================================================
INSERT CHILD DATA FOR NEW JD
=========================================================
*/

/*
KRA
*/
foreach ($kraData as $row) {

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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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
    ?? $row['add_info']
    ?? $row['frequency']
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
    ?? $row['exp_type']
    ?? $row['expenseType']
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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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
            '" . addslashes($targetJobId) . "',
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

} 
catch (Throwable $e) {
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
