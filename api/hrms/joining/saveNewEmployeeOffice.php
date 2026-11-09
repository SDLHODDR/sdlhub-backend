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

    $validated = joiningValidateOffice($input, $employee['COMP_ID'], $employee['EMP_CODE']);
    $organogram = $validated['org_id'];
    $effectiveFrom = joiningIsoDate($input['effec_from'] ?? '', 'Effective from');
    if ($employee['DOJ_ISO'] && $effectiveFrom < $employee['DOJ_ISO']) {
        throw new JoiningRequestException('Effective from cannot be before the date of joining.', 422);
    }

    $binds = [
        ':divsn_id' => $validated['divsn_id'],
        ':dept_id' => $validated['dept_id'],
        ':desi_id' => $validated['desi_id'],
        ':org_id' => $organogram,
        ':org_loc_id' => $validated['org_loc_id'],
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

    joiningUpdateEppOffice($employee['EMP_CODE'], $validated, $empCode);

    return ['Office details saved successfully.', ['emp_code' => $employee['EMP_CODE']]];
}, 'saveNewEmployeeOffice.php');
