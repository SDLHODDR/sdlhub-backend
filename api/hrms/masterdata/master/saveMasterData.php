<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

ob_start();

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

/* ==========================================================
   SESSION VALIDATION
========================================================== */

if (!isset($_SESSION['emp_code']) ||  empty($_SESSION['emp_code'])) {
    apiResponse(false, "Session expired. Please login again.", null, 401);
}

/* ==========================================================
   REQUEST METHOD
========================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(false, "Invalid request method.", null, 405);
}

/* ==========================================================
   READ REQUEST
========================================================== */

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    $input = $_POST;
}

/* ==========================================================
   REQUEST PARAMETERS
========================================================== */

$tabName = strtoupper(trim($input['tabName'] ?? ''));
$id = trim($input['id'] ?? '');
$description = trim($input['description'] ?? '');

/* ==========================================================
   BASIC VALIDATION
========================================================== */

if ($tabName === '') {
    apiResponse(false, "Master table name is required.", null, 400);
}

if ($description === '') {
    apiResponse(false, "Description is required.", null, 400);
}

/* ==========================================================
   VALIDATE TABLE FROM HR_MST_TABLES
========================================================== */

$sql = "
    SELECT
        TAB_NAME,
        COL_NAME,
        COL_SEQ
    FROM HR_MST_TABLES
    WHERE UPPER(TAB_NAME) = UPPER(:TAB_NAME)
    ORDER BY COL_SEQ
";

$binds = [':TAB_NAME' => $tabName];
$columns = multiRec($sql, $binds);

/* ==========================================================
   VALIDATE MASTER CONFIGURATION
========================================================== */

if ( empty($columns) || count($columns) < 2) {  
    apiResponse(false, "Invalid master table configuration.",  null, 400);
}

/* ==========================================================
   GET ID / DESCRIPTION COLUMNS
========================================================== */

$idColumn = strtoupper(trim($columns[0]['COL_NAME'] ?? ''));
$descColumn = strtoupper(trim($columns[1]['COL_NAME'] ?? ''));

if ($idColumn === '' ||    $descColumn === '' ){
    apiResponse(false, "Invalid master table column configuration.", null, 400);
}

/* ==========================================================
   CHECK DUPLICATE DESCRIPTION
========================================================== */
/*
 * Duplicate comparison is:
 *
 * - case insensitive
 * - leading/trailing spaces ignored
 *
 * Example:
 *
 * "Test"
 * "test"
 * " TEST "
 *
 * are treated as the same value.
 */

/* ==========================================================
   ADD
========================================================== */

if ($id === '') {

    $duplicateSql = "
        SELECT
            COUNT(*) AS CNT
        FROM {$tabName}
        WHERE UPPER(TRIM({$descColumn})) = UPPER(TRIM(:DESCRIPTION))
    ";

    $duplicateRecord = singRec(
        $duplicateSql,
        [
            ':DESCRIPTION' => $description
        ]
    );

    $duplicateCount = (int)($duplicateRecord['CNT'] ?? 0);
    if ($duplicateCount > 0) {
        apiResponse(false, "This description already exists.", null, 409);
    }
}

/* ==========================================================
   UPDATE
========================================================== */

if ($id !== '') {

    /*
     * For UPDATE:
     *
     * Check whether another record already has
     * the same description.
     *
     * Exclude the current record using ID.
     */

    $duplicateSql = "
        SELECT
            COUNT(*) AS CNT
        FROM {$tabName}
        WHERE
            UPPER(TRIM({$descColumn})) = UPPER(TRIM(:DESCRIPTION))
            AND {$idColumn} <> :ID
    ";

    $duplicateRecord = singRec(
        $duplicateSql,
        [
            ':DESCRIPTION' => $description,
            ':ID'          => $id
        ]
    );

    $duplicateCount = (int)($duplicateRecord['CNT'] ?? 0);


    if ($duplicateCount > 0) {

        apiResponse(
            false,
            "Another record with this description already exists.",
            null,
            409
        );
    }


    /* ======================================================
       START UPDATE TRANSACTION
    ====================================================== */

    startQry();


    try {

        $result = execQry([
            'type' => 'update',

            'table' => $tabName,

            'data' => [
                $descColumn => $description,
                'CHG_ON'     => 'SYSDATE',
                'CHG_BY'     => $_SESSION['emp_code']
            ],

            'where' => [
                $idColumn => $id
            ]
        ]);


        if ($result === false) {

            endQry();

            apiResponse(
                false,
                "Unable to update master record.",
                null,
                500
            );
        }


        /* ==================================================
           COMMIT
        ================================================== */

        endQry();


        /* ==================================================
           GET UPDATED RECORD
        ================================================== */

        $updatedSql = "
            SELECT
                {$idColumn} AS ID,
                {$descColumn} AS DESCRIPTION
            FROM {$tabName}
            WHERE {$idColumn} = :ID
        ";

        $updatedRecord = singRec(
            $updatedSql,
            [
                ':ID' => $id
            ]
        );


        apiResponse(
            true,
            "Master record updated successfully.",
            [
                'id'          => $id,
                'description' => $description,
                'record'      => $updatedRecord
            ],
            200
        );

    } catch (Throwable $e) {

        forceRollback("saveMasterData.php UPDATE : " . $e->getMessage());

        endQry();

        apiResponse(false, "Unable to update master record.", null, 500);
    }
}


/* ==========================================================
   INSERT NEW RECORD
========================================================== */

startQry();


try {

    $newId = execQry([
        'type' => 'insert',

        'table' => $tabName,

        'data' => [
            $descColumn => $description,
            'CHG_ON'     => 'SYSDATE',
            'CHG_BY'     => $_SESSION['emp_code']
        ],

        'return' => $idColumn
    ]);

    /* ======================================================
       CHECK INSERT
    ====================================================== */

    if ($newId === false) {

        endQry();

        apiResponse(false, "Unable to add master record.", null, 500);
    }

    /* ======================================================
       COMMIT
    ====================================================== */
    endQry();

    /* ======================================================
       SUCCESS
    ====================================================== */

    apiResponse(
        true,
        "Master record added successfully.",
        [
            'id'          => $newId,
            'description' => $description
        ],
        200
    );

} catch (Throwable $e) {

    forceRollback("saveMasterData.php INSERT : " . $e->getMessage());

    endQry();

    apiResponse(false, "Unable to add master record.", null, 500);
}