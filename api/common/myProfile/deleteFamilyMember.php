<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

$empCode = $_SESSION['emp_code'] ?? '';

if (!$empCode) {
    apiResponse(false, "Unauthorized access", null, 401);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? '';

if (empty($id)) {
    apiResponse(false, "Family member ID is required");
    exit;
}

try {
    //soft delete
    /*executeQry("
        DELETE FROM EPT_HR_EMP_FAMILY_INFO
        WHERE ID = '".$id."'
        AND EMP_CODE = '".$empCode."'
    ");*/

     executeQry("
            UPDATE EPT_HR_EMP_FAMILY_INFO
            SET               
                STATUS = 'd',
                CHG_ON = SYSDATE,
                CHG_BY = '" . $empCode . "'
            WHERE ID = " . (int)$id . "
        ");

    endQry();

    echo json_encode([
        "status" => true,
        "message" => "Family member deleted successfully"
    ]);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}