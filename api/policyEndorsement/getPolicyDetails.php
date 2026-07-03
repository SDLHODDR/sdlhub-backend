<?php

ini_set('display_errors',1);
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

$policyId = $_GET['policy_id'] ?? 0;
$policyId = 13;

try {

    $sql = "
        SELECT
            POLI_ID,
            POLICY_NAME,
            DOC_PATH,
            POLICY_DESC,
            TO_CHAR(START_DATE, 'DD-MON-YYYY') START_DATE,
            TO_CHAR(END_DATE, 'DD-MON-YYYY') END_DATE,
            IS_MANDATORY,
            PUBLISH
        FROM EPT_HR_POLICY
        WHERE POLI_ID = :policy_id
    ";

    $stmt = oci_parse($con, $sql);

    oci_bind_by_name($stmt, ":policy_id", $policyId);

    oci_execute($stmt);

    $policy = oci_fetch_assoc($stmt);

    if (!$policy) {

        echo json_encode([
            "status" => false,
            "message" => "Policy not found"
        ]);
        exit;
    }

    echo json_encode([
        "status" => true,
        "policy" => $policy
    ]);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}