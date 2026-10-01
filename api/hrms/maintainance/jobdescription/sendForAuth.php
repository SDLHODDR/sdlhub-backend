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

function findParentOrgEmp($empCode)
{
    $office = singRec(
        "SELECT ORG_ID, ORG_LOC_ID
         FROM (
             SELECT ORG_ID, ORG_LOC_ID
             FROM HRMSLIVE.HR_EMP_OFFICE_DET
             WHERE EMP_CODE = '" . addslashes($empCode) . "'
             ORDER BY EFFEC_TO DESC
         )
         WHERE ROWNUM = 1"
    );

    if (empty($office['ORG_ID']) || empty($office['ORG_LOC_ID'])) {
        return '';
    }

    $report = singRec(
        "SELECT PARENT_ORGID, PARENT_LOCID
         FROM HRMSLIVE.HR_ORG_LOC_PARENT
         WHERE ORG_ID = '" . addslashes($office['ORG_ID']) . "'
           AND SYSDATE BETWEEN EFFEC_FROM
               AND NVL(EFFEC_TO, '01-Mar-3000')
           AND ORG_LOC_ID = '" . addslashes($office['ORG_LOC_ID']) . "'"
    );

    if (empty($report['PARENT_ORGID']) || empty($report['PARENT_LOCID'])) {
        return '';
    }

    $orgEmp = singRec(
        "SELECT HO.EMP_CODE
         FROM HRMSLIVE.HR_EMPLOYEE_INFO HO
         INNER JOIN HRMSLIVE.HR_EMP_OFFICE_DET HD
             ON HD.EMP_CODE = HO.EMP_CODE
         WHERE HD.ORG_ID = '" . addslashes($report['PARENT_ORGID']) . "'
           AND HD.ORG_LOC_ID = '" . addslashes($report['PARENT_LOCID']) . "'
           AND HO.STATUS = 'A'"
    );

    return $orgEmp['EMP_CODE'] ?? '';
}

try {

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        $input = [];
    }

    $jobId = trim(
        $input['id'] ??
        $input['ID'] ??
        ''
    );

    if ($jobId === '') {
        apiResponse(
            false,
            "Job description ID is required.",
            null,
            400
        );
        exit;
    }

    /*
     * Get the Job Description.
     */
    $job = singRec(
        "SELECT ID, SH_DESC, DEPT_ID
         FROM HR_JD
         WHERE ID = '" . addslashes($jobId) . "'"
    );

    if (empty($job['ID'])) {
        apiResponse(
            false,
            "Job description not found.",
            null,
            404
        );
        exit;
    }

    /*
     * Employee who is sending the JD for authorization.
     */
    $creatorEmpCode = $_SESSION['emp_code'] ?? '';

    if ($creatorEmpCode === '') {
        apiResponse(
            false,
            "Employee session not found.",
            null,
            401
        );
        exit;
    }

        /*
     * Task 57 = AUTH_JD
     */
    $taskMaster = singRec(
        "SELECT ID
         FROM HR_TASK_MASTER
         WHERE TASK_GRP = 'AUTH_JD'"
    );

    if (empty($taskMaster['ID'])) {
        apiResponse(
            false,
            "Job description authorization task is not configured.",
            null,
            400
        );
        exit;
    }

    /*
     * Prevent duplicate OPEN authorization task.
     */
    $existingTask = singRec(
        "SELECT ID
         FROM HR_USER_TASKS
         WHERE TASK_ID = '" . addslashes($taskMaster['ID']) . "'
           AND TRAN_CODE = '" . addslashes($jobId) . "'
           AND STATUS = 'O'"
    );

    if (!empty($existingTask['ID'])) {
        apiResponse(
            false,
            "This job description is already sent for authorization.",
            ['task_id' => $existingTask['ID']],
            400
        );
        exit;
    }

    /*
     * Get requester's organization details.
     */
    $employeeOffice = singRec(
        "SELECT DIVSN_ID, DEPT_ID
         FROM HRMSLIVE.HR_EMP_OFFICE_DET
         WHERE EMP_CODE = '" . addslashes($creatorEmpCode) . "'
         ORDER BY EFFEC_TO DESC
         FETCH FIRST 1 ROW ONLY"
    );

    $compId = singRec(
        "SELECT COMP_ID
         FROM HRMSLIVE.HR_EMPLOYEE_INFO
         WHERE EMP_CODE = '" . addslashes($creatorEmpCode) . "'"
    );

    $compIdValue = $compId['COMP_ID'] ?? '';
    // $compIdValue = $_SESSION['compId'] ?? '';
    $divIdValue = $employeeOffice['DIVSN_ID'] ?? '';
    $deptIdValue = $employeeOffice['DEPT_ID'] ?? '';

    if ($compIdValue === '' || $divIdValue === '' || $deptIdValue === '') {
        apiResponse(
            false,
            "Employee organization details could not be found.",
            null,
            400
        );
        exit;
    }

    $taskDescription =
        "Job Description " . $jobId .
        " - " . $job['SH_DESC'] .
        " submitted by employee " . $creatorEmpCode .
        " for authorization.";

    /*
 * Create HRMS authorization task.
 *
 * Task ID 57 = Authorise Job Description (JD)
 *
 * HR_USER_TASKS.ID is generated automatically
 * by the BI_HR_USER_TASKS trigger.
 */
$taskInsertSql = "
    INSERT INTO HR_USER_TASKS
    (
        TASK_ID,
        STATUS,
        TRAN_CODE,
        TASK_TYPE,
        TRAN_DESC,
        TASK_GRP_DESC,
        EMP_CODE_FOR,
        COMP_ID,
        DIVSN_ID,
        DEPT_ID,
        CREATED_ON,
        CREATED_BY
    )
    VALUES
    (
        '" . addslashes($taskMaster['ID']) . "',
        'O',
        '" . addslashes($jobId) . "',
        'M',
        '" . addslashes($taskDescription) . "',
        'Authorise Job Description (JD)',
        NULL,
        '" . addslashes($compIdValue) . "',
        '" . addslashes($divIdValue) . "',
        '" . addslashes($deptIdValue) . "',
        SYSDATE,
        '" . addslashes($creatorEmpCode) . "'
    )
";

$taskInsertResult = executeQry($taskInsertSql);
if (!$taskInsertResult) {
    endQry("Unable to insert Job Description authorization task");
    apiResponse(false, "Unable to create the authorization task. Check the server log for the database error.", null, 500);
    exit;
}

/*
 * Get the task ID generated by the trigger.
 */
$createdTask = singRec(
    "SELECT ID
     FROM HR_USER_TASKS
     WHERE TASK_ID = '" . addslashes($taskMaster['ID']) . "'
       AND TRAN_CODE = '" . addslashes($jobId) . "'
       AND STATUS = 'O'
     ORDER BY ID DESC
     FETCH FIRST 1 ROW ONLY"
);

$authTaskId = $createdTask['ID'] ?? '';

if ($authTaskId === '') {
    apiResponse(
        false,
        "Unable to send job description for authorization.",
        null,
        500
    );
    exit;
}

    $statusUpdated = executeQry(
        "UPDATE HR_JD SET STATUS = 'T' WHERE ID = '" . addslashes($jobId) . "'"
    );
    if (!$statusUpdated) {
        endQry('Unable to update Job Description status');
        apiResponse(false, "Task was created but the Job Description status could not be updated.", null, 500);
        exit;
    }

    if (!oci_commit($conn)) {
        $commitError = oci_error($conn);
        oci_rollback($conn);
        logOracleError($commitError ?: ['message' => 'Oracle commit failed'], 'HR_USER_TASKS authorization commit');
        apiResponse(false, "Authorization task could not be committed to the database.", null, 500);
        exit;
    }

apiResponse(
    true,
    "Job description sent for authorization successfully.",
    [
        'id' => $jobId,
        'task_id' => $authTaskId,
        'emp_code_for' => null
    ],
    200
);

exit;

} catch (Throwable $e) {

    error_log(
        "sendForAuth.php ERROR: " .
        $e->getMessage()
    );

    apiResponse(
        false,
        "Unable to send job description for authorization.",
        null,
        500
    );

    exit;
}