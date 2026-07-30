<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";

header('Content-Type: application/json');

try {

    /* =====================================================
       SESSION VALIDATION
    ===================================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access", null, 401);
        return;
    }

    /* =====================================================
       FAMILY UPDATE WINDOW CHECK
    ===================================================== */

    $canManageFamily = false;

    $familyUpdateParam = singRec("
        SELECT SYS_VAL
        FROM EPT_SYS_PARAM
        WHERE SYS_LBL = 'FAMILY_UPDATE_RANGE'
    ");

    if (!empty($familyUpdateParam['SYS_VAL'])) {

        $dates = explode(',', $familyUpdateParam['SYS_VAL']);

        if (count($dates) === 2) {

            $from = strtotime(trim($dates[0]));
            $to   = strtotime(trim($dates[1]));
            $today = strtotime(date('Y-m-d'));

            if ($from && $to && $today >= $from && $today <= $to) {
                $canManageFamily = true;
            }
        }
    }

    /* =====================================================
       EMPLOYEE DETAILS
    ===================================================== */

    $empInfo = singRec("
        SELECT
            EPT_GET_DEPT_NAME(HR_GET_DEPTCODE_ID(be.DEPT_CODE)) DEPT_NAME,
            HR_GET_DESIGN_NAME(be.DESIGNATION) DESIG_NAME,
            HR_GET_DIVISION_NAME(be.DIVISION) DIVISION_NAME,
            HR_GET_EMP_NAME(be.REPORT_TO) REPORT_TO_NAME,
            be.REPORT_TO,
            HEI.ID,
            HEI.DOJ,
            HEI.FNAME,
            HEI.MNAME,
            HEI.LNAME,
            HEI.CELL,
            HEI.COM_EMAIL,
            HEI.PER_EMAIL,
            TO_CHAR(TO_DATE(HEI.DOB),'dd-Mon-yyyy') DOB,
            HEI.GENDER,
            HEI.EMP_CODE,
            HEI.CITY,
            HEI.ADDRESS,
            HEI.PINCODE,
            HEI.PERMNT_PINCODE,
            HEI.PERMNT_CITY,
            HEI.PERMNT_ADDRESS,
            be.M_STATUS,
            HEI.BLOOD_GRP,
            TO_CHAR(TO_DATE(HEI.DOJ),'dd-Mon-yyyy') DOJ,
            TO_CHAR(TO_DATE(be.DATE_CONF),'dd-Mon-yyyy') DATE_CONF,
            TRUNC(MONTHS_BETWEEN(SYSDATE, HEI.DOJ)/12)||' Years '||
            MOD(TRUNC(MONTHS_BETWEEN(SYSDATE, HEI.DOJ)),12)||' Months' EXPERIENCE,
            bas.SHFT_LABEL,
            be.GRADE,
            be.UAN_NO,
            be.ESIC_NO,
            be.IT_NO,
            be.AADHAR_NO,
            be.BANK_NAME,
            be.BANK_ACCT,
            be.AC_BRANCH_NAME,
            be.AC_IFSC_NO,
            be.WORK_SITE,
            HEI.TITLE
        FROM EPT_BCS_EMPLOYEE be
        INNER JOIN EPT_BCS_ATTD_SHIFT bas
            ON be.WORK_SHIFT = bas.SHFT_CODE
        INNER JOIN EPT_HR_EMPLOYEE_INFO HEI
            ON HEI.EMP_CODE = be.EMP_CODE
        WHERE HEI.EMP_CODE = '$empCode'
        AND NVL(HEI.STATUS,'A') <> 'd'
    ");

    /* =====================================================
       PROFILE IMAGE
    ===================================================== */

    $profileImage = null;

    $filePath = rtrim($_ENV["PUBLIC_PATH"], "/") . "/profiles/" . $empCode . ".jpg";
    $imageUrl = rtrim($_ENV["PROFILES_URL"], "/") . "/" . $empCode . ".jpg";

    if (file_exists($filePath)) {
        $profileImage = $imageUrl . "?v=" . filemtime($filePath);
    }

    $empInfo['PROFILE_IMAGE'] = $profileImage;

    /* =====================================================
       FAMILY DETAILS
    ===================================================== */

    $spouse = singRec("
        SELECT ID, FM_NAME, FM_RELATION, AGE,
               TO_CHAR(DOB,'dd-Mon-yyyy') DOB,
               AADHAAR, FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE='$empCode'
        AND FM_RELATION IN ('Wife','Husband')
        AND NVL(STATUS,'A') <> 'd'
    ");

    $children = multiRec("
        SELECT ID, FM_NAME, FM_RELATION, AGE,
               TO_CHAR(DOB,'dd-Mon-yyyy') DOB,
               AADHAAR, FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE='$empCode'
        AND FM_RELATION IN ('Son','Daughter')
        AND NVL(STATUS,'A') <> 'd'
    ");

    $father = singRec("
        SELECT ID, FM_NAME, FM_RELATION, AGE,
               TO_CHAR(DOB,'dd-Mon-yyyy') DOB,
               AADHAAR, FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE='$empCode'
        AND FM_RELATION='Father'
        AND NVL(STATUS,'A') <> 'd'
    ");

    $mother = singRec("
        SELECT ID, FM_NAME, FM_RELATION, AGE,
               TO_CHAR(DOB,'dd-Mon-yyyy') DOB,
               AADHAAR, FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE='$empCode'
        AND FM_RELATION='Mother'
        AND NVL(STATUS,'A') <> 'd'
    ");

    /* =====================================================
       SUCCESS RESPONSE
    ===================================================== */

    apiResponse(true, "Employee profile fetched successfully", [
        "employee" => $empInfo,
        "spouse" => $spouse,
        "children" => $children,
        "father" => $father,
        "mother" => $mother,
        "permissions" => [
            "can_manage_family" => $canManageFamily
        ]
    ]);

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch employee profile.", null, 500);
}
