<?php
/* GET ?report=<REPORTS_MENU key>[&dept_id=|&comp_id=] -> dropdown options of the report's filter page.
 * tenure with comp_id returns that company's employees with a tenure change.
 * jdmaster with dept_id returns the designations mapped to that department (legacy jd_sv.php?populate_des_report). */
require_once __DIR__ . '/reportsCommon.php';

$report = (string)($_GET['report'] ?? '');
reportsRunOptions($report, static function () use ($report) {
    if ($report === 'followup') {
        return ['employees' => reportsOptions("SELECT EMP_CODE AS V,
                TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) || ' - ' || EMP_CODE AS L
            FROM HR_EMPLOYEE_INFO WHERE STATUS = 'A' AND EMP_CODE IS NOT NULL ORDER BY 2")];
    }
    if ($report === 'orglochierarchy' || $report === 'orglochierarchyvisual') {
        return ['locations' => reportsHierarchyLocations()];
    }
    if ($report === 'can_track' || $report === 'req_track') {
        return [];
    }
    $companies = reportsOptions("SELECT COMP_ID AS V, COMP_DESC AS L FROM HR_COMPANY ORDER BY 2");
    if ($report === 'tenure') {
        $compId = trim((string)($_GET['comp_id'] ?? ''));
        if ($compId === '') {
            return ['companies' => $companies];
        }
        if (!ctype_digit($compId)) {
            throw new ReportsException('Invalid company.', 422);
        }
        // Employees of the company with a tenure change (legacy tenure_change_sv.php?populate_emp).
        return ['employees' => reportsOptions("SELECT DISTINCT A.EMP_CODE AS V,
                TRIM(REGEXP_REPLACE(A.FNAME || ' ' || A.MNAME || ' ' || A.LNAME, ' +', ' ')) || ' - ' || A.EMP_CODE AS L
            FROM HR_EMPLOYEE_INFO A INNER JOIN HR_EMP_TENURE_CHANGE B ON B.EMP_CODE = A.EMP_CODE
            WHERE A.COMP_ID = :comp_id ORDER BY 2", [':comp_id' => $compId])];
    }
    $departments = reportsOptions("SELECT DEPT_ID AS V, DEPT_DESC AS L FROM HR_DEPARTMENT ORDER BY 2");
    if ($report === 'orgmaster' || $report === 'openpositions') {
        return [
            'companies' => $companies,
            'divisions' => reportsOptions("SELECT DIVSN_ID AS V, DIVSN_DESC AS L FROM HR_DIVISIONS ORDER BY 2"),
            'departments' => $departments,
        ];
    }
    $deptId = trim((string)($_GET['dept_id'] ?? ''));
    if ($deptId !== '') {
        if (!ctype_digit($deptId)) {
            throw new ReportsException('Invalid department.', 422);
        }
        return ['designations' => reportsOptions("SELECT DISTINCT HD.DESI_ID AS V, HD.DESI_DESC AS L
            FROM HR_DES_DEPT_MAP M INNER JOIN HR_DESIGNATION HD ON HD.DESI_ID = M.DESIG_ID
            WHERE M.DEPT_ID = :dept_id ORDER BY 2", [':dept_id' => $deptId])];
    }
    return ['departments' => $departments];
});
