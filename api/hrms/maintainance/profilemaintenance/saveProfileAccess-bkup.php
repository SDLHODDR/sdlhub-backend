<?php

ob_start();
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/../../config/env.php";

header("Content-Type: application/json");

try {

    /* ==========================================================
       SESSION
    ========================================================== */

    if (
		!isset($_SESSION["emp_code"]) ||
		empty($_SESSION["emp_code"])
	) {
		apiResponse(
			false,
			"Session expired. Please login again.",
			null,
			401
		);
	}

    /* ==========================================================
       REQUEST
    ========================================================== */

    $request = json_decode(file_get_contents("php://input"), true);

    $profileId = trim($request['profileId'] ?? "");

    if ($profileId == "") {
        apiResponse(false, "Profile is required.");
    }

    $menuIds       = $request['menuIds'] ?? [];
    $subMenuIds    = $request['subMenuIds'] ?? [];
    $companyIds    = $request['companyIds'] ?? [];
    $divisionIds   = $request['divisionIds'] ?? [];
    $departmentIds = $request['departmentIds'] ?? [];
    $taskIds       = $request['taskIds'] ?? [];
    $dashboardIds  = $request['dashboardIds'] ?? [];

    $empCode = $_SESSION['EMP_CODE'];

    oci_execute(oci_parse($sql___func___con, "SAVEPOINT PROFILE_SAVE"));

    /* ==========================================================
       MENU ACCESS
    ========================================================== */

    $sql = "DELETE FROM HR_PROFILE_MENU
            WHERE PROFILE_ID=:profile";

    $stid = oci_parse($sql___func___con,$sql);

    oci_bind_by_name($stid,":profile",$profileId);

    oci_execute($stid,OCI_NO_AUTO_COMMIT);

    foreach($subMenuIds as $subMenu){

        $sql="INSERT INTO HR_PROFILE_MENU
        (
            ID,
            PROFILE_ID,
            SUB_MENU_ID,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            HR_PROFILE_MENU_SEQ.NEXTVAL,
            :profile,
            :submenu,
            'A',
            SYSDATE,
            :emp
        )";

        $stid=oci_parse($sql___func___con,$sql);

        oci_bind_by_name($stid,":profile",$profileId);
        oci_bind_by_name($stid,":submenu",$subMenu);
        oci_bind_by_name($stid,":emp",$empCode);

        oci_execute($stid,OCI_NO_AUTO_COMMIT);
    }

    /* ==========================================================
       COMPANY
    ========================================================== */

    oci_execute(
        oci_parse(
            $sql___func___con,
            "DELETE FROM HR_PROFILE_COMPANY WHERE PROFILE_ID='$profileId'"
        ),
        OCI_NO_AUTO_COMMIT
    );

    foreach($companyIds as $id){

        $sql="INSERT INTO HR_PROFILE_COMPANY
        (
            ID,
            PROFILE_ID,
            COMP_ID,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            HR_PROFILE_COMPANY_SEQ.NEXTVAL,
            :profile,
            :id,
            'A',
            SYSDATE,
            :emp
        )";

        $stid=oci_parse($sql___func___con,$sql);

        oci_bind_by_name($stid,":profile",$profileId);
        oci_bind_by_name($stid,":id",$id);
        oci_bind_by_name($stid,":emp",$empCode);

        oci_execute($stid,OCI_NO_AUTO_COMMIT);

    }

    /* ==========================================================
       DIVISION
    ========================================================== */

    oci_execute(
        oci_parse(
            $sql___func___con,
            "DELETE FROM HR_PROFILE_DIVISIONS WHERE PROFILE_ID='$profileId'"
        ),
        OCI_NO_AUTO_COMMIT
    );

    foreach($divisionIds as $id){

        $sql="INSERT INTO HR_PROFILE_DIVISIONS
        (
            ID,
            PROFILE_ID,
            DIVISION_ID,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            HR_PROFILE_DIVISIONS_SEQ.NEXTVAL,
            :profile,
            :id,
            'A',
            SYSDATE,
            :emp
        )";

        $stid=oci_parse($sql___func___con,$sql);

        oci_bind_by_name($stid,":profile",$profileId);
        oci_bind_by_name($stid,":id",$id);
        oci_bind_by_name($stid,":emp",$empCode);

        oci_execute($stid,OCI_NO_AUTO_COMMIT);

    }

    /* ==========================================================
       DEPARTMENT
    ========================================================== */

    oci_execute(
        oci_parse(
            $sql___func___con,
            "DELETE FROM HR_PROFILE_DEPARTMENT WHERE PROFILE_ID='$profileId'"
        ),
        OCI_NO_AUTO_COMMIT
    );

    foreach($departmentIds as $id){

        $sql="INSERT INTO HR_PROFILE_DEPARTMENT
        (
            ID,
            PROFILE_ID,
            DEPT_ID,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            HR_PROFILE_DEPARTMENT_SEQ.NEXTVAL,
            :profile,
            :id,
            'A',
            SYSDATE,
            :emp
        )";

        $stid=oci_parse($sql___func___con,$sql);

        oci_bind_by_name($stid,":profile",$profileId);
        oci_bind_by_name($stid,":id",$id);
        oci_bind_by_name($stid,":emp",$empCode);

        oci_execute($stid,OCI_NO_AUTO_COMMIT);

    }

    /* ==========================================================
       TASK
    ========================================================== */

    oci_execute(
        oci_parse(
            $sql___func___con,
            "DELETE FROM HR_PROFILE_TASK WHERE PROFILE_ID='$profileId'"
        ),
        OCI_NO_AUTO_COMMIT
    );

    foreach($taskIds as $id){

        $sql="INSERT INTO HR_PROFILE_TASK
        (
            ID,
            PROFILE_ID,
            TASK_ID,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            HR_PROFILE_TASK_SEQ.NEXTVAL,
            :profile,
            :id,
            SYSDATE,
            :emp
        )";

        $stid=oci_parse($sql___func___con,$sql);

        oci_bind_by_name($stid,":profile",$profileId);
        oci_bind_by_name($stid,":id",$id);
        oci_bind_by_name($stid,":emp",$empCode);

        oci_execute($stid,OCI_NO_AUTO_COMMIT);

    }

    /* ==========================================================
       DASHBOARD
    ========================================================== */

    oci_execute(
        oci_parse(
            $sql___func___con,
            "DELETE FROM HR_PROFILE_DASHBOARD WHERE PROFILE_ID='$profileId'"
        ),
        OCI_NO_AUTO_COMMIT
    );

    foreach($dashboardIds as $id){

        $sql="INSERT INTO HR_PROFILE_DASHBOARD
        (
            ID,
            PROFILE_ID,
            DASH_ID,
            STATUS,
            CHG_ON,
            CHG_BY
        )
        VALUES
        (
            HR_PROFILE_DASHBOARD_SEQ.NEXTVAL,
            :profile,
            :id,
            'A',
            SYSDATE,
            :emp
        )";

        $stid=oci_parse($sql___func___con,$sql);

        oci_bind_by_name($stid,":profile",$profileId);
        oci_bind_by_name($stid,":id",$id);
        oci_bind_by_name($stid,":emp",$empCode);

        oci_execute($stid,OCI_NO_AUTO_COMMIT);

    }

    oci_commit($sql___func___con);

    apiResponse(true,"Profile access updated successfully.");

}
catch(Throwable $e){

    oci_rollback($sql___func___con);

    logOracleError($e,__FILE__,__LINE__);

    apiResponse(false,$e->getMessage());

}
finally{

    if(isset($sql___func___con)){
        oci_close($sql___func___con);
    }

}
