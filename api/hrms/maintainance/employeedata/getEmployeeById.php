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

$employee = singRec("
    SELECT
        e.*,

        b.ADHAR_NO,
        b.PAN_NO,
        b.DRIV_LICE_NO,
        b.PASSPORT_NO,
        b.ESI_NO,
        b.NATIONALITY,
        b.CITIZEN_NO,
        b.M_STATUS,
        b.MOTHER_LANG,
        b.RELIGION,
        b.PF_NO,
        b.FPF_NO,
        b.PF_NOMINEE,
        b.MEMBER_ID,
        b.UAN_NO,
        b.RETIRE_AGE,
        b.GRATUITY_DATE

    FROM HR_EMPLOYEE_INFO e

    LEFT JOIN HRMSLIVE.HR_EMP_BASIC_INFO b
        ON b.EMP_ID = e.ID

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


    // Office Details - employee records
$officeDetails = multiRec("
    SELECT
        o.ID,
        o.EMP_ID,
        o.EMP_CODE,
        o.DIVSN_ID,
        o.DEPT_ID,
        o.DESI_ID,
        o.ORG_ID,
        o.ORG_LOC_ID,
        TO_CHAR(o.EFFEC_FROM, 'YYYY-MM-DD') AS EFFEC_FROM,
        TO_CHAR(o.EFFEC_TO, 'YYYY-MM-DD') AS EFFEC_TO
    FROM HRMSLIVE.HR_EMP_OFFICE_DET o
    WHERE o.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY o.EFFEC_FROM DESC
");

$reportsTo = multiRec("
    SELECT
        e.EMP_CODE,
        e.FNAME,
        e.MNAME,
        e.LNAME
    FROM HRMSLIVE.HR_EMP_OFFICE_DET emp_office
    INNER JOIN HRMSLIVE.HR_ORG_LOC_PARENT p
        ON p.ORG_ID = emp_office.ORG_ID
       AND p.ORG_LOC_ID = emp_office.ORG_LOC_ID
       AND p.STATUS = 'A'
    INNER JOIN HRMSLIVE.HR_EMP_OFFICE_DET manager_office
        ON manager_office.ORG_ID = p.PARENT_ORGID
       AND manager_office.ORG_LOC_ID = p.PARENT_LOCID
       AND manager_office.EFFEC_TO IS NULL
    INNER JOIN HRMSLIVE.HR_EMPLOYEE_INFO e
        ON e.EMP_CODE = manager_office.EMP_CODE
    WHERE emp_office.EMP_ID = '" . addslashes($employeeId) . "'
      AND e.STATUS = 'A'
");

error_log('REPORTS TO: ' . json_encode($reportsTo));

$departments = multiRec("
    SELECT
        DEPT_ID AS VALUE,
        DEPT_DESC AS LABEL
    FROM HRMSLIVE.HR_DEPARTMENT
    ORDER BY DEPT_DESC
");

$designations = multiRec("
    SELECT
        DESI_ID AS VALUE,
        DESI_DESC AS LABEL
    FROM HRMSLIVE.HR_DESIGNATION
    ORDER BY DESI_DESC
");

$divisions = multiRec("
    SELECT
        DIVSN_ID AS VALUE,
        DIVSN_DESC AS LABEL
    FROM HRMSLIVE.HR_DIVISIONS
    ORDER BY DIVSN_DESC
");

$organograms = multiRec("
    SELECT DISTINCT
        o.ID AS VALUE,
        o.ID || ' - ' ||
        o.FINENT || ' - ' ||
        NVL(l.LOC_LABEL, '') || ' - ' ||
        NVL(d.DEPT_DESC, '') || ' - ' ||
        NVL(ds.DESI_DESC, '') AS LABEL
    FROM HRMSLIVE.HR_ORGANOGRAM o
    LEFT JOIN HRMSLIVE.HR_ORGANOGRAM_LOC l
        ON l.ORG_ID = o.ID
        AND l.STATUS = 'A'
    LEFT JOIN HRMSLIVE.HR_DEPARTMENT d
        ON d.DEPT_ID = o.DEPT_ID
    LEFT JOIN HRMSLIVE.HR_DESIGNATION ds
        ON ds.DESI_ID = o.DESI_ID
    WHERE o.STATUS = 'A'
    ORDER BY LABEL
");

$organogramLocations = multiRec("
    SELECT
        ID AS VALUE,
        LOC_LABEL AS LABEL
    FROM HRMSLIVE.HR_ORGANOGRAM_LOC
    WHERE STATUS = 'A'
    ORDER BY LOC_LABEL
");

    /*
     * =========================================================
     * BANK
     * =========================================================
     */

    $banks = multiRec("
    SELECT
        b.ID,
        b.EMP_ID,
        b.EMP_CODE,
        b.BANK_NAME,
        b.BANK_BRANCH,
        b.BANK_IFSC,
        b.BANK_ACNO,
        b.BANK_NOMINEE,
        b.STATUS,
        TO_CHAR(b.CHG_ON, 'YYYY-MM-DD') AS CHG_ON,
        b.CHG_BY
    FROM HRMSLIVE.HR_EMP_BANK_DET b
    WHERE b.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY b.ID DESC
");


    /*
     * =========================================================
     * QUALIFICATION
     * =========================================================
     */

    $education = multiRec("
    SELECT
        e.ID,
        e.EMP_ID,
        e.EMP_CODE,
        e.INST_NAME,
        e.COURSE,
        TO_CHAR(e.FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE,
        TO_CHAR(e.TO_DATE, 'YYYY-MM-DD') AS TO_DATE,
        e.PERGRADE,
        e.REMARKS,
        TO_CHAR(e.CHG_ON, 'YYYY-MM-DD') AS CHG_ON,
        e.CHG_BY
    FROM HRMSLIVE.HR_EMP_EDUCATION e
    WHERE e.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY e.TO_DATE ASC
");


    /*
     * =========================================================
     * EXPERIENCE
     * =========================================================
     */

    $experience = multiRec("
    SELECT
        e.ID,
        e.EMP_ID,
        e.EMP_CODE,
        e.ORG_NAME,
        TO_CHAR(e.FROM_DATE, 'YYYY-MM-DD') AS FROM_DATE,
        TO_CHAR(e.TO_DATE, 'YYYY-MM-DD') AS TO_DATE,
        e.DESIG,
        e.GROSS_SALARY,
        e.DUTY_NATURE,
        e.LEAVE_REASON,
        TO_CHAR(e.CHG_ON, 'YYYY-MM-DD') AS CHG_ON,
        e.CHG_BY
    FROM HRMSLIVE.HR_EMP_EXPERIENCE e
    WHERE e.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY e.FROM_DATE ASC
");

/*
     * =========================================================
     * REFERENCES
     * =========================================================
     */

$references = multiRec("
    SELECT
        r.ID,
        r.EMP_CODE,
        r.EXP_ID,
        r.REF_NAME,
        r.ADDRESS,
        r.POSITION,
        r.TEL,
        r.YEAR_KNOWN,
        r.STATUS,
        r.FERIFIC_TYPE,
        r.VERIFIC_MODE,
        r.VERIFIC_REMARKS,
        TO_CHAR(r.CHG_ON, 'YYYY-MM-DD') AS CHG_ON,
        r.CHG_BY
    FROM HRMSLIVE.HR_EMP_REFERENCE r
    WHERE r.EMP_CODE = '" . addslashes($employee['EMP_CODE']) . "'
    ORDER BY r.ID
");

    /*
     * =========================================================
     * FAMILY
     * =========================================================
     */

    $family = multiRec("
    SELECT
        f.ID,
        f.EMP_ID,
        f.EMP_CODE,
        f.FM_NAME,
        f.FM_RELATION,
        f.FM_CONTACT,
        f.FM_DEP,
        f.FM_OCCUPATION,
        f.AGE,
        TO_CHAR(f.DOB, 'YYYY-MM-DD') AS DOB,
        f.AADHAAR,
        TO_CHAR(f.CHG_ON, 'YYYY-MM-DD') AS CHG_ON,
        f.CHG_BY,
        f.STATUS
    FROM HRMSLIVE.HR_EMP_FAMILY_INFO f
    WHERE f.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY f.ID
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
        d.ID,
        d.EMP_ID,
        d.EMP_CODE,
        d.DOC_ID,
        d.DOC_PATH,
        d.DOC_REF,
        TO_CHAR(d.CHG_ON, 'YYYY-MM-DD') AS CHG_ON,
        d.CHG_BY,
        d.CC_TO
    FROM HRMSLIVE.HR_EMP_DOCS d
    WHERE d.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY d.ID
");


    /*
     * =========================================================
     * TENURE
     * =========================================================
     */

$tenure = multiRec("
    SELECT
        t.ID,
        t.EMP_ID,
        t.EMP_CODE,
        t.ETYPE_ID,
        t.ETYPE_PERIOD,
        TO_CHAR(t.EFF_FROM, 'YYYY-MM-DD') AS EFF_FROM,
        TO_CHAR(t.EFF_TO, 'YYYY-MM-DD') AS EFF_TO,
        e.STATUS AS EMP_STATUS
    FROM HRMSLIVE.HR_EMP_TENURE t
    INNER JOIN HR_EMPLOYEE_INFO e
        ON e.ID = t.EMP_ID
    WHERE t.EMP_ID = '" . addslashes($employeeId) . "'
    ORDER BY t.EFF_FROM DESC
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
    SELECT
        a.ID,
        a.APP,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM EPPLIVE.BCS_APP_ACCESS aa
                WHERE aa.EMP_CODE = '" . addslashes($employee['EMP_CODE']) . "'
                  AND aa.APP_ID = a.ID
            )
            THEN 'Y'
            ELSE 'N'
        END AS HAS_ACCESS
    FROM EPPLIVE.BCS_APP a
    ORDER BY a.ID
");

/*
     * =========================================================
     * KRA
     * =========================================================
     */

$kra = multiRec("
    SELECT
        k.ID AS KRA_ROW_ID,
        k.JD_ID,
        k.KRA_ID,
        m.KRA_DESC,
        k.RESP_PERC
    FROM HRMSLIVE.HR_JD_KRA k
    INNER JOIN HRMSLIVE.HR_KRA_MASTER m
        ON m.KRA_ID = k.KRA_ID
    INNER JOIN HRMSLIVE.HR_JD j
        ON j.ID = k.JD_ID
    WHERE j.ID = (
        SELECT j2.ID
        FROM HRMSLIVE.HR_JD j2
        INNER JOIN HRMSLIVE.HR_EMP_OFFICE_DET o2
            ON o2.DEPT_ID = j2.DEPT_ID
            AND o2.DESI_ID = j2.DESIG_ID
        WHERE TRIM(o2.EMP_CODE) = TRIM('" . addslashes($employee['EMP_CODE']) . "')
          AND o2.EFFEC_TO IS NULL
          AND j2.STATUS = 'A'
          AND ROWNUM = 1
    )
    ORDER BY k.ID
");


    /*
     * =========================================================
     * RETURN EVERYTHING
     * =========================================================
     */

    $data = [
        'employee' => $employee,

        // Office Details
        'officeDetails' => $officeDetails,
            'reportsTo' => $reportsTo,
        'officeMasters' => [
        'departments' => $departments,
        'designations' => $designations,
        'divisions' => $divisions,
        'organograms' => $organograms,
        'organogramLocations' => $organogramLocations
    ],

        'banks' => $banks,
        'education' => $education,
        'experience' => $experience,
        'references' => $references,
        'family' => $family,
        'assets' => $assets,
        'documents' => $documents,
        'tenure' => $tenure,
        'policies' => $policies,
        'userAccess' => $userAccess,
            'kra' => $kra
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