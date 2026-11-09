<?php
/*
 * POST { app_id, action: 'save', joining_date, joining_location, joining_remarks }
 *   or { app_id, action: 'submit' }
 * Legacy new_joinee_sv.php. Save stores the joining details. Submit uses the saved
 * details: it fills the position, closes the recruitment request, moves the
 * application to T and creates the Employee Code Generation task (3, JOIN_REQ).
 */
require_once __DIR__ . '/joiningCommon.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiResponse(false, 'Invalid request method.', null, 405);
    exit;
}

$input = joiningInput();

joiningRunAction(function () use ($input, $empCode) {
    loadHrmsAccess($empCode);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['save', 'submit'], true)) {
        throw new JoiningRequestException('Invalid action.', 422);
    }

    $application = joiningRecord("SELECT A.ID, A.STATUS, A.REQ_ID, A.ORG_ID, B.CND_NAME,
            TO_CHAR(A.JOINING_DATE, 'YYYY-MM-DD') AS JOINING_ISO, A.JOINING_LOCATION, A.JOINING_REMARKS,
            E.COMPANY, E.DIVSN_ID, E.DEPT_ID,
            GET_DEPT_NAME(E.DEPT_ID) || ' - ' || GET_DESIGN_NAME(E.DESI_ID) AS DESIG
        FROM HR_CAN_APPLICATION_DETAILS A
        INNER JOIN HR_CANDIDATE_INFO B ON B.ID = A.CAN_ID
        INNER JOIN HR_ORGANOGRAM E ON E.ID = A.ORG_ID
        WHERE A.ID = :id", [':id' => (string)(int)($input['app_id'] ?? 0)]);
    if (empty($application['ID'])) {
        throw new JoiningRequestException('Application not found.', 404);
    }
    joiningAssertCompanyScope($application['COMPANY']);
    if ($application['STATUS'] !== 'J') {
        throw new JoiningRequestException('This joinee has already been submitted.', 409);
    }

    if ($action === 'save') {
        $date = joiningIsoDate($input['joining_date'] ?? '', 'Joining date');
        $location = trim((string)($input['joining_location'] ?? ''));
        $remarks = trim((string)($input['joining_remarks'] ?? ''));
        if ($location === '') throw new JoiningRequestException('Joining location is required.', 422);
        if ($remarks === '') throw new JoiningRequestException('Joining remarks are required.', 422);
        joiningAssertLength($location, 50, 'Joining location');
        joiningAssertLength($remarks, 100, 'Joining remarks');

        joiningExecute("UPDATE HR_CAN_APPLICATION_DETAILS
            SET JOINING_DATE = TO_DATE(:joining_date, 'YYYY-MM-DD'), JOINING_LOCATION = :joining_location,
                JOINING_REMARKS = :joining_remarks, CHG_ON = SYSDATE, CHG_BY = :chg_by
            WHERE ID = :id", [
            ':joining_date' => $date,
            ':joining_location' => $location,
            ':joining_remarks' => $remarks,
            ':chg_by' => $empCode,
            ':id' => $application['ID'],
        ], 'Could not save the joining details.');
        return ['Joining details saved successfully.', null];
    }

    if ($application['JOINING_ISO'] === '' || $application['JOINING_LOCATION'] === '' || $application['JOINING_REMARKS'] === '') {
        throw new JoiningRequestException('Save the joining details before submitting.', 422);
    }

    // One statement, so two people cannot both take the last free position.
    $filled = joiningExecute("UPDATE HR_ORGANOGRAM SET FILL_COUNT = NVL(FILL_COUNT, 0) + 1, CHG_BY = :chg_by, CHG_ON = SYSDATE
        WHERE ID = :id AND NVL(FILL_COUNT, 0) < NVL(POSI_COUNT, 0)",
        [':chg_by' => $empCode, ':id' => $application['ORG_ID']], 'Could not update the organogram fill count.');
    if ($filled !== 1) {
        throw new JoiningRequestException('Position is already occupied!', 409);
    }
    joiningExecute("UPDATE HR_RECRUITMENT SET STATUS = 'C', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
        [':chg_by' => $empCode, ':id' => $application['REQ_ID']], 'Could not close the recruitment request.');
    joiningExecute("UPDATE HR_CAN_APPLICATION_DETAILS SET STATUS = 'T', CHG_BY = :chg_by, CHG_ON = SYSDATE WHERE ID = :id",
        [':chg_by' => $empCode, ':id' => $application['ID']], 'Could not update the application.');
    joiningGenerateTask('JOIN_REQ', $application['ID'], $application['CND_NAME'] . ' - ' . $application['DESIG'],
        $empCode, $application['COMPANY'], $application['DIVSN_ID'], $application['DEPT_ID']);

    return ['Task generated for HR.', null];
}, 'saveNewJoinee.php');
