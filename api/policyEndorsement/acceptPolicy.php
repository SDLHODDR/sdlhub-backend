<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$con = db_eportal();

$policyId = $data['policy_id'] ?? 0;

try {

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {

        echo json_encode([
            "status" => false,
            "message" => "Session expired"
        ]);
        exit;
    }

    if (empty($policyId)) {

        echo json_encode([
            "status" => false,
            "message" => "Invalid policy"
        ]);
        exit;
    }

    /*
    -------------------------------------------------------
    CHECK ALREADY ACCEPTED
    -------------------------------------------------------
    */

    $checkSql = "
        SELECT COUNT(*) CNT
        FROM EPT_USER_POLICY_VIEW_LOG
        WHERE POLICY_ID = :policy_id
        AND EMP_CODE = :emp_code
    ";

    $checkStmt = oci_parse($con, $checkSql);

    oci_bind_by_name($checkStmt, ":policy_id", $policyId);
    oci_bind_by_name($checkStmt, ":emp_code", $empCode);

    oci_execute($checkStmt);

    $checkRow = oci_fetch_assoc($checkStmt);

    if ($checkRow['CNT'] > 0) {

        echo json_encode([
            "status" => true,
            "message" => "Policy already accepted"
        ]);
        exit;
    }

    /*
    -------------------------------------------------------
    INSERT ACCEPTANCE LOG
    -------------------------------------------------------
    */

    $sql = "
        INSERT INTO EPT_USER_POLICY_VIEW_LOG
        (
            ID,
            POLICY_ID,
            EMP_CODE,
            ACCEPTED_ON,
            IP_ADDR,
            USER_AGENT,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            EPT_USER_POLICY_VIEW_LOG_SEQ.NEXTVAL,
            :policy_id,
            :emp_code,
            SYSDATE,
            :ip_addr,
            :user_agent,
            SYSDATE,
            :chg_by
        )
    ";

    $stmt = oci_parse($con, $sql);

    $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    oci_bind_by_name($stmt, ":policy_id", $policyId);
    oci_bind_by_name($stmt, ":emp_code", $empCode);
    oci_bind_by_name($stmt, ":ip_addr", $ipAddr);
    oci_bind_by_name($stmt, ":user_agent", $userAgent);
    oci_bind_by_name($stmt, ":chg_by", $empCode);

    $result = oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);

    if ($result) {

        echo json_encode([
            "status" => true,
            "message" => "Policy accepted successfully"
        ]);

    } else {

        $e = oci_error($stmt);

        echo json_encode([
            "status" => false,
            "message" => $e['message']
        ]);
    }

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}