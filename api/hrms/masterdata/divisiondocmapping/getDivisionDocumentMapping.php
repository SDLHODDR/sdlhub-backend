<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

ob_start();

define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

/* ==========================================================
   SESSION VALIDATION
========================================================== */

if (!isset($_SESSION['emp_code']) || empty($_SESSION['emp_code'])) {
    apiResponse(false, "Session expired. Please login again.", null, 401);
}

/* ==========================================================
   REQUEST METHOD
========================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(false, "Invalid request method.", null, 405);
}

/* ==========================================================
   READ REQUEST
========================================================== */

$input = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($input)) {
    $input = $_POST;
}

/* ==========================================================
   ACTION
========================================================== */

$action = strtolower(
    trim($input['action'] ?? '')
);

if ($action === '') {
    apiResponse(false, "Action is required.", null, 400);
}

/* ==========================================================
   INITIAL DATA
   Company + Division + Department
========================================================== */

if ($action === 'initial') {

    try {

        /* ==================================================
           COMPANY
        ================================================== */

        $companySql = "
            SELECT
                COMP_ID AS ID,
                COMP_ID || ' - ' || COMP_DESC AS DESCRIPTION
            FROM HR_COMPANY
            ORDER BY COMP_ID
        ";

        $companies = multiRec(
            $companySql,
            []
        );

        /* ==================================================
           DIVISION
        ================================================== */

        $divisionSql = "
            SELECT
                DIVSN_ID AS ID,
                DIVSN_ID || ' - ' || DIVSN_DESC AS DESCRIPTION
            FROM HR_DIVISIONS
            ORDER BY 2
        ";

        $divisions = multiRec(
            $divisionSql,
            []
        );


        /* ==================================================
           DEPARTMENT
        ================================================== */

        $departmentSql = "
            SELECT
                DEPT_ID AS ID,
                DEPT_ID || ' - ' || DEPT_DESC AS DESCRIPTION
            FROM HR_DEPARTMENT
            ORDER BY 2
        ";

        $departments = multiRec(
            $departmentSql,
            []
        );


        apiResponse(
            true,
            "Dropdown data loaded successfully.",
            [
                'companies'   => $companies ?: [],
                'divisions'   => $divisions ?: [],
                'departments' => $departments ?: []
            ],
            200
        );

    } catch (Throwable $e) {

        logOracleError(
            "getDivisionDocumentMapping.php INITIAL : " .
            $e->getMessage()
        );

        apiResponse(false, "Unable to load dropdown data.", null, 500);
    }
}

/* ==========================================================
   DESIGNATIONS
   Based on Division + Department
========================================================== */

if ($action === 'designations') {

    $divisionId = trim(
        (string)($input['divisionId'] ?? '')
    );

    $departmentId = trim(
        (string)($input['departmentId'] ?? '')
    );

    /* ======================================================
       VALIDATION
    ====================================================== */

    if ($divisionId === '') {
        apiResponse(false, "Division is required.", null, 400);
    }

    if ($departmentId === '') {
        apiResponse(false, "Department is required.", null, 400);
    }

    if (
        !ctype_digit($divisionId) ||
        !ctype_digit($departmentId)
    ) {
        apiResponse(false, "Invalid division or department.", null, 400);
    }

    try {
        $designationSql = "
            SELECT DISTINCT
                HD.DESI_ID AS ID,
                HD.DESI_ID || ' - ' || HD.DESI_DESC AS DESCRIPTION

            FROM HR_DESIGNATION HD

            WHERE HD.DESI_ID IN
            (
                SELECT HDDM.DESIG_ID
                FROM HR_DES_DEPT_MAP HDDM
                WHERE HDDM.DEPT_ID = :DEPT_ID
            )

            AND HD.DESI_ID IN
            (
                SELECT HJ.DESIG_ID
                FROM HR_JD HJ

                WHERE HJ.ID IN
                (
                    SELECT HJD.JD_ID
                    FROM HR_JD_DIVSN HJD
                    WHERE HJD.DIVSN_ID = :DIVSN_ID
                )
            )

            ORDER BY 2
        ";

        $designations = multiRec(
            $designationSql,
            [
                ':DEPT_ID'  => $departmentId,
                ':DIVSN_ID' => $divisionId
            ]
        );

        apiResponse(
            true,
            "Designations loaded successfully.",
            [
                'designations' => $designations ?: []
            ],
            200
        );

    } catch (Throwable $e) {

        logOracleError(
            "getDivisionDocumentMapping.php DESIGNATIONS : " .
            $e->getMessage()
        );

        apiResponse(false, "Unable to load designations.", null, 500);
    }
}

/* ==========================================================
   DOCUMENT MAPPING
========================================================== */

if ($action === 'mapping') {

    $companyId = trim((string)($input['companyId'] ?? ''));
    $divisionId = trim((string)($input['divisionId'] ?? '') );
    $departmentId = trim((string)($input['departmentId'] ?? ''));
    $designationId = trim((string)($input['designationId'] ?? ''));

    /* ======================================================
       VALIDATION
    ====================================================== */

    if ($companyId === '') {
        apiResponse(false, "Company is required.", null, 400);
    }

    if ($divisionId === '') {
        apiResponse(false, "Division is required.", null, 400);
    }

    if ($departmentId === '') {
        apiResponse(false, "Department is required.", null, 400);
    }

    if ($designationId === '') {
        apiResponse(false, "Designation is required.", null, 400);
    }

    if (
        !ctype_digit($companyId) ||
        !ctype_digit($divisionId) ||
        !ctype_digit($departmentId) ||
        !ctype_digit($designationId)
    ) {
        apiResponse(false, "Invalid selection.", null, 400 );
    }

    try {

        /* ==================================================
           DOCUMENT TYPES + EXISTING MAPPING
        ================================================== */

        $mappingSql = "
            SELECT
                HDT.DOCTYP_ID AS ID,
                HDT.DOCTYP_DESC AS DESCRIPTION,

                (
                    SELECT MAX(HJD.ORG_LOC_ID)
                    FROM HR_DIVSN_DOC HJD
                    WHERE HJD.DOC_ID = HDT.DOCTYP_ID
                    AND HJD.COMP_ID = :COMP_ID
                    AND HJD.DIVSN_ID = :DIVSN_ID
                    AND HJD.DEPT_ID = :DEPT_ID
                    AND HJD.DESI_ID = :DESI_ID
                ) AS ORG_LOC_ID

            FROM HR_DOC_TYPES HDT

            WHERE HDT.DOC_TYPE = 'D'

            ORDER BY HDT.DOCTYP_ID
        ";

        $documents = multiRec(
            $mappingSql,
            [
                ':COMP_ID'  => $companyId,
                ':DIVSN_ID' => $divisionId,
                ':DEPT_ID'  => $departmentId,
                ':DESI_ID'  => $designationId
            ]
        );

        /* ==================================================
           ORGANIZATION LOCATION DROPDOWN
        ================================================== */

        $orgLocationSql = "
            SELECT
                HL.ID AS ID,

                HL.ID
                || ' - '
                || GET_EMP_NAME(
                    GET_ORG_LOC_EMP_CODE(
                        HL.ID,
                        SYSDATE
                    )
                )
                || ' - '
                || GET_SHCOMP_NAME(HO.COMPANY)
                || ' - '
                || GET_DIVISION_NAME(HO.DIVSN_ID)
                || ' - '
                || GET_DEPT_NAME(HO.DEPT_ID)
                || ' - '
                || GET_DESIGN_NAME(HO.DESI_ID)
                || ' - '
                || HL.GEO_DESC AS DESCRIPTION

            FROM HR_ORGANOGRAM HO

            INNER JOIN HR_ORGANOGRAM_LOC HL
                ON HL.ORG_ID = HO.ID

            ORDER BY HL.ID ASC
        ";

        $orgLocations = multiRec(
            $orgLocationSql,
            []
        );

        /* ==================================================
           SUCCESS
        ================================================== */

        apiResponse(
            true,
            "Division document mapping data loaded successfully.",
            [
                'documents'   => $documents ?: [],
                'orgLocations' => $orgLocations ?: []
            ],
            200
        );

    } catch (Throwable $e) {

        logOracleError(
            "getDivisionDocumentMapping.php MAPPING : " .
            $e->getMessage()
        );
        apiResponse(false, "Unable to load document mapping data.", null, 500 );
    }
}

/* ==========================================================
   ORGANIZATION LOCATIONS
   Standalone action
========================================================== */

if ($action === 'orgLocations') {

    try {
        $orgLocationSql = "
            SELECT
                HL.ID AS ID,

                HL.ID
                || ' - '
                || GET_EMP_NAME(
                    GET_ORG_LOC_EMP_CODE(
                        HL.ID,
                        SYSDATE
                    )
                )
                || ' - '
                || GET_SHCOMP_NAME(HO.COMPANY)
                || ' - '
                || GET_DIVISION_NAME(HO.DIVSN_ID)
                || ' - '
                || GET_DEPT_NAME(HO.DEPT_ID)
                || ' - '
                || GET_DESIGN_NAME(HO.DESI_ID)
                || ' - '
                || HL.GEO_DESC AS DESCRIPTION

            FROM HR_ORGANOGRAM HO

            INNER JOIN HR_ORGANOGRAM_LOC HL
                ON HL.ORG_ID = HO.ID

            ORDER BY HL.ID ASC
        ";

        $orgLocations = multiRec(
            $orgLocationSql,
            []
        );

        apiResponse(
            true,
            "Organization locations loaded successfully.",
            [
                'orgLocations' => $orgLocations ?: []
            ],
            200
        );

    } catch (Throwable $e) {

        logOracleError(
            "getDivisionDocumentMapping.php ORG LOCATIONS : " .
            $e->getMessage()
        );

        apiResponse(false, "Unable to load organization locations.", null, 500);
    }
}

/* ==========================================================
   INVALID ACTION
========================================================== */
apiResponse(false, "Invalid action.", null, 400);