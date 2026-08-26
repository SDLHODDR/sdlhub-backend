<?php
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

ob_start();
define('CURRENT_PORTAL', 'hrms');
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* ==========================================================
       SESSION VALIDATION
    ========================================================== */

    // if (!isset($_SESSION["emp_code"])) {
    //     apiResponse(false, "Session expired. Please login again.", null, 401);
    // }

    /* ==========================================================
       DATABASE
    ========================================================== */

    if (!$conn) {
        apiResponse(false, "Unable to connect to HRMS database.", null, 500);
    }

    /* ==========================================================
       ROUTING: GET (list or single), POST (save)
    ========================================================== */

    $method = $_SERVER['REQUEST_METHOD'];

    $action = strtolower($_GET['action'] ?? '');

if ($action === 'accounts') {

    // $sql = "
    //     SELECT ACCT_CODE, DESCR
    //     FROM HR_BCS_ACCOUNTS
    //     WHERE ACTIVE = 'Y'
    //     ORDER BY ACCT_CODE
    // ";

    $sql = "
        SELECT DISTINCT
        m.ACCT_CODE,
        a.DESCR
        FROM HR_BCS_ACCT_CCTR m
        JOIN HR_BCS_ACCOUNTS a
        ON a.ACCT_CODE = m.ACCT_CODE
        WHERE m.STATUS = 'A'
        ORDER BY m.ACCT_CODE";

    $rows = multiRec($sql, $conn);

    apiResponse(
        true,
        "Accounts fetched successfully.",
        ['accounts' => $rows],
        200
    );

    exit;
}

if ($action === 'costcenters') {

    // $sql = "
    //     SELECT CCTR_CODE, DESCR, DEPT_CODE
    //     FROM HR_BCS_COSTCTR
    //     ORDER BY CCTR_CODE
    // ";

    $sql = "
        SELECT DISTINCT
        m.CCTR_CODE,
        c.DESCR
        FROM HR_BCS_ACCT_CCTR m
        JOIN HR_BCS_COSTCTR c
        ON c.CCTR_CODE = m.CCTR_CODE
        WHERE m.STATUS = 'A'
        ORDER BY m.CCTR_CODE";

    $rows = multiRec($sql, $conn);

    apiResponse(
        true,
        "Cost centers fetched successfully.",
        ['costCenters' => $rows],
        200
    );

    exit;
}

    if ($method === 'GET') { //cho "hii "; echo "in the loop"; print_r($_GET); exit;

        // GET /getDepartments.php?id=123  -> single department
        if (!empty($_GET['id'])) {
            $id = $_GET['id'];

            $row = singRec("SELECT DEPT_CODE, DEPT_DESC, SHORT_CODE, ACCT_CODE, CCTR_CODE FROM HR_DEPARTMENT WHERE DEPT_CODE='" . addslashes($id) . "'");

            if (empty($row)) {
                apiResponse(false, "Department not found.", null, 404);
            }

            $department = [
                'DEPT_CODE' => $row['DEPT_CODE'],
                'DEPT_DESC' => $row['DEPT_DESC'],
                'SHORT_CODE' => $row['SHORT_CODE'],
                'ACCT_CODE' => $row['ACCT_CODE'],
                'CCTR_CODE' => $row['CCTR_CODE'],

                'id' => $row['DEPT_CODE'],
                'code' => $row['DEPT_CODE'],
                'name' => $row['DEPT_DESC'],
                'shortName' => $row['SHORT_CODE'],
                'acctCode' => $row['ACCT_CODE'],
                'costCenter' => $row['CCTR_CODE'],
            ];

            apiResponse(true, "Department fetched successfully.", ['department' => $department], 200);
            exit;
        }

        // GET /getDepartments.php  -> list all
        // $sql = "SELECT DEPT_CODE, DEPT_DESC, SHORT_CODE, ACCT_CODE, CCTR_CODE FROM HR_DEPARTMENT ORDER BY DEPT_CODE";

        $sql = "
    SELECT
        DEPT_ID,
        DEPT_DESC,
        DEPT_CODE,
        ACCT_CODE,
        CCTR_CODE,
        SHORT_CODE
    FROM HR_DEPARTMENT
    ORDER BY DEPT_CODE
";
        $deptRows = multiRec($sql, $conn);

        $departments = [];

        foreach ($deptRows as $r) {
            $departments[] = [
                        'DEPT_ID' => $r['DEPT_ID'],
                'DEPT_CODE' => $r['DEPT_CODE'],
                'DEPT_DESC' => $r['DEPT_DESC'],
                'SHORT_CODE' => $r['SHORT_CODE'],
                'ACCT_CODE' => $r['ACCT_CODE'],
                'CCTR_CODE' => $r['CCTR_CODE'],

                'id' => $r['DEPT_CODE'],
                'code' => $r['DEPT_CODE'],
                'name' => $r['DEPT_DESC'],
                'shortName' => $r['SHORT_CODE'],
                'acctCode' => $r['ACCT_CODE'],
                'costCenter' => $r['CCTR_CODE'],
            ];
        }

        apiResponse(true, "Departments fetched successfully.", ['departments' => $departments], 200);
        exit;
    }

    elseif ($method === 'POST') {

        // Read JSON body if present
        $input = [];
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json)) $input = $json;
        }

        // Merge with $_POST for form-encoded requests
        $input = array_merge($input, $_POST);

        // Accept multiple field names used by UI or older code
        $id = $input['ID'] ?? $input['DEPT_CODE'] ?? null;
        $descr = $input['description'] ?? $input['DESCR'] ?? $input['DEPT_DESC'] ?? $input['DEPT_NAME'] ?? '';
        $short = $input['short_name'] ?? $input['SHORT_CODE'] ?? '';
        $acct = $input['acctCode'] ?? $input['ACCT_CODE'] ?? $input['ACCT'] ?? '';
        $cctr = $input['costCenter'] ?? $input['CCTR_CODE'] ?? $input['CCTR'] ?? '';

        $action = strtolower($input['action'] ?? '');

        // Normalize description as per existing logic
        // $descr_db = htmlspecialchars(ucwords(strtolower(trim((string)$descr))), ENT_QUOTES);
        $descr_db = htmlspecialchars(
    trim((string)$descr),
    ENT_QUOTES
);

        // Start DB transaction
        startQry();

        $loginId = $_SESSION['loginId']
    ?? $_SESSION['emp_code']
    ?? 'SYSTEM';

    if ($action === 'delete') {

    if (empty($id)) {
        apiResponse(false, "Department ID is required.", null, 400);
    }

    startQry();

    $sql = "DELETE FROM HR_DEPARTMENT
            WHERE DEPT_CODE='" . addslashes($id) . "'";

    $ok = executeQry($sql);

    if ($ok) {
        endQry('Deleted');
        apiResponse(true, "Department deleted successfully.", ['id' => $id], 200);
    } else {
        endQry();
        apiResponse(false, "Unable to delete department.", null, 500);
    }

    exit;
}
        if (!empty($id)) {
            // Update existing
            $sql = "UPDATE HR_DEPARTMENT SET
                        DEPT_DESC='" . addslashes($descr_db) . "',
                        ACCT_CODE='" . addslashes($acct) . "',
                        CCTR_CODE='" . addslashes($cctr) . "',
                        SHORT_CODE='" . addslashes($short) . "',
                        CHG_ON=SYSDATE,
                        CHG_BY='" . addslashes($loginId) ."'
                    WHERE DEPT_CODE='" . addslashes($id) . "'";

            $ok = executeQry($sql);

            if ($ok) {
                endQry('Updated');
                apiResponse(true, "Department updated successfully.", ['id' => $id], 200);
            } else {
                // rollback handled by endQry
                endQry();
                apiResponse(false, "Unable to update department.", null, 500);
                $e = oci_error($sql___func___con);

                print_r($e);

                exit;
            }

            exit;
        }

        // Insert new
        $last = singRec("SELECT MAX(DEPT_CODE) AS DEPT_CODE FROM HR_DEPARTMENT");

        if (empty($last) || $last['DEPT_CODE'] === '') {
            $new_code = '1';
        } else {
            // preserve numeric increment behaviour
            $new_code = (string)(intval($last['DEPT_CODE']) + 1);
        }

        $loginId = $_SESSION['loginId']
    ?? $_SESSION['emp_code']
    ?? 'SYSTEM';

        $sql = "INSERT INTO HR_DEPARTMENT (DEPT_CODE,DEPT_DESC,ACCT_CODE,CCTR_CODE,SHORT_CODE,CHG_ON,CHG_BY)
                VALUES('".addslashes($new_code)."',
                       '".addslashes($descr_db)."',
                       '".addslashes($acct)."',
                       '".addslashes($cctr)."',
                       '".addslashes($short)."',
                       SYSDATE,
                       '".addslashes($loginId)."')";

        $ok = executeQry($sql);

        if ($ok) {
            endQry('Inserted');
            apiResponse(true, "Department inserted successfully.", ['id' => $new_code], 201);
        } else {
            endQry();
            apiResponse(false, "Unable to insert department.", null, 500);
        }

        exit;
    }

    else {
        apiResponse(false, "Unsupported HTTP method.", null, 405);
    }

} catch (Throwable $e) {

    /* ==========================================================
       LOG ERROR
    ========================================================== */

    logOracleError($e);

    /* ==========================================================
       GENERIC ERROR RESPONSE
    ========================================================== */

    apiResponse(false, "Unable to process request.", null, 500);
}
