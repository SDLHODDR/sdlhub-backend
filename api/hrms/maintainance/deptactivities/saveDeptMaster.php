<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);
define('CURRENT_PORTAL', 'hrms');
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



try {
    startQry();   
    $deptDesc = trim(($data['DEPT_DESC'] ?? ''));
    if (!empty($deptDesc)) {
        $DIdCHK = singRec("select DEPT_ID from HR_DEPARTMENT where DEPT_DESC='" . $data['DEPT_DESC'] . "' ");
        if ($DIdCHK) {
            endQry("Record Already Exists!");
            apiResponse(false, "Record Already Exists", null, 200);
            exit;
        } else {
            $dptId = executeQry("insert into HR_DEPARTMENT(DEPT_ID,DEPT_DESC,CHG_BY,CHG_ON)
                        values (
                        '',
                        '" . trim($data['DEPT_DESC']) . "',
                        '" . trim($empCode) . "',
                        sysdate ) returning  DEPT_ID into :dptId ", 'dptId');
        }

        endQry('Saved Successfully');
        apiResponse(
            true,
            "Department Master saved successfully.",
            ["DEPT_ID" => (int)$dptId]
        );
    } else {
        apiResponse(false, "Department Description required.", null, 200);
        exit;
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "saveDeptActivities.php"
    );

    apiResponse(false, "Unable to save department activity.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}