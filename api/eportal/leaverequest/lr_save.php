<?php

require_once "lr_head.php";

if (!isset($data['saveLrData']) || $data['saveLrData'] !== true) {
    apiResponse(false, "Invalid request.", null, 400);
    exit;
}

try {

    startQry();

    /* =========================================================
       1. BASIC VALIDATION
       ========================================================= */

    $empCode = trim($data['EMP_CODE'] ?? '');
    $lveCode = trim($data['LVE_CODE'] ?? '');
    $fromDate = trim($data['LVE_DATE_FR'] ?? '');
    $toDate = trim($data['LVE_DATE_TO'] ?? '');
    $leaveStarts = trim($data['LEAVE_STARTS'] ?? '');
    $leaveEnds = trim($data['LEAVE_ENDS'] ?? '');
    $reason = trim($data['REASON'] ?? '');
    $noDays = $data['NO_DAYS'] ?? 0;

    if ($empCode === '') {
        endQry();

        apiResponse(
            false,
            "Employee code is required.",
            null,
            200
        );
        exit;
    }

    if ($lveCode === '') {
        endQry();

        apiResponse(
            false,
            "Leave Code is required.",
            null,
            200
        );
        exit;
    }

    if ($fromDate === '') {
        endQry();

        apiResponse(
            false,
            "From Date is required.",
            null,
            200
        );
        exit;
    }

    if ($toDate === '') {
        endQry();

        apiResponse(
            false,
            "To Date is required.",
            null,
            200
        );
        exit;
    }

    if ($leaveStarts === '') {
        endQry();

        apiResponse(
            false,
            "Leave Starts is required.",
            null,
            200
        );
        exit;
    }

    if ($leaveEnds === '') {
        endQry();

        apiResponse(
            false,
            "Leave Ends is required.",
            null,
            200
        );
        exit;
    }

    if ((float)$noDays <= 0) {
        endQry();

        apiResponse(
            false,
            "Invalid number of leave days.",
            null,
            200
        );
        exit;
    }

    if ($reason === '') {
        endQry();

        apiResponse(
            false,
            "Reason is required.",
            null,
            200
        );
        exit;
    }


    /* =========================================================
       2. VALIDATE DATE FORMAT
       ========================================================= */

    $fromObj = DateTime::createFromFormat('Y-m-d', $fromDate);
    $toObj = DateTime::createFromFormat('Y-m-d', $toDate);

    if (
        !$fromObj ||
        !$toObj ||
        $fromObj->format('Y-m-d') !== $fromDate ||
        $toObj->format('Y-m-d') !== $toDate
    ) {
        endQry();

        apiResponse(
            false,
            "Invalid leave date format.",
            null,
            200
        );
        exit;
    }

    if ($fromObj > $toObj) {
        endQry();

        apiResponse(
            false,
            "From Date cannot be greater than To Date.",
            null,
            200
        );
        exit;
    }


    /* =========================================================
       3. GET MANAGER
       ========================================================= */

    $name = singRec(
        "SELECT ept_hr_get_emp_mgr('" .
        addslashes($empCode) .
        "', SYSDATE) AS EMP_CODE FROM DUAL"
    );

    $name1 = findParentOrgEmp($empCode);

    $Manager = !empty($name['EMP_CODE'])
        ? $name['EMP_CODE']
        : $name1;

    if (empty($Manager)) {

        endQry();

        apiResponse(
            false,
            "Unable to determine reporting manager.",
            null,
            200
        );
        exit;
    }


    /* =========================================================
       4. MANAGER EMAIL
       ========================================================= */

    $manageremail = singRec(
        "SELECT EMAIL_ID_OFF AS COM_EMAIL
         FROM ept_bcs_employee
         WHERE emp_code = '" . addslashes($Manager) . "'"
    );

    $managerEmail = trim($manageremail['COM_EMAIL'] ?? '');


    /* =========================================================
       5. EMPLOYEE DATA
       ========================================================= */

    $empData = singRec(
        "SELECT DEPT_CODE,
                PROC_GROUP AS PROC_GRP
         FROM ept_bcs_employee
         WHERE emp_code = '" . addslashes($empCode) . "'"
    );


    /* =========================================================
       6. CHECK OVERLAPPING LEAVE
       ========================================================= */

    /*
       Correct overlap condition:

       Existing From <= New To
       AND
       Existing To >= New From

       This handles all possible overlap scenarios.
    */

    $overlapSql = "
        SELECT emp_code
        FROM ept_bcs_emp_leaves_temp
        WHERE emp_code = '" . addslashes($empCode) . "'
          AND status NOT IN ('X', 'R')
          AND lve_date_fr <= TO_DATE('" . addslashes($toDate) . "', 'YYYY-MM-DD')
          AND lve_date_to >= TO_DATE('" . addslashes($fromDate) . "', 'YYYY-MM-DD')

        UNION

        SELECT emp_code
        FROM ept_bcs_emp_leaves
        WHERE emp_code = '" . addslashes($empCode) . "'
          AND lve_date_fr <= TO_DATE('" . addslashes($toDate) . "', 'YYYY-MM-DD')
          AND lve_date_to >= TO_DATE('" . addslashes($fromDate) . "', 'YYYY-MM-DD')
    ";

    $existingLeaves = multiRec($overlapSql);

    if (!empty($existingLeaves) && count($existingLeaves) > 0) {

        endQry();

        apiResponse(
            false,
            "Leave already available for this period.",
            null,
            200
        );
        exit;
    }


    /* =========================================================
       7. OPTIONAL BACKEND CL VALIDATION
       ========================================================= */

    /*
       IMPORTANT:
       Your React validation checks CL monthly usage.
       But backend should also protect the rule.

       CL maximum = 3 applications in a month.
    */

    if ($lveCode === 'CL') {

        $monthStart = $fromObj->format('Y-m-01');
        $monthEnd = $fromObj->format('Y-m-t');

        $clSql = "
            SELECT COUNT(*) AS CL_COUNT
            FROM (
                SELECT LVE_DATE_FR
                FROM EPT_BCS_EMP_LEAVES
                WHERE EMP_CODE = '" . addslashes($empCode) . "'
                  AND LVE_CODE = 'CL'
                  AND STATUS NOT IN ('X', 'R')
                  AND LVE_DATE_FR <= TO_DATE('" . $monthEnd . "', 'YYYY-MM-DD')
                  AND LVE_DATE_TO >= TO_DATE('" . $monthStart . "', 'YYYY-MM-DD')

                UNION ALL

                SELECT LVE_DATE_FR
                FROM EPT_BCS_EMP_LEAVES_TEMP
                WHERE EMP_CODE = '" . addslashes($empCode) . "'
                  AND LVE_CODE = 'CL'
                  AND STATUS NOT IN ('X', 'R')
                  AND LVE_DATE_FR <= TO_DATE('" . $monthEnd . "', 'YYYY-MM-DD')
                  AND LVE_DATE_TO >= TO_DATE('" . $monthStart . "', 'YYYY-MM-DD')
            )
        ";

        $clResult = singRec($clSql);

        $clCount = (int)($clResult['CL_COUNT'] ?? 0);

        if ($clCount >= 3) {

            endQry();

            apiResponse(
                false,
                "CL can not be taken more than thrice in a month and CL can not be more than 2 days!",
                [
                    "validation" => "CL_MONTHLY_LIMIT",
                    "cl_count" => $clCount
                ],
                200
            );
            exit;
        }

        /*
           CL cannot be more than 2 days.
        */

        if ((float)$noDays > 2) {

            endQry();

            apiResponse(
                false,
                "CL can not be taken for more than 2 days!",
                [
                    "validation" => "CL_MAX_DAYS",
                    "no_days" => $noDays
                ],
                200
            );
            exit;
        }
    }


    /* =========================================================
       8. PREPARE DATES
       ========================================================= */

    $LVE_DATE_FR = $fromObj->format('d-M-Y');
    $LVE_DATE_TO = $toObj->format('d-M-Y');


    /* =========================================================
       9. INSERT LEAVE
       ========================================================= */

    $insertSql = "
        INSERT INTO EPT_BCS_EMP_LEAVES_TEMP
        (
            EMP_CODE,
            LVE_DATE_FR,
            LVE_DATE_TO,
            LVE_START_ON,
            LVE_END_ON,
            LVE_CODE,
            TOTAL_DAYS,
            REASON,
            CHG_BY,
            CHG_ON,
            APRVR_ID,
            RAISED_BY,
            STATUS
        )
        VALUES
        (
            '" . addslashes($empCode) . "',

            TO_DATE(
                '" . addslashes($fromDate) . "',
                'YYYY-MM-DD'
            ),

            TO_DATE(
                '" . addslashes($toDate) . "',
                'YYYY-MM-DD'
            ),

            '" . addslashes($leaveStarts) . "',

            '" . addslashes($leaveEnds) . "',

            '" . addslashes($lveCode) . "',

            '" . addslashes($noDays) . "',

            '" . addslashes($reason) . "',

            '" . addslashes($_SESSION['eptPrimaryId'] ?? '') . "',

            SYSDATE,

            '" . addslashes($Manager) . "',

            '" . addslashes($empCode) . "',

            'T'
        )
        RETURNING ID INTO :newId
    ";

    $insert_id = executeQry(
        $insertSql,
        'newId'
    );


    /* =========================================================
       10. VERIFY INSERT
       ========================================================= */

    if (!$insert_id) {

        endQry();

        apiResponse(
            false,
            "Unable to save leave request.",
            null,
            200
        );
        exit;
    }


    /* =========================================================
       11. GET INSERTED RECORD
       ========================================================= */

    $get_temp_leave = singRec(
        "SELECT *
         FROM EPT_BCS_EMP_LEAVES_TEMP
         WHERE ID = '" . addslashes($insert_id) . "'"
    );


    if (empty($get_temp_leave)) {

        endQry();

        apiResponse(
            false,
            "Leave was not saved correctly.",
            null,
            200
        );
        exit;
    }


    /* =========================================================
       12. GENERATE TASK
       ========================================================= */

    $task_id = generateTask(
        'leave_application',
        $insert_id,
        getEmpInfoByCode($empCode) .
        " (" .
        strtoupper($fromDate) .
        " TO " .
        strtoupper($toDate) .
        ")",
        '',
        '',
        '',
        '',
        $Manager
    );


    /* =========================================================
       13. EMAIL
       ========================================================= */

    /*
       IMPORTANT:

       Email should NOT decide whether the leave was saved.

       Even if manager email is rap@sdlindia.com,
       leave must remain successfully saved.
    */

    try {

        if ($managerEmail !== '') {

            $empmail_self = singRec(
                "SELECT com_email
                 FROM EPT_HR_EMPLOYEE_INFO
                 WHERE emp_code = '" . addslashes($empCode) . "'"
            );

            $mailBody =
                'Hi, ' .
                ucwords(strtolower(getEmpInfoByCode($empCode))) .
                ' has sent a leave request, the details are as follows.
                <br><br>

                <b>Leave from Date:</b> ' .
                $get_temp_leave['LVE_DATE_FR'] .
                '<br>

                <b>Leave to Date:</b> ' .
                $get_temp_leave['LVE_DATE_TO'] .
                '<br>

                <b>Total Days:</b> ' .
                $get_temp_leave['TOTAL_DAYS'] .
                '<br>

                <b>Leave Type:</b> ' .
                $get_temp_leave['LVE_CODE'] .
                '<br>

                <b>Reason:</b> ' .
                $get_temp_leave['REASON'] .
                '<br>

                <b>Status:</b> <b>Pending Approval</b>
                <br><br>

                Regards,<br>
                Admin';


            /*
             * Insert mail.
             *
             * DO NOT fail leave saving if this part fails.
             */

            $maild = executeQry(
                "INSERT INTO EPT_BCS_MAILBOX_EPP
                (
                    ID,
                    SUBJECT,
                    MAIL_BODY,
                    ATTACHMENT,
                    STATUS,
                    CHG_ON,
                    CHG_BY,
                    MAIL_DESCR
                )
                VALUES
                (
                    null,
                    'Leave Request of " .
                    addslashes(
                        getEmpInfoByCode($get_temp_leave['EMP_CODE'])
                    ) .
                    " from " .
                    addslashes($get_temp_leave['LVE_DATE_FR']) .
                    " to " .
                    addslashes($get_temp_leave['LVE_DATE_TO']) .
                    "',
                    '" . addslashes(trim($mailBody)) . "',
                    null,
                    'N',
                    SYSDATE,
                    '" . addslashes($empCode) . "',
                    'Leave'
                )
                RETURNING ID INTO :mid",
                'mid'
            );


            if ($maild) {

                executeQry(
                    "INSERT INTO EPT_BCS_MAILBOX_EPP_DETAILS
                    (
                        ID,
                        MAIL_ID,
                        EMAIL_TO,
                        EMAIL_CC,
                        EMAIL_BCC
                    )
                    VALUES
                    (
                        null,
                        '" . addslashes($maild) . "',
                        '" . addslashes(strtolower($managerEmail)) . "',
                        null,
                        null
                    )"
                );
            }
        }

    } catch (Throwable $mailError) {

        /*
         * Log mail error only.
         *
         * DO NOT rollback leave.
         */
        error_log(
            "Leave mail notification failed for ID " .
            $insert_id .
            ": " .
            $mailError->getMessage()
        );
    }


    /* =========================================================
       14. COMMIT / COMPLETE TRANSACTION
       ========================================================= */

    endQry();


    /* =========================================================
       15. SUCCESS RESPONSE
       ========================================================= */

    apiResponse(
        true,
        "Leave Added successfully.",
        [
            "message" => "Leave Added Successfully !!",
            "data" => $get_temp_leave,
            "leave_id" => $insert_id,
            "task_id" => $task_id
        ]
    );

    exit;


} catch (Throwable $e) {

    /*
     * IMPORTANT:
     * If startQry() opened a transaction,
     * rollback should happen here if your helper supports it.
     */

    error_log(
        "Leave Save Error: " .
        $e->getMessage()
    );

    try {
        if (function_exists('rollbackQry')) {
            rollbackQry();
        }
    } catch (Throwable $rollbackError) {
        error_log(
            "Leave Rollback Error: " .
            $rollbackError->getMessage()
        );
    }

    apiResponse(
        false,
        "Unable to apply leaves: " . $e->getMessage(),
        null,
        500
    );

    exit;
}