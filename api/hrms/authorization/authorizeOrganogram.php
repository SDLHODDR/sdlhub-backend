<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/validateCsrf.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

$empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

$method = $_SERVER['REQUEST_METHOD'];

$data = json_decode(file_get_contents("php://input"), true) ?? [];

try {
    startQry();

    if ($method === 'POST') {
        $sysTaskId  = intval($data['TASK_ID'] ?? 0);
        $reqId      = intval($data['ID'] ?? 0);
        $tranCode   = intval($data['TRAN_CODE'] ?? 0);
        $decision   = strtoupper(trim($data['decision'] ?? '')); // 'A' (Accept) or 'R' (Reject)
        $remarks    = trim($data['remarks'] ?? '');

        if ($reqId <= 0 || !in_array($decision, ['A', 'R'], true)) {
            apiResponse(false, 'Invalid authorization parameters.', null, 400);
        }

        if ($decision === 'R' && empty($remarks)) {
            apiResponse(false, 'Remarks are required when rejecting a request.', null, 400);
        }

        // Fetch request details
        $chk = singRec("SELECT ID,STATUS FROM HR_ORGANOGRAM WHERE ID ='" .trim($data['TRAN_CODE']). "'");
        if($chk['ID'] == "" || !$chk){
            apiResponse(false, 'Organogram Data not found.', null, 404);
        }

        if (strtoupper($chk['STATUS']) === 'C' || strtoupper($chk['STATUS']) === 'X') {
            apiResponse(false, 'ORganogram request has already been processed.', null, 400);
        }
        
        /* ------------------------------------------------------
            STEP A: IF ACCEPTED, UPDATE ORGANOGRAM (HR_ORGANOGRAM)
            ------------------------------------------------------ */
        $OrgId = executeQry("UPDATE HR_ORGANOGRAM SET 
                    AUTH_BY= '" . $empCode . "',
                    STATUS= '" . $decision . "',
                    AUTH_ON= sysdate
                    WHERE ID='" . $data['TRAN_CODE'] . "'");
        
        if(!$OrgId) {
            apiResponse(false, 'Organogram authorization request not processed.', null, 400);    
        } else {
            $updTaskSql = executeQry("
                UPDATE HR_USER_TASKS
                SET 
                    STATUS  = 'C',
                    AUTH_ON = SYSDATE,
                    AUTH_BY = '" . $empCode . "',
                    REMARKS = '" . $remarks ."'
                WHERE ID = '" . $data['ID'] . "' AND TRAN_CODE = '" . $data['TRAN_CODE'] . "'");
            
            
            if (!$updTaskSql) {
                endQry('Error');
                apiResponse(false, 'Failed to update user task: ', null, 500);
            } else {
                endQry('Updated successfully');
                apiResponse(true, "Organogram processed successfully.", null, 200);
            }
        }
    } else {
        apiResponse(false, "only POST method allowed", null, 500);
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "authorizeOrganogram.php"
    );

    apiResponse(false, "Unable to authorize organogram.", null, 500);
} finally {
    if ($stmt) {
        oci_free_statement($stmt);
    }
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}