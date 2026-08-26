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



try {
    startQry();   
    $deptActId = trim(($data['ID'] ?? ''));
    if (!empty($deptActId)) {
        $deptActDesc = trim($data['ACT_DESC'] ?? '');
        $deptActType = trim($data['ACT_TYPE'] ?? '');
        $deptActDeptID = trim($data['DEPT_ID'] ?? '');
        $deptActDispSeq = trim($data['DISP_SEQ'] ?? '');
        
        $escapedDeptActDesc = str_replace("'", "''", $deptActDesc);
        $escapedDeptActType = str_replace("'", "''", $deptActType);
        $escapeddeptActDeptID = str_replace("'", "''", $deptActDeptID);
        $escapedDeptActDispSeq = str_replace("'", "''", $deptActDispSeq);
        $escapedDeptActId = str_replace("'", "''", $deptActId);
        $escapedEmpCode = str_replace("'", "''", $empCode);         

        $updateResult = executeQry(
            "update HR_DEPT_JOEX_ACTIVITY set
                ACT_DESC='" . $escapedDeptActDesc . "',
                DEPT_ID='" . $escapeddeptActDeptID . "',
                ACT_TYPE='" . $escapedDeptActType . "',
                DISP_SEQ='" . $escapedDeptActDispSeq . "',
                CHG_BY='" . $escapedEmpCode . "',
                CHG_ON=sysdate
             where ID='" . $escapedDeptActId . "'"
        );

        if ($updateResult === false) {
            throw new RuntimeException('Unable to update Department Activity.');
        }

        endQry('Updated Successfully!');

        apiResponse(true, "Department Activity updated successfully.", [
            "ID" => $deptActId,
        ]);
        exit;
    } else {
        $exists = singRec(
            "SELECT DISP_SEQ FROM HR_DEPT_JOEX_ACTIVITY WHERE 
            DEPT_ID='" . $data['DEPT_ID'] . "'
            AND ACT_TYPE = '" . $data['ACT_TYPE'] . "' 
            AND DISP_SEQ='" . $data['DISP_SEQ'] . "'"
        );
       
        if (!empty($exists)) {
            endQry('Record Already Exists!');
            apiResponse(false, "Record already exists.", null, 200);
            exit;
        }

        $newActivityId = executeQry(
            "insert into HR_DEPT_JOEX_ACTIVITY (ID, DEPT_ID, ACT_TYPE, DISP_SEQ, ACT_DESC, CHG_ON, CHG_BY)
             values ('', '" . $data['DEPT_ID'] . "', '" . $data['ACT_TYPE'] . "', '" . $data['DISP_SEQ'] . "', '" . $data['ACT_DESC'] . "', sysdate, '" . $empCode . "')
             returning ID into :newActivityId",
            'newActivityId'
        );

        if ($newActivityId === false) {
            //throw new RuntimeException('Unable to insert department activity master.');
            apiResponse(false, "Unable to insert department activity master", null, 200);
        }

        endQry('Saved Successfully');

        apiResponse(
            true,
            "Department Activity saved successfully.",
            ["ID" => (int)$newActivityId]
        );
    }
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "saveDeptActivities.php"
    );

    apiResponse(false, "Unable to save department activity.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}