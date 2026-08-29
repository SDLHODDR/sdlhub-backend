<?php
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

$questionId = trim($data['ID'] ?? ($data['QUESTION_ID'] ?? $data['tid'] ?? ''));

try {
    if (empty($questionId)) {
        apiResponse(false, "Question ID is required.", null, 400);
    }

    startQry();

    $escapedQuestionId = str_replace("'", "''", $questionId);
    $deleted = executeQry("update HR_QUESTION_MASTER set STATUS='D' where ID='" . $escapedQuestionId . "'");

    if ($deleted === false) {
        throw new RuntimeException('Unable to delete question.');
    }

    endQry('Deleted');

    apiResponse(true, "Question deleted successfully.", []);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "deleteQuestion.php"
    );

    apiResponse(false, "Unable to delete question.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
