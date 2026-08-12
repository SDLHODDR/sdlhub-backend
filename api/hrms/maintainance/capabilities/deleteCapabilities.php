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

$capaId = trim($data['CAPA_ID'] ?? ($data['ID'] ?? ''));

try {
    if (empty($capaId)) {
        apiResponse(false, "CAPA_ID is required.", null, 400);
    }

    startQry();

    $escapedCapaId = str_replace("'", "''", $capaId);
    $updateResult = executeQry(
        "update HR_CAPABILITIES set STATUS='D', CHG_BY='" . str_replace("'", "''", $empCode) . "', CHG_ON=sysdate
         where CAPA_ID='" . $escapedCapaId . "'"
    );

    if ($updateResult === false) {
        throw new RuntimeException('Unable to delete capability.');
    }

    endQry('Deleted');

    apiResponse(true, "Capability deleted successfully.", []);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "deleteCapabilities.php"
    );

    apiResponse(false, "Unable to delete capability.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
