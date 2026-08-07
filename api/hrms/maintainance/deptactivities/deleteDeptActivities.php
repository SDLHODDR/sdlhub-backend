<?php

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

$deptActivitiesId = trim($data['ID'] ?? ($data['ACT_ID'] ?? $data['aid'] ?? ''));

try {
    if (empty($deptActivitiesId)) {
        apiResponse(false, "Department Activity ID is required.", null, 400);
    }

    startQry();

    startQry();
    $deleted = executeQry("delete from HR_DEPT_JOEX_ACTIVITY where ID='" . $deptActivitiesId . "'");
    

    if ($deleted === false) {
        throw new RuntimeException('Unable to delete activity.');
    }

    endQry('Deleted');

    apiResponse(true, "Department Activity deleted successfully.", []);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "deleteDeptActivities.php"
    );

    apiResponse(false, "Unable to delete activity.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
