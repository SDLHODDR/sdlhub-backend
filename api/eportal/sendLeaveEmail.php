<?php
require_once "../config/session.php";
require_once "../config/cors.php";
require_once "../config/db_oracle.php";
require_once "../config/validateCsrf.php";
require_once __DIR__."/../../config/utils.php";

header("Content-Type: application/json");

if (!isset($_SESSION['ept']['eptEmpCode'])) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$data = json_decode(file_get_contents("php://input"), true);
$LEAVE_ID = $data['leave_id'] ?? 0;

if (!$LEAVE_ID) {
    echo json_encode(["status" => false, "message" => "Invalid leave id"]);
    exit;
}

/* -------------------------------------------------
   1️ GET LEAVE DETAILS
------------------------------------------------- */

$sql = "
SELECT t.*, e.emp_name
FROM ept_bcs_emp_leaves_temp t
JOIN ept_hr_employee_info e ON e.emp_code = t.emp_code
WHERE t.id = :id
";

$stmt = oci_parse($conn, $sql);
oci_bind_by_name($stmt, ":id", $LEAVE_ID);
oci_execute($stmt);

$leave = oci_fetch_assoc($stmt);

if (!$leave) {
    echo json_encode(["status" => false, "message" => "Leave not found"]);
    exit;
}

/* -------------------------------------------------
   2️ GET MANAGER EMAIL
------------------------------------------------- */

$mgrSql = "
SELECT email_id_off AS com_email
FROM ept_bcs_employee
WHERE emp_code = :mgr
";

$stmt = oci_parse($conn, $mgrSql);
oci_bind_by_name($stmt, ":mgr", $leave['APRVR_ID']);
oci_execute($stmt);

$mgr = oci_fetch_assoc($stmt);

if (!$mgr || !$mgr['COM_EMAIL']) {
    echo json_encode(["status" => false, "message" => "Manager email not found"]);
    exit;
}

/* -------------------------------------------------
   3️ BUILD EMAIL
------------------------------------------------- */

$subject = "Leave Request of {$leave['EMP_NAME']}";

$mailBody = "
Hi,<br><br>

<b>{$leave['EMP_NAME']}</b> has applied for leave.<br><br>

<b>Leave From:</b> {$leave['LVE_DATE_FR']}<br>
<b>Leave To:</b> {$leave['LVE_DATE_TO']}<br>
<b>Total Days:</b> {$leave['TOTAL_DAYS']}<br>
<b>Leave Type:</b> {$leave['LVE_CODE']}<br>
<b>Reason:</b> {$leave['REASON']}<br>
<b>Status:</b> Pending Approval<br><br>

Regards,<br>
Admin
";

/* -------------------------------------------------
   4️ INSERT INTO MAIL QUEUE
------------------------------------------------- */

$mailSql = "
INSERT INTO bcs_mailbox_epp
(
 ID, SUBJECT, MAIL_BODY, ATTACHMENT,
 STATUS, CHG_ON, CHG_BY, MAIL_DESCR
)
VALUES
(
 NULL,
 :subject,
 :body,
 NULL,
 'N',
 SYSDATE,
 :chg_by,
 'Leave'
)
RETURNING ID INTO :mid
";

$stmt = oci_parse($conn, $mailSql);

oci_bind_by_name($stmt, ":subject", $subject);
oci_bind_by_name($stmt, ":body", $mailBody);
oci_bind_by_name($stmt, ":chg_by", $_SESSION['ept']['eptEmpCode']);
oci_bind_by_name($stmt, ":mid", $MAIL_ID, 32);

$r = oci_execute($stmt, OCI_NO_AUTO_COMMIT);

if (!$r) {
    oci_rollback($conn);
    echo json_encode(["status" => false, "message" => "Mail insert failed"]);
    exit;
}

/* -------------------------------------------------
   5️ MAIL RECEIVER
------------------------------------------------- */

$detailSql = "
INSERT INTO bcs_mailbox_epp_details
(
 ID, MAIL_ID, EMAIL_TO, EMAIL_CC, EMAIL_BCC
)
VALUES
(
 NULL,
 :mail_id,
 :email_to,
 NULL,
 NULL
)
";

$stmt = oci_parse($conn, $detailSql);
oci_bind_by_name($stmt, ":mail_id", $MAIL_ID);
oci_bind_by_name($stmt, ":email_to", $mgr['COM_EMAIL']);
oci_execute($stmt);

oci_commit($conn);

echo json_encode([
    "status" => true,
    "message" => "Email queued successfully"
]);
