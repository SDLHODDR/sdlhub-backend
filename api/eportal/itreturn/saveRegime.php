<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* =====================================================
       AUTH CHECK
    ===================================================== */

    if (!isset($_SESSION['emp_code'])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    $empCode = $_SESSION['emp_code'] ?? '';

    /* =====================================================
       INPUT
    ===================================================== */

    $input = json_decode(file_get_contents("php://input"), true);

    $regime = strtoupper(trim($input['regime'] ?? ''));

    if (!in_array($regime, ['N', 'O'])) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid Regime Selected"
        ]);
        exit;
    }

    /* =====================================================
       GET EMPLOYEE ID
    ===================================================== */

    $employee = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE EMP_CODE = '{$empCode}'
    ");

    $empId = $employee['ID'] ?? '';

    if (!$empId) {

        echo json_encode([
            "success" => false,
            "message" => "Employee Not Found"
        ]);
        exit;
    }

    /* =====================================================
       CURRENT FY
    ===================================================== */

    $bcs_acct_period = singRec("
        SELECT *
        FROM EPT_BCS_ACCT_PERIOD
        WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE
    "); 

    if (empty($bcs_acct_period)) {

        echo json_encode([
            "success" => false,
            "message" => "Financial Year Not Found"
        ]);
        exit;
    }

    $fy = $bcs_acct_period['DESCR'] ?? '';

    /* =====================================================
       CHECK EXISTING REGIME
    ===================================================== */

    $existing = singRec("
        SELECT *
        FROM EPT_BCS_ITAX_EMP_REGIME
        WHERE EMP_ID = '{$empId}'
        AND FY = '{$fy}'
    ");

    startQry();

    /* =====================================================
       UPDATE
    ===================================================== */

    if (!empty($existing)) {

        execQry([
            'type'  => 'update',
            'table' => 'EPT_BCS_ITAX_EMP_REGIME',
            'data'  => [
                'REGIME' => $regime,
                'CHG_BY' => $empCode,
                'CHG_ON' => date('d-M-Y')
            ],
            'where' => [
                'EMP_ID' => $empId,
                'FY'     => $fy
            ],
            'print' => 0
        ]);

        $message = "Regime Updated Successfully";

    } else {

        /* =====================================================
           GENERATE ID
        ===================================================== */

        $maxId = singRec("
            SELECT NVL(MAX(ID),0)+1 AS ID
            FROM EPT_BCS_ITAX_EMP_REGIME
        ");

        $id = $maxId['ID'];

        executeQry("
            INSERT INTO EPT_BCS_ITAX_EMP_REGIME
            (
                ID,
                EMP_ID,
                REGIME,
                CHG_ON,
                CHG_BY,
                FY
            )
            VALUES
            (
                '{$id}',
                '{$empId}',
                '{$regime}',
                SYSDATE,
                '{$empCode}',
                '{$fy}'
            )
        ");

        $message = "Regime Saved Successfully";
    }

    endQry();

    /* =====================================================
       RESPONSE
    ===================================================== */

    echo json_encode([
        "success" => true,
        "message" => $message,
        "data" => [
            "EMP_ID" => $empId,
            "FY"     => $fy,
            "REGIME" => $regime
        ]
    ]);

} catch (Exception $e) {

    rollbackQry();

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}