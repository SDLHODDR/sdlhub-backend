<?php
/*
|--------------------------------------------------------------------------
| RECRUITMENT FLOW - COMMON
|--------------------------------------------------------------------------
| Tasks after Assign Recruiter (legacy recruitment_sv.php and its task pages):
|   6  SEND_SUIT          Send Suitable Candidates     send_suitable_cand.php   TRAN_CODE = request id
|   7  SHORT_CAN          Shortlist Candidates         short_can.php            TRAN_CODE = request id
|   8  SCHED_INT          Schedule Interview           schedule_int.php         TRAN_CODE = application id
|   16 INT_CONF           Interview Confirmation       conf_can_int.php         TRAN_CODE = application id
|   11 ASS_INT            Assign Interviewer           assign_int.php           TRAN_CODE = application id
|   9  SESS_INT           Interview Session            session_int.php          TRAN_CODE = interview id
|   17 SESS_INT_RECO      Session as Recommender       session_int_reco.php     TRAN_CODE = interview id
|   10 OFFER_GEN          Generate Offer               offer_generate.php       TRAN_CODE = interview id
|   21 ANNEX_AUTH         Annexure Approval            offer_generate_auth.php  TRAN_CODE = interview id
|   22 OFFER_SEND         Print Offer Letter           offer_generate.php       TRAN_CODE = interview id
|   12 UPDATE_OFFER_ACCEP Update Offer Acceptance      offer_generate.php       TRAN_CODE = interview id
| An accepted offer (application 'J') then goes to New Joinee.
| Application ids are HR_CAN_APPLICATION_DETAILS.ID, interview ids HR_INTRV_DATES.ID.
*/

require_once __DIR__ . '/recruitmentCommon.php';

const RECRUITMENT_TASK_SEND_SUITABLE = 6;
const RECRUITMENT_TASK_SHORTLIST = 7;
const RECRUITMENT_TASK_SCHEDULE_INTERVIEW = 8;
const RECRUITMENT_TASK_INTERVIEW_SESSION = 9;
const RECRUITMENT_TASK_GENERATE_OFFER = 10;
const RECRUITMENT_TASK_ASSIGN_INTERVIEWER = 11;
const RECRUITMENT_TASK_OFFER_ACCEPTANCE = 12;
const RECRUITMENT_TASK_INTERVIEW_CONFIRMATION = 16;
const RECRUITMENT_TASK_RECOMMENDER_SESSION = 17;
const RECRUITMENT_TASK_ANNEXURE_APPROVAL = 21;
const RECRUITMENT_TASK_PRINT_OFFER = 22;

// What each task's TRAN_CODE points to.
const RECRUITMENT_FLOW_TRAN_KIND = [
    RECRUITMENT_TASK_SEND_SUITABLE => 'request',
    RECRUITMENT_TASK_SHORTLIST => 'request',
    RECRUITMENT_TASK_SCHEDULE_INTERVIEW => 'application',
    RECRUITMENT_TASK_INTERVIEW_CONFIRMATION => 'application',
    RECRUITMENT_TASK_ASSIGN_INTERVIEWER => 'application',
    RECRUITMENT_TASK_INTERVIEW_SESSION => 'interview',
    RECRUITMENT_TASK_RECOMMENDER_SESSION => 'interview',
    RECRUITMENT_TASK_GENERATE_OFFER => 'interview',
    RECRUITMENT_TASK_ANNEXURE_APPROVAL => 'interview',
    RECRUITMENT_TASK_PRINT_OFFER => 'interview',
    RECRUITMENT_TASK_OFFER_ACCEPTANCE => 'interview',
];

// Legacy schedule_int.php $ModesArr and the HR_INTRV_DATES.INTRV_STATUS values.
const RECRUITMENT_INTERVIEW_MODES = ['I' => 'In Person', 'V' => 'Virtual', 'T' => 'Telephonic'];
const RECRUITMENT_INTERVIEW_STATUSES = [
    'N' => 'Not Intimated', 'S' => 'Scheduled', 'C' => 'Confirmed', 'R' => 'Rescheduled', 'X' => 'Cancelled',
];
// Legacy recruitment_sv.php CloseGOTask: the annexure is always approved by this employee.
const RECRUITMENT_ANNEXURE_APPROVER = '02218';
// Legacy recruitment_sv.php sendCTCMail recipients.
const RECRUITMENT_CTC_MAIL_TO = ['nehamishra@teamsdl.in'];
const RECRUITMENT_CTC_MAIL_CC = ['nikita.sawant@teamsdl.in'];
const RECRUITMENT_FEEDBACK_REMARK_MAX = 200;
const RECRUITMENT_ANSWER_MAX = 1000;

/**
 * Load an open flow task the user may act on, with the request, application,
 * candidate and interview it belongs to. Returns [$task, $context].
 */
function recruitmentFlowLoadTask($workflowTaskId, $userTaskId, $empCode)
{
    $workflowTaskId = (int)$workflowTaskId;
    $userTaskId = (int)$userTaskId;
    $kind = RECRUITMENT_FLOW_TRAN_KIND[$workflowTaskId] ?? null;
    if ($kind === null || $userTaskId <= 0) {
        throw new RecruitmentRequestException('A valid task is required.', 422);
    }

    loadHrmsAccess($empCode);
    $task = recruitmentRow("SELECT ID, TASK_ID, TRAN_CODE, TRAN_DESC, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS WHERE ID = :id AND TASK_ID = :task_id AND STATUS = 'O'", [
        ':id' => (string)$userTaskId,
        ':task_id' => (string)$workflowTaskId,
    ]);
    if (empty($task['ID'])) {
        throw new RecruitmentRequestException('The task was not found or is no longer open.', 404);
    }
    if (!hrmsCanActOnTask($task, $empCode)) {
        throw new RecruitmentRequestException('You are not authorized to open this task.', 403);
    }

    $tranCode = (string)$task['TRAN_CODE'];
    $interview = [];
    $application = [];
    if ($kind === 'interview') {
        $interview = recruitmentFlowInterview($tranCode);
        if (empty($interview['ID'])) {
            throw new RecruitmentRequestException('The interview for this task was not found.', 404);
        }
        $application = recruitmentFlowApplicationFor($interview['CAN_ID'], $interview['REQ_ID']);
        $requestId = $interview['REQ_ID'];
    } elseif ($kind === 'application') {
        $application = recruitmentFlowApplication($tranCode);
        $requestId = $application['REQ_ID'] ?? '';
    } else {
        $requestId = $tranCode;
    }
    if ($kind !== 'request' && empty($application['ID'])) {
        throw new RecruitmentRequestException('The candidate application for this task was not found.', 404);
    }

    $request = recruitmentFlowRequest($requestId);
    if (empty($request['ID'])) {
        throw new RecruitmentRequestException('The recruitment request for this task was not found.', 404);
    }
    if ($kind === 'request' && $request['STATUS'] !== 'I') {
        throw new RecruitmentRequestException('This recruitment request is no longer in process.', 409);
    }
    if ($request['STATUS'] === 'X') {
        throw new RecruitmentRequestException('This recruitment request has been cancelled.', 409);
    }

    $candidate = [];
    if (!empty($application['CAN_ID'])) {
        $candidate = recruitmentRow("SELECT ID, CND_NAME, CND_EMAIL, CND_MOBILE, DOC_PATH FROM HR_CANDIDATE_INFO WHERE ID = :id",
            [':id' => (string)$application['CAN_ID']]);
    }

    return [$task, [
        'request' => $request,
        'application' => $application,
        'candidate' => $candidate,
        'interview' => $interview,
    ]];
}

function recruitmentFlowRequest($requestId)
{
    if ((int)$requestId <= 0) {
        return [];
    }
    return recruitmentRow("SELECT HR.ID, HR.STATUS, HR.REC_TITLE, HR.REMARKS, HR.JD_ID, HR.ORG_ID, HR.ORG_LOC_ID,
            HR.ASSIGNED_TO, HR.AUTH_BY, HR.DIVSN_ID, HR.DEPT_ID, HR.DESIG_ID, O.COMPANY,
            GET_DEPT_NAME(HR.DEPT_ID) AS DEPT, GET_DESIGN_NAME(HR.DESIG_ID) AS DESIG,
            GET_DIVISION_NAME(HR.DIVSN_ID) AS DIVSN, GET_ORG_LOC_NAME(HR.ORG_LOC_ID) AS LOC
        FROM HR_RECRUITMENT HR
        INNER JOIN HR_ORGANOGRAM O ON O.ID = HR.ORG_ID
        WHERE HR.ID = :id", [':id' => (string)(int)$requestId]);
}

function recruitmentFlowApplication($applicationId)
{
    return recruitmentRow("SELECT ID, CAN_ID, REQ_ID, STATUS, DESIG_ID, DEPT_ID FROM HR_CAN_APPLICATION_DETAILS WHERE ID = :id",
        [':id' => (string)(int)$applicationId]);
}

function recruitmentFlowApplicationFor($canId, $requestId)
{
    return recruitmentRow("SELECT ID, CAN_ID, REQ_ID, STATUS, DESIG_ID, DEPT_ID FROM HR_CAN_APPLICATION_DETAILS
        WHERE CAN_ID = :can_id AND REQ_ID = :req_id ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY",
        [':can_id' => (string)(int)$canId, ':req_id' => (string)(int)$requestId]);
}

function recruitmentFlowInterview($interviewId)
{
    return recruitmentRow("SELECT ID, CAN_ID, REQ_ID, INTRV_STATUS, INTRV_MODE,
            TO_CHAR(INTRV_DATE, 'YYYY-MM-DD') AS INTRV_DAY, TO_CHAR(INTRV_DATE, 'HH24:MI') AS INTRV_TIME,
            TO_CHAR(INTRV_DATE, 'DD-Mon-YYYY') AS INTRV_DATE_TEXT,
            CTC_OFFER, ETYPE_ID, ETYPE_PERIOD,
            TO_CHAR(TNT_JOIN_DATE, 'YYYY-MM-DD') AS TNT_JOIN_DAY, TO_CHAR(TNT_JOIN_DATE, 'DD-Mon-YYYY') AS TNT_JOIN_TEXT
        FROM HR_INTRV_DATES WHERE ID = :id", [':id' => (string)(int)$interviewId]);
}

/** Bound INSERT; $sysdate lists columns set to SYSDATE. Returns the trigger-assigned ID. */
function recruitmentFlowInsert($table, array $data, array $sysdate = [])
{
    $columns = [];
    $values = [];
    $binds = [];
    foreach ($data as $column => $value) {
        $columns[] = $column;
        $values[] = ':v_' . strtolower($column);
        $binds[':v_' . strtolower($column)] = $value === null ? '' : (string)$value;
    }
    foreach ($sysdate as $column) {
        $columns[] = $column;
        $values[] = 'SYSDATE';
    }

    global $sql___func___con;
    $stmt = oci_parse($sql___func___con, "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ("
        . implode(', ', $values) . ") RETURNING ID INTO :new_id");
    if (!$stmt) {
        throw new RuntimeException("Could not prepare the {$table} insert.");
    }
    foreach ($binds as $key => $value) {
        $binds[$key] = $value;
        oci_bind_by_name($stmt, $key, $binds[$key]);
    }
    $newId = '';
    oci_bind_by_name($stmt, ':new_id', $newId, 40);
    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        oci_free_statement($stmt);
        logOracleError($error, "INSERT INTO {$table}");
        throw new RuntimeException("Could not save into {$table}.");
    }
    oci_free_statement($stmt);
    return $newId;
}

/** Legacy candidate_log(): history of application status changes. */
function recruitmentFlowLog(array $application, $status, $userTaskId, $empCode)
{
    recruitmentFlowInsert('HR_CAN_APP_DET_LOG', [
        'CAN_APP_DET_ID' => $application['ID'],
        'CAN_ID' => $application['CAN_ID'],
        'REQ_ID' => $application['REQ_ID'],
        'STATUS' => $status,
        'TASK_ID' => (string)$userTaskId,
        'CHG_BY' => $empCode,
    ], ['CHG_ON']);
}

/** Set an application's status and log it (legacy update + candidate_log). */
function recruitmentFlowSetApplicationStatus(array $application, $status, $userTaskId, $empCode, $logStatus = null)
{
    recruitmentExecute("UPDATE HR_CAN_APPLICATION_DETAILS SET STATUS = :status, CHG_ON = SYSDATE, CHG_BY = :chg_by WHERE ID = :id",
        [':status' => $status, ':chg_by' => $empCode, ':id' => (string)$application['ID']], 'Could not update the candidate status.');
    recruitmentFlowLog($application, $logStatus ?? $status, $userTaskId, $empCode);
}

/** New task in the request's company / division / department (legacy getCompfromReq etc.). */
function recruitmentFlowCreateTask($group, $tranCode, $description, $assignedTo, $empCode, array $request)
{
    return generateTaskHR($group, (string)$tranCode, $description, null, null, 'O', $assignedTo ?: null, $empCode,
        $request['COMPANY'] ?? '', $request['DIVSN_ID'] ?? '', $request['DEPT_ID'] ?? '');
}

function recruitmentFlowHasOpenTask($workflowTaskId, $tranCode)
{
    $row = recruitmentRow("SELECT COUNT(*) AS CNT FROM HR_USER_TASKS WHERE TASK_ID = :task_id AND TRAN_CODE = :tran_code AND STATUS = 'O'",
        [':task_id' => (string)$workflowTaskId, ':tran_code' => (string)$tranCode]);
    return (int)($row['CNT'] ?? 0) > 0;
}

/** Legacy getCandidateInfo(): candidate name in title case. */
function recruitmentFlowCandidateName(array $candidate)
{
    return ucwords(strtolower(trim((string)($candidate['CND_NAME'] ?? ''))));
}

function recruitmentFlowEmployeeName($empCode)
{
    if (trim((string)$empCode) === '') {
        return '';
    }
    $row = recruitmentRow("SELECT TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) || ' (' || EMP_CODE || ')' AS NM
        FROM HR_EMPLOYEE_INFO WHERE EMP_CODE = :code", [':code' => (string)$empCode]);
    return $row['NM'] ?? (string)$empCode;
}

/** Active employees for the interviewer / recommender lists (legacy assign_int.php). */
function recruitmentFlowActiveEmployees()
{
    return array_map(static fn($row) => ['value' => $row['EMP_CODE'], 'label' => $row['LABEL']],
        multiRec("SELECT EMP_CODE, TRIM(REGEXP_REPLACE(FNAME || ' ' || MNAME || ' ' || LNAME, ' +', ' ')) || ' - ' || EMP_CODE AS LABEL
            FROM HR_EMPLOYEE_INFO WHERE STATUS = 'A' AND EMP_CODE IS NOT NULL ORDER BY 2") ?: []);
}

/** Summary shown at the top of every flow task. */
function recruitmentFlowSummary(array $context)
{
    $request = $context['request'];
    $candidate = $context['candidate'];
    $interview = $context['interview'];
    return [
        'request' => [
            'id' => $request['ID'],
            'title' => $request['REC_TITLE'] ?? '',
            'department' => $request['DEPT'] ?? '',
            'designation' => $request['DESIG'] ?? '',
            'division' => $request['DIVSN'] ?? '',
            'location' => $request['LOC'] ?? '',
            'remarks' => $request['REMARKS'] ?? '',
        ],
        'candidate' => $candidate ? [
            'id' => $candidate['ID'],
            'name' => recruitmentFlowCandidateName($candidate),
            'email' => $candidate['CND_EMAIL'] ?? '',
            'mobile' => $candidate['CND_MOBILE'] ?? '',
            'docPath' => $candidate['DOC_PATH'] ?? '',
            'applicationStatus' => $context['application']['STATUS'] ?? '',
        ] : null,
        'interview' => $interview ? recruitmentFlowInterviewView($interview) : null,
    ];
}

function recruitmentFlowInterviewView(array $interview)
{
    return [
        'id' => $interview['ID'],
        'date' => $interview['INTRV_DAY'] ?? '',
        'dateText' => $interview['INTRV_DATE_TEXT'] ?? '',
        'time' => $interview['INTRV_TIME'] ?? '',
        'mode' => $interview['INTRV_MODE'] ?? '',
        'modeText' => RECRUITMENT_INTERVIEW_MODES[$interview['INTRV_MODE'] ?? ''] ?? '',
        'status' => $interview['INTRV_STATUS'] ?? '',
        'statusText' => RECRUITMENT_INTERVIEW_STATUSES[$interview['INTRV_STATUS'] ?? ''] ?? '',
        'ctcOffer' => $interview['CTC_OFFER'] ?? '',
        'employeeType' => $interview['ETYPE_ID'] ?? '',
        'employeeTypePeriod' => $interview['ETYPE_PERIOD'] ?? '',
        'joiningDate' => $interview['TNT_JOIN_DAY'] ?? '',
        'joiningDateText' => $interview['TNT_JOIN_TEXT'] ?? '',
    ];
}

function recruitmentFlowAssertNumber($value, $label, $min = 0, $integer = false)
{
    $value = trim((string)$value);
    $pattern = $integer ? '/^\d{1,6}$/' : '/^\d{1,6}(\.\d{1,2})?$/';
    if ($value === '' || !preg_match($pattern, $value) || (float)$value <= $min) {
        throw new RecruitmentRequestException("Please enter a valid {$label}.", 422);
    }
    return $value;
}

/**
 * Queue an email in the EPP mailbox (legacy bcs_bulk_mail). Uses its own EPP
 * connection, so it commits on its own. Returns success.
 */
function recruitmentFlowQueueMail(array $to, array $cc, $subject, $body, $description)
{
    $epp = db_epplive();
    if (!$epp) {
        logOracleError(['message' => 'EPP connection failed'], 'recruitment mail');
        return false;
    }
    try {
        $chunks = str_split((string)$body, 4000) ?: [''];
        if (count($chunks) > 5) {
            throw new RuntimeException('Mail body too long.');
        }
        $columns = ['MAIL_BODY', 'MAIL_BODY1', 'MAIL_BODY2', 'MAIL_BODY3', 'MAIL_BODY4'];
        $mail = oci_parse($epp, "INSERT INTO BCS_MAILBOX_EPP (SUBJECT, " . implode(', ', $columns) . ", STATUS, CHG_ON, CHG_BY, MAIL_DESCR)
            VALUES (:subject, :b0, :b1, :b2, :b3, :b4, 'N', SYSDATE, 'HRMS', :descr) RETURNING ID INTO :mail_id");
        $values = [
            ':subject' => substr((string)$subject, 0, 500),
            ':descr' => substr((string)$description, 0, 50),
        ];
        foreach ($columns as $index => $column) {
            $values[":b{$index}"] = $chunks[$index] ?? '';
        }
        foreach ($values as $key => $value) {
            $values[$key] = $value;
            oci_bind_by_name($mail, $key, $values[$key]);
        }
        $mailId = '';
        oci_bind_by_name($mail, ':mail_id', $mailId, 40);
        if (!oci_execute($mail, OCI_NO_AUTO_COMMIT)) {
            throw new RuntimeException(oci_error($mail)['message'] ?? 'mail insert failed');
        }
        $details = oci_parse($epp, "INSERT INTO BCS_MAILBOX_EPP_DETAILS (MAIL_ID, EMAIL_TO, EMAIL_CC, EMAIL_BCC)
            VALUES (:mail_id, :email_to, :email_cc, NULL)");
        $toValue = implode(',', $to);
        $ccValue = implode(',', $cc);
        oci_bind_by_name($details, ':mail_id', $mailId);
        oci_bind_by_name($details, ':email_to', $toValue);
        oci_bind_by_name($details, ':email_cc', $ccValue);
        if (!oci_execute($details, OCI_NO_AUTO_COMMIT)) {
            throw new RuntimeException(oci_error($details)['message'] ?? 'mail recipient insert failed');
        }
        return oci_commit($epp);
    } catch (Throwable $e) {
        oci_rollback($epp);
        logOracleError(['message' => $e->getMessage()], 'recruitment mail');
        return false;
    } finally {
        oci_close($epp);
    }
}
