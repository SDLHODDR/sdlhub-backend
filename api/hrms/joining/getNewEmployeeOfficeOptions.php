<?php
/*
 * GET ?company=&division=&department=&designation=&organogram=&emp_code=
 * Office Details dropdowns for the New Employee page (legacy populate_desi,
 * populate_org and populate_org_loc in employee_info_sv2.php):
 *   designations for the department, organograms of the employee's company
 *   matching division / department / designation, and the organogram's
 *   locations that are not held by another employee. A relieved employee
 *   (status R) no longer holds one, even if the end date is still ahead.
 */
require_once __DIR__ . '/joiningCommon.php';

$id = static fn($key) => (string)(int)($_GET[$key] ?? 0);

try {
    $company = $id('company');
    $division = $id('division');
    $department = $id('department');
    $designation = $id('designation');
    $organogram = $id('organogram');
    $employeeCode = trim((string)($_GET['emp_code'] ?? ''));

    $options = [
        'divisions' => multiRec("SELECT DIVSN_ID AS VALUE, DIVSN_DESC AS LABEL FROM HR_DIVISIONS ORDER BY DIVSN_DESC") ?: [],
        'departments' => multiRec("SELECT DEPT_ID AS VALUE, DEPT_DESC AS LABEL FROM HR_DEPARTMENT ORDER BY DEPT_DESC") ?: [],
        'designations' => [],
        'organograms' => [],
        'locations' => [],
    ];

    if ($department !== '0') {
        $options['designations'] = multiRec("SELECT D.DESI_ID AS VALUE, D.DESI_DESC AS LABEL
            FROM HR_DESIGNATION D
            WHERE D.DESI_ID IN (SELECT DESIG_ID FROM HR_DES_DEPT_MAP WHERE DEPT_ID = :dept_id)
            ORDER BY D.DESI_DESC", [':dept_id' => $department]) ?: [];
    }

    if ($company !== '0' && $division !== '0' && $department !== '0' && $designation !== '0') {
        $options['organograms'] = multiRec("SELECT HO.ID AS VALUE,
                HO.ID || ' - ' || GET_SHCOMP_NAME(HO.COMPANY) || ' - ' || GET_DIVISION_NAME(HO.DIVSN_ID)
                    || ' - ' || GET_DEPT_NAME(HO.DEPT_ID) || ' - ' || GET_DESIGN_NAME(HO.DESI_ID) AS LABEL,
                HO.POSI_COUNT, HO.FILL_COUNT
            FROM HR_ORGANOGRAM HO
            WHERE HO.COMPANY = :company AND HO.DIVSN_ID = :division
              AND HO.DEPT_ID = :department AND HO.DESI_ID = :designation
              AND NVL(HO.STATUS, 'A') = 'A'
            ORDER BY HO.ID", [
            ':company' => $company,
            ':division' => $division,
            ':department' => $department,
            ':designation' => $designation,
        ]) ?: [];
    }

    if ($organogram !== '0') {
        $options['locations'] = multiRec("SELECT HOL.ID AS VALUE, HOL.ID || ' - ' || HOL.GEO_DESC AS LABEL
            FROM HR_ORGANOGRAM_LOC HOL
            WHERE HOL.ORG_ID = :org_id
              AND NVL(HOL.STATUS, 'A') = 'A'
              AND SYSDATE BETWEEN NVL(HOL.EFFEC_FROM, DATE '1900-01-01') AND NVL(HOL.EFFEC_TO, DATE '3000-03-01')
              AND HOL.ID NOT IN (
                  SELECT D.ORG_LOC_ID FROM HR_EMP_OFFICE_DET D
                  LEFT JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = D.EMP_CODE
                  WHERE D.ORG_LOC_ID IS NOT NULL AND D.EMP_CODE <> :emp_code
                    AND NVL(E.STATUS, 'A') <> 'R'
                    AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01'))
            ORDER BY HOL.ID", [':org_id' => $organogram, ':emp_code' => $employeeCode]) ?: [];
    }

    apiResponse(true, 'Office options fetched successfully.', $options, 200);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getNewEmployeeOfficeOptions.php');
    apiResponse(false, 'Unable to load office options.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
