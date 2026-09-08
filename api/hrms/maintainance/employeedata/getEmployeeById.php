<?php

define('CURRENT_PORTAL', 'hrms');

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

try {

    $employeeId = $_GET['id'] ?? '';

    if ($employeeId === '') {
        apiResponse(
            false,
            'Employee ID is required.',
            null,
            400
        );
        exit;
    }

    /*
     * =========================================================
     * PERSONAL / BASIC EMPLOYEE INFORMATION
     * =========================================================
     */

    // $employee = singRec("
    //     SELECT
    //         e.*,

    //         TO_CHAR(e.DOB, 'YYYY-MM-DD') AS DOB,
    //         TO_CHAR(e.DOJ, 'YYYY-MM-DD') AS DOJ,

    //         t.TITLE_DESC AS TITLE_DESC,

    //         c.COMP_DESC AS COMPANY_DESC,

    //         s1.GSTAT_DESC AS CURRENT_STATE_DESC,
    //         s2.GSTAT_DESC AS PERMANENT_STATE_DESC,

    //         co1.DESCR AS CURRENT_COUNTRY_DESC,
    //         co2.DESCR AS PERMANENT_COUNTRY_DESC

    //     FROM HR_EMPLOYEE_INFO e

    //     LEFT JOIN HR_TITLES t
    //         ON t.TITLE_DESC = e.TITE

    //     LEFT JOIN HR_COMPANY c
    //         ON c.COMP_ID = e.EMP_COMPANY

    //     LEFT JOIN EPPLIVE.BCS_GST_STATES s1
    //         ON s1.GSTAT_CODE = e.STATE

    //     LEFT JOIN EPPLIVE.BCS_GST_STATES s2
    //         ON s2.GSTAT_CODE = e.PERMNT_STATE

    //     LEFT JOIN BCS_COUNTRY co1
    //         ON co1.COUNT_CODE = e.COUNTRY

    //     LEFT JOIN BCS_COUNTRY co2
    //         ON co2.COUNT_CODE = e.PERMNT_COUNTRY

    //     WHERE e.ID = '" . addslashes($employeeId) . "'
    // ");

    $employee = singRec("
    SELECT
        e.*
    FROM HR_EMPLOYEE_INFO e
    WHERE e.ID = '" . addslashes($employeeId) . "'
");

    if (!$employee) {
        apiResponse(
            false,
            'Employee not found.',
            null,
            404
        );
        exit;
    }


    /*
     * =========================================================
     * DEPARTMENT
     * =========================================================
     */

    $departments = multiRec("
        SELECT
            DEPT_CODE,
            TO_CHAR(EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM,
            TO_CHAR(EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO,
            EMP_ID
        FROM HR_EMP_DEPARTMENT
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY EFFEC_FROM DESC
    ");


    /*
     * =========================================================
     * DESIGNATION
     * =========================================================
     */

    $designations = multiRec("
        SELECT
            DESI_ID,
            TO_CHAR(EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM,
            TO_CHAR(EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO,
            EMP_ID
        FROM HR_EMP_DESIGNATION
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY EFFEC_FROM DESC
    ");


    /*
     * =========================================================
     * DIVISION
     * =========================================================
     */

    $divisions = multiRec("
        SELECT
            DIVSN_ID,
            TO_CHAR(EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM,
            TO_CHAR(EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO,
            EMP_ID
        FROM HR_EMP_DIVISIONS
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY EFFEC_FROM DESC
    ");


    /*
     * =========================================================
     * LOCATION
     * =========================================================
     */

    $locations = multiRec("
        SELECT
            LOC_NAME,
            TO_CHAR(FROM_DT, 'YYYY-MM-DD') AS FROM_DT,
            TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DATE
        FROM HR_EMP_LOCATIONS
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY FROM_DT DESC
    ");


    /*
     * =========================================================
     * BANK
     * =========================================================
     */

    $banks = multiRec("
        SELECT
            ID,
            EMP_ID,
            BANK_DESC,
            BANK_BRANCH,
            BANK_IFSC,
            BANK_ACNO,
            BANK_NOMINEE,
            STATUS
        FROM HR_EMP_BANKDET
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY ID DESC
    ");


    /*
     * =========================================================
     * QUALIFICATION
     * =========================================================
     */

    $education = multiRec("
        SELECT
            ID,
            EMP_ID,
            INST_NAME,
            TO_CHAR(FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE,
            TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DATE,
            COURSE,
            PERC,
            REMARKS
        FROM HR_EMP_EDUCATION
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY TO_DATE ASC
    ");


    /*
     * =========================================================
     * EXPERIENCE
     * =========================================================
     */

    $experience = multiRec("
        SELECT
            ID,
            EMP_ID,
            ORG_NAME,
            TO_CHAR(FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE,
            TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DATE,
            DESIG,
            GROSS_SALARY,
            DUTY_NATURE,
            LEAVE_REASON
        FROM HR_EMP_EXPERIENCE
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY FROM_DATE DESC
    ");


    /*
     * =========================================================
     * FAMILY
     * =========================================================
     */

    $family = multiRec("
        SELECT
            ID,
            EMP_ID,
            FM_RELATION,
            AGE,
            FM_DEP,
            FM_OCCUPATION,
            TO_CHAR(DOB, 'YYYY-MM-DD') AS DOB,
            AADHAAR,
            FM_NAME
        FROM HR_EMP_FAMILY_INFO
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY ID
    ");


    /*
     * =========================================================
     * ASSETS
     * =========================================================
     */

    $assets = multiRec("
        SELECT
            ID,
            APRV_BY,
            ASSET_CODE,
            EMP_ID,
            TO_CHAR(FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE,
            TO_CHAR(TO_DATE, 'YYYY-MM-DD') AS TO_DATE,
            REMARKS,
            RESP_PERSON,
            STATUS
        FROM HR_EMP_ASSET_ALLOCATION
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY FROM_DATE DESC
    ");


    /*
     * =========================================================
     * DOCUMENTS
     * =========================================================
     */

    $documents = multiRec("
        SELECT
            ID,
            EMP_ID,
            DOC_PATH,
            DOC_REMARKS,
            DOCTYP_ID
        FROM HR_EMP_DOCS
        WHERE EMP_ID = '" . addslashes($employeeId) . "'
        ORDER BY ID DESC
    ");


    /*
     * =========================================================
     * TENURE
     * =========================================================
     */

    $tenure = multiRec("
        SELECT
            j.ID,
            j.EMP_TYPE,
            j.EMP_CODE,
            j.EMP_STATUS,
            j.PERIOD,
            TO_CHAR(j.FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE,
            TO_CHAR(j.TO_DATE, 'YYYY-MM-DD') AS TO_DATE,
            i.REASON_OF_LEAVING,
            i.STATUS,
            i.CONFIRMED,
            TO_CHAR(i.DATE_CONF, 'YYYY-MM-DD') AS DATE_CONF
        FROM HR_EMPLOYEE_PERIOD j
        LEFT JOIN HR_EMPLOYEE_INFO i
            ON i.EMP_CODE = j.EMP_CODE
        WHERE j.EMP_CODE = '" . addslashes($employee['EMP_CODE']) . "'
        ORDER BY j.ID DESC
    ");


    /*
     * =========================================================
     * POLICY
     * =========================================================
     */

    $policies = multiRec("
        SELECT
            POLI_ID,
            POLICY_NAME,
            DOC_PATH,
            POLICY_DESC,
            TO_CHAR(START_DATE, 'YYYY-MM-DD') AS START_DATE
        FROM HR_POLICY
        WHERE STATUS = 'A'
          AND (
                DIVISION_ID = (
                    SELECT DIVISION
                    FROM HR_EMPLOYEE_INFO
                    WHERE EMP_CODE = '" . addslashes($employee['EMP_CODE']) . "'
                )
                OR DIVISION_ID IS NULL
              )
          AND (
                DEPT_ID = (
                    SELECT DIVISION
                    FROM HR_EMPLOYEE_INFO
                    WHERE EMP_CODE = '" . addslashes($employee['EMP_CODE']) . "'
                )
                OR DEPT_ID IS NULL
              )
        ORDER BY START_DATE DESC
    ");


    /*
     * =========================================================
     * USER ACCESS
     * =========================================================
     */

    $userAccess = multiRec("
        SELECT *
        FROM EPPLIVE.BCS_APP_ACCESS
        WHERE EMP_CODE = '" . addslashes($employee['EMP_CODE']) . "'
    ");


    /*
     * =========================================================
     * RETURN EVERYTHING
     * =========================================================
     */

    $data = [
        'employee' => $employee,

        'departments' => $departments,
        'designations' => $designations,
        'divisions' => $divisions,
        'locations' => $locations,

        'banks' => $banks,
        'education' => $education,
        'experience' => $experience,
        'family' => $family,
        'assets' => $assets,
        'documents' => $documents,
        'tenure' => $tenure,
        'policies' => $policies,
        'userAccess' => $userAccess
    ];

    apiResponse(
        true,
        'Employee information fetched successfully.',
        $data,
        200
    );

} catch (Throwable $e) {
    error_log('Employee Data API Error: ' . $e->getMessage());

    apiResponse(
        false,
        'Unable to fetch employee information.',
        null,
        500
    );
}