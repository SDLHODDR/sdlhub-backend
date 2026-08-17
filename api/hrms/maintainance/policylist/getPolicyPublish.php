<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true);
if (empty($data)) {
    $data = $_POST;
}
// ---------------------------------------------------------
// Collect + validate input (server-side; client validation
// in usePolicyHandler.validateForm() can be bypassed)
// ---------------------------------------------------------
$policyId   = trim($data['ID'] ?? '');

// ---------------------------------------------------------
// Save (transaction: HR_POLICY + HR_POLICY_DEPT + HR_POLICY_DIVSN)
// ---------------------------------------------------------
try {
    startQry();
    if ($policyId !== '') {
        // ---- UPDATE ----

        $polyR = executeQry("UPDATE HR_POLICY set
                            STATUS='A'
					where POLI_ID='" . $policyId . "'");
        endQry();
        if($polyR){
            apiResponse(
                true,
                "Policy Published successfully",
                [
                    "POLI_ID" => $policyId,
                    "STATUS" => "A"
                ]
            );
        } else
        {
            apiResponse(false, "Error occured", null, 200);
        }
    } else {
        apiResponse(false, "Policy Id Required", null, 200);
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getPolicyPUblish.php"
    );

    apiResponse(false, "Unable to update Policy.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}