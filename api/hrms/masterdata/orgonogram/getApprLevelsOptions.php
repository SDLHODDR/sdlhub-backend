<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    $orgId = $data['ID'] ?? null;

    if (!$orgId ) {
        apiResponse(false, "Organogram Id is required", null, 500);
        exit;
    }

    $hierarchyArr = singRec("SELECT get_org_parental('".$orgId."', sysdate) as ORG from dual");
    if ( empty($hierarchyArr) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    } else {
        $orgDet = $hierarchyArr['ORG'] ?? '';
        $result1 = [];
        if (!empty($orgDet)) {
            $entries = explode('#', rtrim($orgDet, '#'));
            $cnt = 1;
            foreach ($entries as $entry) {
                $parts = explode(',', $entry);
                
                if (count($parts) === 2) {
                    $result1[] = [
                        'ORG_ID' => $parts[0],
                        'DESC'   => $parts[1],
                        'LVL'   =>  $cnt,
                    ];
                }
                $cnt++;
            }
        }
        $lvlarr = [];
        foreach($result1 as $lvl)
        {
            $lvlarr[$lvl['LVL']] = $lvl['ORG_ID'];
        }

        $orgIds = implode(',',$lvlarr);
    }
    
    $getAppLevelsOptData = multiRec("SELECT ID,
    ID||' - ' || GET_ORG_NAME(ID) AS NAME
    FROM HR_ORGANOGRAM WHERE ID IN(" . $orgIds . ") ORDER BY ID DESC");
    
    if ( empty($getAppLevelsOptData) ) {
        apiResponse( false, "No Data found", null, 200 );
        exit;
    }
    $results = [];
    foreach ($getAppLevelsOptData as $apprLvl) {
        $results[] = [
            "ID" => (int)$apprLvl['ID'],
            "NAME" => $apprLvl['NAME']
        ];
    }
    apiResponse(true, "Organogram Appraisal data fetched successfully.", $results);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ],
        "getOrganogramData.php"
    );
    apiResponse(false, $e->getMessage(), null, 500);
    //apiResponse(false, "Unable to load organogram.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
?>