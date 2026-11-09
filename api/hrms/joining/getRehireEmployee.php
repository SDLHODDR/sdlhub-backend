<?php
/*
 * GET            relieved employees in the user's company scope, and employee types.
 * GET ?emp_code= that employee's summary, last office and tenure history.
 * Legacy rehire_emp.php.
 */
require_once __DIR__ . '/joiningCommon.php';

try {
    loadHrmsAccess($empCode);
    $code = trim((string)($_GET['emp_code'] ?? ''));

    if ($code === '') {
        $companies = implode(',', array_map('intval', joiningUserCompanies())) ?: '0';
        apiResponse(true, 'Relieved employees fetched successfully.', [
            'employees' => joiningRecords("SELECT EMP_CODE AS VALUE,
                    TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) || ' - ' || EMP_CODE AS LABEL
                FROM HR_EMPLOYEE_INFO
                WHERE STATUS = 'R' AND COMP_ID IN ({$companies})
                ORDER BY 2"),
            'employeeTypes' => joiningRecords("SELECT ETYPE_ID AS VALUE, EMP_TYPE AS LABEL FROM HR_EMP_TYPE ORDER BY EMP_TYPE"),
        ], 200);
    }

    $employee = joiningLoadEmployee($code);
    joiningAssertCompanyScope($employee['COMP_ID']);
    if ($employee['STATUS'] !== 'R') {
        throw new JoiningRequestException('This employee is not relieved.', 409);
    }
    $info = joiningRecord("SELECT COM_EMAIL, TO_CHAR(DOL, 'YYYY-MM-DD') AS DOL_ISO, TO_CHAR(DOL, 'DD-Mon-YYYY') AS DOL_DATE
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :emp_code", [':emp_code' => $employee['EMP_CODE']]);
    $office = joiningRecord("SELECT * FROM (
            SELECT GET_DEPT_NAME(DEPT_ID) AS DEPT, GET_DESIGN_NAME(DESI_ID) AS DESIG, GET_ORG_LOC_NAME(ORG_LOC_ID) AS LOC
            FROM HR_EMP_OFFICE_DET WHERE EMP_CODE = :emp_code
            ORDER BY EFFEC_FROM DESC, ID DESC)
        WHERE ROWNUM = 1", [':emp_code' => $employee['EMP_CODE']]);

    apiResponse(true, 'Employee fetched successfully.', [
        'empCode' => $employee['EMP_CODE'],
        'name' => joiningEmployeeName($employee),
        'companyId' => $employee['COMP_ID'],
        'email' => $info['COM_EMAIL'] ?? '',
        'dolIso' => $info['DOL_ISO'] ?? '',
        'dolDate' => $info['DOL_DATE'] ?? '',
        'department' => $office['DEPT'] ?? '',
        'designation' => $office['DESIG'] ?? '',
        'location' => $office['LOC'] ?? '',
        'tenures' => array_map(static fn($row) => [
            'id' => $row['ID'],
            'type' => $row['EMP_TYPE'],
            'period' => $row['ETYPE_PERIOD'],
            'from' => $row['EFF_FROM_DATE'],
            'to' => $row['EFF_TO_DATE'],
        ], joiningRecords("SELECT T.ID, M.EMP_TYPE, T.ETYPE_PERIOD,
                TO_CHAR(T.EFF_FROM, 'DD-Mon-YYYY') AS EFF_FROM_DATE, TO_CHAR(T.EFF_TO, 'DD-Mon-YYYY') AS EFF_TO_DATE
            FROM HR_EMP_TENURE T INNER JOIN HR_EMP_TYPE M ON M.ETYPE_ID = T.ETYPE_ID
            WHERE T.EMP_CODE = :emp_code ORDER BY T.ID DESC", [':emp_code' => $employee['EMP_CODE']])),
    ], 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getRehireEmployee.php');
    apiResponse(false, 'Unable to load the employee.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
