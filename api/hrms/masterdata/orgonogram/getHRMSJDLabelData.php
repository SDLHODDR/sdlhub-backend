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

try {
    $deptId = $data['DEPARTMENT_ID'] ?? null;
    $desigId = $data['DESIGNATION_ID'] ?? null;

    if (!$deptId && !$desigId) {
        apiResponse(false, "Department Id and Designation Id is required", null, 500);
        exit;
    }

    // Only authorized JDs can be linked; an organogram's current JD stays
    // listed so an existing record still shows its value.
    $organogramId = trim((string)($data['ORGANOGRAM_ID'] ?? ''));
    $jdStmt = oci_parse($sql___func___con, "SELECT ID, ID || ' - ' || SH_DESC AS LABEL,
            NVL(STATUS, 'N') AS STATUS, LVL_ID
        FROM HR_JD
        WHERE DEPT_ID = :dept_id AND DESIG_ID = :desig_id
          AND (STATUS = 'A' OR ID = (SELECT O.JD_ID FROM HR_ORGANOGRAM O WHERE O.ID = :org_id))
        ORDER BY SH_DESC");
    $deptIdValue = (string)$deptId;
    $desigIdValue = (string)$desigId;
    oci_bind_by_name($jdStmt, ':dept_id', $deptIdValue);
    oci_bind_by_name($jdStmt, ':desig_id', $desigIdValue);
    oci_bind_by_name($jdStmt, ':org_id', $organogramId);
    oci_execute($jdStmt, OCI_NO_AUTO_COMMIT);
    $organogramJDLbl = [];
    while ($jdRow = oci_fetch_assoc($jdStmt)) {
        if ($jdRow['STATUS'] !== 'A') {
            $jdRow['LABEL'] .= ' (Not Authorized)';
        }
        $organogramJDLbl[] = $jdRow;
    }
    oci_free_statement($jdStmt);
    
    if ( empty($organogramJDLbl) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }

    $results = [];
    foreach ($organogramJDLbl as $org) {
        $results[] = [
            "ID" => (int)$org['ID'],
            "LABEL" => $org['LABEL'],
            // Org Level follows the JD's Band/Level (legacy org_sv.php getOLVL).
            "LVL_ID" => $org['LVL_ID'] ?? null
        ];
    }
    
    apiResponse(true, "Organogram data fetched successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getOrganogramData.php"
    );

    apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
