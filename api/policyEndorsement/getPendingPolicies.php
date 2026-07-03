<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";

$con = db_eportal();

header('Content-Type: application/json');

try {

    $empCode = $_SESSION['emp_code'] ?? '00575';

    if (empty($empCode)) {
        echo json_encode([
            "status" => false,
            "message" => "Session expired"
        ]);
        exit;
    }

    /*
    -------------------------------------------------------
    GET EMPLOYEE DIVISION + DEPARTMENT
    -------------------------------------------------------
    */

    $empSql = "
        SELECT
            EMP_CODE,
            DIVSN_ID,
            DEPT_ID
        FROM EPT_HR_EMP_OFFICE_DET 
        WHERE EMP_CODE = :emp_code
    ";

    $empStmt = oci_parse($con, $empSql);
    oci_bind_by_name($empStmt, ":emp_code", $empCode);
    oci_execute($empStmt);

    $employee = oci_fetch_assoc($empStmt);
    if (!$employee) {

        echo json_encode([
            "status" => false,
            "message" => "Employee not found"
        ]);
        exit;
    }

    $divisionId = $employee['DIVSN_ID'];
    $departmentId = $employee['DEPT_ID'];

    /*
    -------------------------------------------------------
    GET PENDING POLICIES
    -------------------------------------------------------
    */

    $sql = "
        SELECT
            P.POLI_ID,
            P.POLICY_NAME,
            P.DOC_PATH,
            P.POLICY_DESC,
            TO_CHAR(P.START_DATE, 'DD-MON-YYYY') START_DATE,
            TO_CHAR(P.END_DATE, 'DD-MON-YYYY') END_DATE
        FROM EPT_HR_POLICY P
        WHERE 
         P.IS_MANDAT = 'Y'
       

        AND SYSDATE BETWEEN P.START_DATE AND P.END_DATE

        AND EXISTS (
            SELECT 1
            FROM EPT_HR_POLICY_DIVSN D
            WHERE D.POLICY_ID = P.POLI_ID
            AND D.DIVSN_ID = :division_id
        )

        AND EXISTS (
            SELECT 1
            FROM EPT_HR_POLICY_DEPT DP
            WHERE DP.POLICY_ID = P.POLI_ID
            AND DP.DEPT_ID = :department_id
        )

        AND NOT EXISTS (
            SELECT 1
            FROM EPT_USER_POLICY_VIEW_LOG L
            WHERE L.POLICY_ID = P.POLI_ID
            AND L.EMP_CODE = :emp_code_2
        )

        ORDER BY P.START_DATE DESC
    ";

 /*
    echo ":division_id: ".$divisionId; 
    echo ":department_id: ".$departmentId;
    echo ":emp_code_2: ".$empCode; 
   */


 /*$sql = "
        SELECT
            P.POLI_ID,
            P.POLICY_NAME,
            P.DOC_PATH,
            P.POLICY_DESC,
            TO_CHAR(P.START_DATE, 'DD-MON-YYYY') START_DATE,
            TO_CHAR(P.END_DATE, 'DD-MON-YYYY') END_DATE
        FROM EPT_HR_POLICY P
        WHERE P.POLI_ID = '11'";   */

    $stmt = oci_parse($con, $sql);

    oci_bind_by_name($stmt, ":division_id", $divisionId);
    oci_bind_by_name($stmt, ":department_id", $departmentId);
    oci_bind_by_name($stmt, ":emp_code_2", $empCode); 

    oci_execute($stmt);

    $policies = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $policies[] = $row;
    }

    echo json_encode([
        "status" => true,
        "count" => count($policies),
        "policies" => $policies
    ]);

} catch (Exception $e) {

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}