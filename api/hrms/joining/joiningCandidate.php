<?php
/*
 * Employee Code Generation for a recruited candidate (joining task 3, JOIN_REQ).
 * Legacy employee_edit.php?REC_ID=<application> + convertCandidateToEmp.php
 * (create the employee from the candidate) and employee_info_sv.php?ActiveUser
 * (activate once Basic, Office, Tenure, Bank and App Access exist).
 *
 * The task's TRAN_CODE is the application (HR_CAN_APPLICATION_DETAILS.ID); once
 * the employee is created the application's EMP_CODE links the two.
 */
require_once __DIR__ . '/joiningCommon.php';

const JOINING_TASK_CODE_GENERATION = 3;

// Sections the legacy ActiveUser checks before activating (table => label).
const JOINING_ACTIVATION_SECTIONS = [
    'HR_EMP_BASIC_INFO' => 'Basic Details',
    'HR_EMP_OFFICE_DET' => 'Office Details',
    'HR_EMP_TENURE' => 'Tenure Details',
    'HR_EMP_BANK_DET' => 'Bank Details',
    'HR_BCS_APP_ACCESS' => 'Application Access',
];

/**
 * The open task 3 the user can act on, with its application, candidate and
 * the recruitment request's organogram / location.
 */
function joiningLoadCandidateTask($userTaskId, $sessionEmp)
{
    $userTaskId = (int)$userTaskId;
    if ($userTaskId <= 0) {
        throw new JoiningRequestException('A valid task is required.', 422);
    }
    loadHrmsAccess($sessionEmp);
    $task = singRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'", [
        ':id' => (string)$userTaskId,
        ':task_id' => (string)JOINING_TASK_CODE_GENERATION,
    ]);
    if (empty($task['ID'])) {
        throw new JoiningRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $sessionEmp)) {
        throw new JoiningRequestException('You are not authorized to act on this task.', 403);
    }

    $application = joiningRecord("SELECT A.ID, A.CAN_ID, A.REQ_ID, A.ORG_ID, A.STATUS, A.EMP_CODE,
            TO_CHAR(A.JOINING_DATE, 'YYYY-MM-DD') AS JOINING_ISO,
            R.ORG_LOC_ID, GET_ORG_LOC_NAME(R.ORG_LOC_ID) AS LOCATION,
            O.COMPANY, O.DIVSN_ID, O.DEPT_ID, O.DESI_ID, O.JD_ID,
            GET_SHCOMP_NAME(O.COMPANY) AS COMPANY_NAME, GET_DIVISION_NAME(O.DIVSN_ID) AS DIVISION,
            GET_DEPT_NAME(O.DEPT_ID) AS DEPARTMENT, GET_DESIGN_NAME(O.DESI_ID) AS DESIGNATION
        FROM HR_CAN_APPLICATION_DETAILS A
        INNER JOIN HR_RECRUITMENT R ON R.ID = A.REQ_ID
        INNER JOIN HR_ORGANOGRAM O ON O.ID = A.ORG_ID
        WHERE A.ID = :id", [':id' => (string)$task['TRAN_CODE']]);
    if (empty($application['ID'])) {
        throw new JoiningRequestException('The candidate application of this task was not found.', 404);
    }
    $task['APPLICATION'] = $application;
    return $task;
}

/** Candidate details used to pre-fill the Personal tab (legacy employee_edit.php). */
function joiningCandidatePrefill(array $application)
{
    $candidate = joiningRecord("SELECT TITLE_ID, CND_NAME, CND_FNAME, CND_MNAME, CND_LNAME,
            TO_CHAR(DOB, 'YYYY-MM-DD') AS DOB_ISO, CND_ADDRESS, CND_ADDR_LINE1, CND_ADDR_LINE2, CND_ADDR_LINE3,
            CND_LOCALITY, CND_CITY, CND_STATE, GET_COUNTRY_CODE(CND_COUNTRY) AS COUNTRY_CODE, CND_PINCODE,
            CND_MOBILE, CND_EMAIL, GEND_ID
        FROM HR_CANDIDATE_INFO WHERE ID = :id", [':id' => (string)$application['CAN_ID']]);
    $address = trim((string)($candidate['CND_ADDRESS'] ?? ''));
    if ($address === '') {
        $address = implode(', ', array_filter(array_map('trim', [
            (string)($candidate['CND_ADDR_LINE1'] ?? ''), (string)($candidate['CND_ADDR_LINE2'] ?? ''),
            (string)($candidate['CND_ADDR_LINE3'] ?? ''), (string)($candidate['CND_LOCALITY'] ?? ''),
        ])));
    }
    $name = static fn($value) => strtoupper(trim((string)$value));
    $digits = static fn($value) => preg_replace('/\D/', '', (string)$value);
    $mobile = $digits($candidate['CND_MOBILE'] ?? '');
    $fullName = preg_split('/\s+/', trim((string)($candidate['CND_NAME'] ?? ''))) ?: [];
    $middle = $name($candidate['CND_MNAME'] ?? '');
    // Middle name is required on the employee; older candidates only have it in the full name.
    if ($middle === '' && count($fullName) === 3) {
        $middle = $name($fullName[1]);
    }
    return [
        'name' => ucwords(strtolower(implode(' ', $fullName))),
        'title' => (string)($candidate['TITLE_ID'] ?? ''),
        'fname' => $name($candidate['CND_FNAME'] ?? ''),
        'mname' => $middle,
        'lname' => $name($candidate['CND_LNAME'] ?? ''),
        'dob' => (string)($candidate['DOB_ISO'] ?? ''),
        'address' => substr($address, 0, 300),
        'city' => (string)($candidate['CND_CITY'] ?? ''),
        'state' => (string)($candidate['CND_STATE'] ?? ''),
        'country' => (string)($candidate['COUNTRY_CODE'] ?? ''),
        'pincode' => substr($digits($candidate['CND_PINCODE'] ?? ''), 0, 6),
        'cell' => strlen($mobile) > 10 ? substr($mobile, -10) : $mobile,
        'per_email' => strtolower(trim((string)($candidate['CND_EMAIL'] ?? ''))),
        'gender' => (string)($candidate['GEND_ID'] ?? ''),
        'comp_id' => (string)$application['COMPANY'],
        'doj' => (string)$application['JOINING_ISO'],
    ];
}

/** Sections still missing before the employee can be activated. */
function joiningActivationMissing($employeeCode)
{
    $missing = [];
    foreach (JOINING_ACTIVATION_SECTIONS as $table => $label) {
        $row = singRec("SELECT COUNT(*) AS CNT FROM {$table} WHERE EMP_CODE = :emp_code", [':emp_code' => (string)$employeeCode]);
        if ((int)($row['CNT'] ?? 0) === 0) {
            $missing[] = $label;
        }
    }
    return $missing;
}

/** Candidate status history (legacy candidate_log). */
function joiningCandidateLog(array $application, $status, $userTaskId, $sessionEmp)
{
    joiningExecute("INSERT INTO HR_CAN_APP_DET_LOG (CAN_APP_DET_ID, CAN_ID, REQ_ID, STATUS, TASK_ID, CHG_BY, CHG_ON)
        VALUES (:app_id, :can_id, :req_id, :status, :task_id, :chg_by, SYSDATE)", [
        ':app_id' => (string)$application['ID'],
        ':can_id' => (string)$application['CAN_ID'],
        ':req_id' => (string)$application['REQ_ID'],
        ':status' => $status,
        ':task_id' => (string)$userTaskId,
        ':chg_by' => $sessionEmp,
    ], 'Could not log the candidate status.');
}

/** Employee code of an application still joining (status T), if any. */
function joiningCandidateEmployeeApplication($employeeCode)
{
    return joiningRecord("SELECT ID FROM HR_CAN_APPLICATION_DETAILS WHERE EMP_CODE = :emp_code AND STATUS = 'T'",
        [':emp_code' => (string)$employeeCode]);
}
