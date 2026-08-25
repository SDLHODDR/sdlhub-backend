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

$capaId = trim($data['CAPA_ID'] ?? ($data['ID'] ?? ''));
$capaCode = trim($data['CAPA_CODE'] ?? '');
$capaDesc = trim($data['CAPA_DESC'] ?? '');

try {
    startQry();

    $escapedEmpCode = str_replace("'", "''", $empCode);
    $escapedCapaDesc = str_replace("'", "''", $capaDesc);
    $normalizedCapaCode = str_replace("'", "''", ucfirst(strtolower($capaCode)));

    if (!empty($capaId)) {
        $escapedCapaId = str_replace("'", "''", $capaId);

        $updateResult = executeQry(
            "update HR_CAPABILITIES set
                CAPA_DESC='" . $escapedCapaDesc . "',
                CHG_BY='" . $escapedEmpCode . "',
                CHG_ON=sysdate
             where CAPA_ID='" . $escapedCapaId . "'"
        );

        if ($updateResult === false) {
            throw new RuntimeException('Unable to update capability.');
        }

        endQry('Updated Successfully!');

        apiResponse(true, "Capability updated successfully.", [
            "CAPA_ID" => $capaId,
        ]);
        exit;
    }

    if (empty($normalizedCapaCode)) {
        apiResponse(false, "CAPA_CODE is required.", null, 400);
    }

    $existing = singRec(
        "select CAPA_ID from HR_CAPABILITIES where CAPA_CODE='" . $normalizedCapaCode . "' and NVL(STATUS,'A')!='D'"
    );
    if (!empty($existing)) {
        endQry('Record Already Exists!');
        apiResponse(false, "Record already exists.", null, 200);
        exit;
    }

    $lastCapa = singRec("select max(CAPA_ID) as CAPA_ID from HR_CAPABILITIES");
    $newCapaId = 1;
    if (!empty($lastCapa['CAPA_ID'])) {
        $newCapaId = ((int)$lastCapa['CAPA_ID']) + 1;
    }

    $insertId = executeQry(
        "insert into HR_CAPABILITIES (CAPA_ID, CAPA_CODE, CAPA_DESC, CHG_BY, CHG_ON)
             values (
                 " . $newCapaId . ",
                 '" . $normalizedCapaCode . "',
                 '" . $escapedCapaDesc . "',
                 '" . $escapedEmpCode . "',
                 sysdate
             ) returning CAPA_ID into :newId",
        'newId'
    );

    if ($insertId === false) {
        throw new RuntimeException('Unable to insert capability.');
    }

    endQry('Saved Successfully!');

    apiResponse(true, "Capability saved successfully.", [
        "CAPA_ID" => $insertId,
    ]);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "saveCapabilities.php"
    );

    apiResponse(false, "Unable to save capability.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
