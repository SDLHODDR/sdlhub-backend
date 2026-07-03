<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        echo json_encode([
            "status" => false,
            "message" => "Invalid request method"
        ]);
        exit;
    }

    /*
    ============================================
    SESSION VALIDATION
    ============================================
    */

    if (!isset($_SESSION['emp_code'])) {
        apiResponse(false, "Unauthorized Access", null, 401);
        exit;
    }

    $empCode = $_SESSION['emp_code'];

    /*
    ============================================
    GET EMPLOYEE ID
    ============================================
    */

    $empData = singRec("
        SELECT ID
        FROM EPT_BCS_EMPLOYEE
        WHERE emp_code = '{$empCode}'
    ");

    $empId = $empData['ID'] ?? null;

    if (!$empId) {
        echo json_encode([
            "status" => false,
            "message" => "Employee not found"
        ]);
        exit;
    }

    /*
    ============================================
    CURRENT FINANCIAL YEAR
    ============================================
    */

    $bcs_acct_period = singRec("
        SELECT *
        FROM ept_bcs_acct_period
        WHERE SYSDATE BETWEEN fr_date AND to_date
    ");

    $financialYear = $bcs_acct_period['CODE'] ?? '25-26';

    /*
    ============================================
    VALIDATE INPUT
    ============================================
    */

    if (!isset($_POST['OTH_INCOME']) || !is_array($_POST['OTH_INCOME'])) {
        echo json_encode([
            "status" => false,
            "message" => "Other income data missing"
        ]);
        exit;
    }

    /*
    ============================================
    FILE UPLOAD PATH
    ============================================
    
    $uploadDir = __DIR__ . "/../../../../public/assets/img/incometax/" . $financialYear . "/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }*/
        
    $uploadDir = realpath(__DIR__ . "/../../../../public") .
        "/assets/img/incometax/" .
        $financialYear .
        "/" .
        $empCode .
        "/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    /*
    ============================================
    SAVE LOOP
    ============================================
    */

    foreach ($_POST['OTH_INCOME'] as $head_id => $amount) {

        $head_id = trim($head_id);
        $amount = trim($amount);

        /* DELETE OLD RECORD */
        executeQry("
            DELETE FROM EPT_BCS_ITAX_OTHER_INCOME
            WHERE
                emp_id = '{$empId}'
                AND fy = '{$financialYear}'
                AND head_id = '{$head_id}'
        ");

        /* INSERT NEW RECORD */
        executeQry("
            INSERT INTO EPT_BCS_ITAX_OTHER_INCOME
            (
                emp_id,
                head_id,
                amount,
                chg_on,
                chg_by,
                fy
            )
            VALUES
            (
                '{$empId}',
                '{$head_id}',
                '{$amount}',
                SYSDATE,
                '{$empId}',
                '{$financialYear}'
            )
        ");

        /* FILE UPLOAD */

       if (
                isset($_FILES['OTH_INCOME_DOC']['name'][$head_id]) &&
                !empty($_FILES['OTH_INCOME_DOC']['name'][$head_id])
            ) {

                if ($_FILES['OTH_INCOME_DOC']['error'][$head_id] !== UPLOAD_ERR_OK) {
                    throw new Exception("File upload error for head ID: " . $head_id);
                }

                $originalFileName = basename(
                    $_FILES['OTH_INCOME_DOC']['name'][$head_id]
                );

                $newFileName = $empCode . "_" . $head_id . "_" . $originalFileName;

                $targetPath = $uploadDir . $newFileName;

                if (
                    !move_uploaded_file(
                        $_FILES['OTH_INCOME_DOC']['tmp_name'][$head_id],
                        $targetPath
                    )
                ) {
                    throw new Exception(
                        "Failed to upload file for head ID: " . $head_id
                    );
                }

                executeQry("
                    UPDATE EPT_BCS_ITAX_OTHER_INCOME
                    SET agreement_attach = '{$newFileName}'
                    WHERE
                        emp_id = '{$empId}'
                        AND fy = '{$financialYear}'
                        AND head_id = '{$head_id}'
                ");
            }
    }

    endQry();
    /*
    ============================================
    SUCCESS RESPONSE
    ============================================
    */

    echo json_encode([
        "status" => true,
        "message" => "Other income saved successfully"
    ]);
    exit;
} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}