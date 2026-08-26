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

try {
    $capabilities = multiRec(
        "select CAPA_ID, CAPA_CODE, CAPA_DESC
         from HR_CAPABILITIES
         order by CAPA_CODE"
    );

    $results = [];
    foreach ($capabilities as $cap) {
        $results[] = [
            "CAPA_ID" => (int)$cap['CAPA_ID'],
            "CAPA_CODE" => $cap['CAPA_CODE'],
            "CAPA_DESC" => $cap['CAPA_DESC'],
        ];
    }

    apiResponse(true, "Capabilities loaded successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getCapabilitiesList.php"
    );

    apiResponse(false, "Unable to load capabilities.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
