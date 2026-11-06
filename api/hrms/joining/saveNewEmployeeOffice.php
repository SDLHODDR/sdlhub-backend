<?php
/*
 * POST office details of an employee still in status N (legacy saveOfficeDet).
 * A new joiner has one office row: it is created, or corrected while in draft.
 */
require_once __DIR__ . '/joiningCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    loadHrmsAccess($empCode);
    $employee = joiningLoadEmployee($input['emp_code'] ?? '');
    joiningAssertCompanyScope($employee['COMP_ID']);
    if ($employee['STATUS'] !== 'N') {
        throw new JoiningRequestException('This employee has already been sent for authorization.', 409);
    }

    $id = static fn($key) => (string)(int)($input[$key] ?? 0);
    $division = $id('divsn_id');
    $department = $id('dept_id');
    $designation = $id('desi_id');
    $organogram = $id('org_id');
    $location = $id('org_loc_id');
    foreach (['Division' => $division, 'Department' => $department, 'Designation' => $designation,
              'Organogram' => $organogram, 'Organogram location' => $location] as $label => $value) {
        if ($value === '0') {
            throw new JoiningRequestException("{$label} is required.", 422);
        }
    }
    $effectiveFrom = joiningIsoDate($input['effec_from'] ?? '', 'Effective from');
    if ($employee['DOJ_ISO'] && $effectiveFrom < $employee['DOJ_ISO']) {
        throw new JoiningRequestException('Effective from cannot be before the date of joining.', 422);
    }

    $org = singRec("SELECT ID FROM HR_ORGANOGRAM
        WHERE ID = :id AND COMPANY = :company AND DIVSN_ID = :division AND DEPT_ID = :department AND DESI_ID = :designation", [
        ':id' => $organogram,
        ':company' => $employee['COMP_ID'],
        ':division' => $division,
        ':department' => $department,
        ':designation' => $designation,
    ]);
    if (empty($org['ID'])) {
        throw new JoiningRequestException('The organogram does not match the company, division, department and designation.', 422);
    }
    $locationRow = singRec("SELECT ID FROM HR_ORGANOGRAM_LOC WHERE ID = :id AND ORG_ID = :org_id",
        [':id' => $location, ':org_id' => $organogram]);
    if (empty($locationRow['ID'])) {
        throw new JoiningRequestException('The location does not belong to the selected organogram.', 422);
    }
    // A relieved employee (status R) no longer holds a location, even if the end date is still ahead.
    $taken = singRec("SELECT COUNT(*) AS CNT FROM HR_EMP_OFFICE_DET D
        LEFT JOIN HR_EMPLOYEE_INFO E ON E.EMP_CODE = D.EMP_CODE
        WHERE D.ORG_LOC_ID = :loc AND D.EMP_CODE <> :emp_code
          AND NVL(E.STATUS, 'A') <> 'R'
          AND SYSDATE BETWEEN D.EFFEC_FROM AND NVL(D.EFFEC_TO, DATE '3000-03-01')",
        [':loc' => $location, ':emp_code' => $employee['EMP_CODE']]);
    if ((int)($taken['CNT'] ?? 0) > 0) {
        throw new JoiningRequestException('This organogram location is already held by another employee.', 422);
    }

    $binds = [
        ':divsn_id' => $division,
        ':dept_id' => $department,
        ':desi_id' => $designation,
        ':org_id' => $organogram,
        ':org_loc_id' => $location,
        ':effec_from' => $effectiveFrom,
        ':chg_by' => $empCode,
    ];
    $office = joiningEmployeeOffice($employee['EMP_CODE']);
    $fillCount = static function ($orgId, $delta) use ($empCode) {
        joiningExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = NVL(FILL_COUNT, 0) + ({$delta}), CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
            [':chg_by' => $empCode, ':id' => (string)$orgId], 'Could not update the organogram fill count.');
    };

    if (!empty($office['ID'])) {
        joiningExecute("UPDATE HR_EMP_OFFICE_DET
            SET DIVSN_ID = :divsn_id, DEPT_ID = :dept_id, DESI_ID = :desi_id, ORG_ID = :org_id, ORG_LOC_ID = :org_loc_id,
                EFFEC_FROM = TO_DATE(:effec_from, 'YYYY-MM-DD'), CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE ID = :id", $binds + [':id' => $office['ID']], 'Could not save the office details.');
        if ((string)$office['ORG_ID'] !== $organogram) {
            $fillCount($office['ORG_ID'], -1);
            $fillCount($organogram, 1);
        }
    } else {
        joiningExecute("INSERT INTO HR_EMP_OFFICE_DET
                (EMP_ID, EMP_CODE, DIVSN_ID, DEPT_ID, DESI_ID, ORG_ID, ORG_LOC_ID, EFFEC_FROM, CHG_ON, CHG_BY)
            VALUES (:emp_id, :emp_code, :divsn_id, :dept_id, :desi_id, :org_id, :org_loc_id,
                TO_DATE(:effec_from, 'YYYY-MM-DD'), SYSDATE, :chg_by)",
            $binds + [':emp_id' => $employee['ID'], ':emp_code' => $employee['EMP_CODE']],
            'Could not save the office details.');
        $fillCount($organogram, 1);
    }

    $deptCode = singRec("SELECT DEPT_CODE FROM HR_DEPARTMENT WHERE DEPT_ID = :id", [':id' => $department]);
    joiningExecute("UPDATE HR_BCS_EMPLOYEE
        SET DIVISION = :division, DEPT_ID = :dept_id, DEPT_CODE = :dept_code, DESIGNATION = :designation,
            ORG_ID = :org_id, CHG_ON = SYSDATE, CHG_BY = :chg_by
        WHERE EMP_CODE = :emp_code", [
        ':division' => $division,
        ':dept_id' => $department,
        ':dept_code' => $deptCode['DEPT_CODE'] ?? '',
        ':designation' => $designation,
        ':org_id' => $organogram,
        ':chg_by' => $empCode,
        ':emp_code' => $employee['EMP_CODE'],
    ], 'Could not update the EPP employee record.');

    return ['Office details saved successfully.', ['emp_code' => $employee['EMP_CODE']]];
}, 'saveNewEmployeeOffice.php');
