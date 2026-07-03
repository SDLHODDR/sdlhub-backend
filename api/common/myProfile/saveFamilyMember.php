<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

$empCode = $_SESSION['emp_code'] ?? '';

if (!$empCode) {
    apiResponse(false, "Unauthorized access", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode([
        "status" => false,
        "message" => "Invalid input"
    ]);
    exit;
}

$id         = $data['id'] ?? null;
$name       = trim($data['name'] ?? '');
$relation   = trim($data['relation'] ?? '');
$dob        = trim($data['dob'] ?? '');
$aadhaar    = trim($data['aadhaar'] ?? '');
$dependent  = trim($data['dependent'] ?? '');
$occupation = trim($data['occupation'] ?? '');

$age = null;

if (!empty($dob)) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

if (!$name || !$relation) {
    echo json_encode([
        "status" => false,
        "message" => "Required fields missing"
    ]);
    exit;
}

try {

    /* =========================================
       UPDATE
    ========================================= */

    if (!empty($id)) {
        $sql = "
            UPDATE EPT_HR_EMP_FAMILY_INFO
            SET
                FM_NAME = '" . ucfirst($name) . "',
                FM_RELATION = '" . $relation . "',
                DOB = TO_DATE('" . strtoupper($dob) . "', 'DD-MON-YYYY'),
                AADHAAR = '" . $aadhaar . "',
                FM_DEP = '" . $dependent . "',
                FM_OCCUPATION = '" . $occupation . "',
                AGE = '" . $age . "',
                CHG_ON = SYSDATE,
                CHG_BY = '" . $empCode . "'
            WHERE ID = " . (int)$id . "
        ";

        executeQry($sql);
        if ($qry_____result == 0) {
            endQry();
            echo json_encode([
                "status" => true,
                "message" => "Family member updated successfully"
            ]);

        } else {
            forceRollback("Update query failed");
            echo json_encode([
                "status" => false,
                "message" => "Failed to update family member"
            ]);
        }
    }

    /* =========================================
       INSERT
    ========================================= */

    else {

        $sql = "
            INSERT INTO EPT_HR_EMP_FAMILY_INFO
            (
                EMP_CODE,
                FM_NAME,
                FM_RELATION,
                FM_DEP,
                DOB,
                AADHAAR,
                FM_OCCUPATION,
                AGE,
                CHG_ON,
                CHG_BY
            )
            VALUES
            (
                '" . $empCode . "',
                '" . ucfirst($name) . "',
                '" . $relation . "',
                '" . $dependent . "',
                TO_DATE('" . strtoupper($dob) . "', 'DD-MON-YYYY'),
                '" . $aadhaar . "',
                '" . $occupation . "',
                '" . $age . "',
                SYSDATE,
                '" . $empCode . "'
            )
        ";
        
        executeQry($sql);
        if ($qry_____result == 0) {
            endQry();
            echo json_encode([
                "status" => true,
                "message" => "Family member added successfully"
            ]);

        } else {
            forceRollback("Insert query failed");
            echo json_encode([
                "status" => false,
                "message" => "Failed to add family member"
            ]);
        }
    }

} catch (Exception $e) {
    forceRollback("Query execution failed");
    echo json_encode([
        "status" => false,
      "message" => $e->getMessage()
    ]);
}