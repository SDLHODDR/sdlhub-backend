<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";

header('Content-Type: application/json');

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {
    apiResponse(false, "Unauthorized access", null, 401);
}

try {

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

        // Example:
        // 2026-06-01,2026-06-15

        $dates = explode(',', $familyUpdateParam['SYS_VAL']);

        if (count($dates) === 2) {

            $fromDate = trim($dates[0]);
            $toDate   = trim($dates[1]);

            $today = strtotime(date('Y-m-d'));

            $from = strtotime($fromDate);
            $to   = strtotime($toDate);

            if (
                $from &&
                $to &&
                $today >= $from &&
                $today <= $to
            ) {
                $canManageFamily = true;
            }
        }
    }

    /* =====================================================
       MAIN EMPLOYEE INFO
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

            TO_CHAR(TO_DATE(HEI.DOB), 'dd-Mon-rrrr') DOB,

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

            TO_CHAR(TO_DATE(HEI.DOJ), 'dd-Mon-rrrr') DOJ,
            TO_CHAR(TO_DATE(be.DATE_CONF), 'dd-Mon-rrrr') DATE_CONF,

            bas.shft_label,

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

        FROM EPT_bcs_employee be

        INNER JOIN EPT_BCS_ATTD_SHIFT bas
            ON be.WORK_SHIFT = bas.shft_code

        INNER JOIN EPT_HR_EMPLOYEE_INFO HEI
            ON HEI.EMP_CODE = be.EMP_CODE

        WHERE HEI.EMP_CODE = '$empCode'
        AND NVL(HEI.STATUS, 'A') <> 'd'
    ");

	/* =====================================================
	   PROFILE IMAGE
	===================================================== */

	$profileImage = null;

	// Physical location
	$filePath = rtrim($_ENV["PUBLIC_PATH"], "/")
		. "/profiles/"
		. $empCode
		. ".jpg";

	// Public URL
	$imageUrl = rtrim($_ENV["PROFILES_URL"], "/")
		. "/"
		. $empCode
		. ".jpg";

	if (file_exists($filePath)) {
		$profileImage = $imageUrl . "?v=" . filemtime($filePath);
	}

	$empInfo['PROFILE_IMAGE'] = $profileImage;
   
    /* =====================================================
       SPOUSE
    ===================================================== */

    $spouse = singRec("
        SELECT
            ID,
            FM_NAME,
            FM_RELATION,
            AGE,
            TO_CHAR(DOB,'dd-Mon-rrrr') DOB,
            AADHAAR,
            FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE = '$empCode'
        AND (
            FM_RELATION='Wife'
            OR FM_RELATION='Husband'
        )
        AND NVL(STATUS, 'A') <> 'd'
    ");

    /* =====================================================
       CHILDREN
    ===================================================== */

    $children = multiRec("
        SELECT
            ID,
            FM_NAME,
            FM_RELATION,
            AGE,
            TO_CHAR(DOB,'dd-Mon-rrrr') DOB,
            AADHAAR,
            FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE = '$empCode'
        AND (
            FM_RELATION='Son'
            OR FM_RELATION='Daughter'
        )
        AND NVL(STATUS, 'A') <> 'd'
    ");

    /* =====================================================
       FATHER
    ===================================================== */

    $father = singRec("
        SELECT
            ID,
            FM_NAME,
            FM_RELATION,
            AGE,
            TO_CHAR(DOB,'dd-Mon-rrrr') DOB,
            AADHAAR,
            FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE='$empCode'
        AND FM_RELATION='Father'
        AND NVL(STATUS, 'A') <> 'd'
    ");

    /* =====================================================
       MOTHER
    ===================================================== */

    $mother = singRec("
        SELECT
            ID,
            FM_NAME,
            FM_RELATION,
            AGE,
            TO_CHAR(DOB,'dd-Mon-rrrr') DOB,
            AADHAAR,
            FM_DEP
        FROM EPT_HR_EMP_FAMILY_INFO
        WHERE EMP_CODE='$empCode'
        AND FM_RELATION='Mother'
        AND NVL(STATUS, 'A') <> 'd'
    ");

    /* =====================================================
       RESPONSE
    ===================================================== */

    echo json_encode([
        "status" => true,
        "employee" => $empInfo,
        "spouse" => $spouse,
        "children" => $children,
        "father" => $father,
        "mother" => $mother,
        "permissions" => [
            "can_manage_family" => $canManageFamily
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => false,
        "error" => $e->getMessage()
    ]);
}
