<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

ob_start();
define('CURRENT_PORTAL', 'hrms');
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

if (!isset($_SESSION['emp_code']) || empty($_SESSION['emp_code'])) {
    apiResponse(
        false,
        "Session expired. Please login again.",
        null,
        401
    );
}


/* ==========================================================
   REQUEST METHOD
========================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(
        false,
        "Invalid request method.",
        null,
        405
    );
}


/* ==========================================================
   READ REQUEST
========================================================== */

$input = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($input)) {
    $input = $_POST;
}


/* ==========================================================
   REQUEST PARAMETERS
========================================================== */

$tabName = strtoupper(
    trim($input['tabName'] ?? '')
);

$id = trim(
    (string)($input['id'] ?? '')
);

$description = trim(
    (string)($input['description'] ?? '')
);


/* ==========================================================
   BASIC VALIDATION
========================================================== */

if ($tabName === '') {
    apiResponse(
        false,
        "Master table name is required.",
        null,
        400
    );
}

if ($description === '') {
    apiResponse(
        false,
        "Description is required.",
        null,
        400
    );
}


/* ==========================================================
   VALIDATE TABLE NAME FORMAT
========================================================== */

/*
 * TAB_NAME comes from HR_MST_TABLES and is later used as
 * a dynamic SQL identifier.
 *
 * Only allow Oracle identifier characters.
 */

if (!preg_match('/^[A-Z][A-Z0-9_$#]*$/', $tabName)) {
    apiResponse(
        false,
        "Invalid master table name.",
        null,
        400
    );
}


/* ==========================================================
   GET MASTER TABLE CONFIGURATION
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

$columns = multiRec(
    $sql,
    [
        ':TAB_NAME' => $tabName
    ]
);


/* ==========================================================
   VALIDATE MASTER CONFIGURATION
========================================================== */

if (empty($columns) || count($columns) < 2) {
    apiResponse(
        false,
        "Invalid master table configuration.",
        null,
        400
    );
}


/* ==========================================================
   GET ID / DESCRIPTION COLUMNS
========================================================== */

$idColumn = strtoupper(
    trim($columns[0]['COL_NAME'] ?? '')
);

$descColumn = strtoupper(
    trim($columns[1]['COL_NAME'] ?? '')
);


if (
    $idColumn === '' ||
    $descColumn === ''
) {
    apiResponse(
        false,
        "Invalid master table column configuration.",
        null,
        400
    );
}


/* ==========================================================
   VALIDATE COLUMN NAMES
========================================================== */

if (
    !preg_match('/^[A-Z][A-Z0-9_$#]*$/', $idColumn) ||
    !preg_match('/^[A-Z][A-Z0-9_$#]*$/', $descColumn)
) {
    apiResponse(
        false,
        "Invalid master table column configuration.",
        null,
        400
    );
}


/* ==========================================================
   GET DESCRIPTION COLUMN SIZE FROM ORACLE
========================================================== */

/*
 * DATA_LENGTH = maximum size in BYTES.
 *
 * This is important because ORA-12899 is reporting:
 *
 * actual: 177
 * maximum: 100
 *
 * Therefore the database column is currently limited to
 * 100 bytes.
 */

$columnSizeSql = "
    SELECT
        DATA_TYPE,
        DATA_LENGTH,
        CHAR_LENGTH,
        CHAR_USED
    FROM USER_TAB_COLUMNS
    WHERE UPPER(TABLE_NAME) = UPPER(:TABLE_NAME)
      AND UPPER(COLUMN_NAME) = UPPER(:COLUMN_NAME)
";

$columnInfo = singRec(
    $columnSizeSql,
    [
        ':TABLE_NAME'  => $tabName,
        ':COLUMN_NAME' => $descColumn
    ]
);


if (empty($columnInfo)) {
    apiResponse(
        false,
        "Unable to determine description column size.",
        null,
        500
    );
}


/* ==========================================================
   DESCRIPTION LENGTH VALIDATION
========================================================== */

$dataType = strtoupper(
    trim($columnInfo['DATA_TYPE'] ?? '')
);

$charUsed = strtoupper(
    trim($columnInfo['CHAR_USED'] ?? '')
);

$dataLength = (int)(
    $columnInfo['DATA_LENGTH'] ?? 0
);

$charLength = (int)(
    $columnInfo['CHAR_LENGTH'] ?? 0
);


/*
 * Oracle VARCHAR2 can be defined using BYTE or CHAR semantics.
 *
 * BYTE:
 *   Validate using strlen() because strlen() returns bytes.
 *
 * CHAR:
 *   Validate using mb_strlen() where available.
 */

if ($dataType === 'VARCHAR2' || $dataType === 'CHAR') {

    if ($charUsed === 'C') {

        $descriptionLength = function_exists('mb_strlen')
            ? mb_strlen($description, 'UTF-8')
            : strlen($description);

        if (
            $charLength > 0 &&
            $descriptionLength > $charLength
        ) {
            apiResponse(
                false,
                "Description cannot exceed {$charLength} characters.",
                [
                    'maxLength' => $charLength,
                    'length'    => $descriptionLength
                ],
                400
            );
        }

    } else {

        /*
         * BYTE semantics.
         */
        $descriptionLength = strlen($description);

        if (
            $dataLength > 0 &&
            $descriptionLength > $dataLength
        ) {
            apiResponse(
                false,
                "Description cannot exceed {$dataLength} bytes.",
                [
                    'maxLength' => $dataLength,
                    'length'    => $descriptionLength
                ],
                400
            );
        }
    }
}


/* ==========================================================
   NORMALIZED DESCRIPTION
========================================================== */

/*
 * Used only for duplicate comparison.
 *
 * Example:
 *
 * Test
 * test
 *  TEST
 * Test
 *
 * are treated as duplicates.
 */

$normalizedDescription = strtoupper(
    trim($description)
);


/* ==========================================================
   DUPLICATE CHECK
========================================================== */

if ($id === '') {

    /* ======================================================
       ADD
    ====================================================== */

    $duplicateSql = "
        SELECT
            COUNT(*) AS CNT
        FROM {$tabName}
        WHERE UPPER(TRIM({$descColumn})) =
              UPPER(TRIM(:DESCRIPTION))
    ";

    $duplicateRecord = singRec(
        $duplicateSql,
        [
            ':DESCRIPTION' => $normalizedDescription
        ]
    );

    $duplicateCount = (int)(
        $duplicateRecord['CNT'] ?? 0
    );

    if ($duplicateCount > 0) {
        apiResponse(
            false,
            "This description already exists.",
            null,
            409
        );
    }

} else {

    /* ======================================================
       UPDATE
    ====================================================== */

    $duplicateSql = "
        SELECT
            COUNT(*) AS CNT
        FROM {$tabName}
        WHERE UPPER(TRIM({$descColumn})) =
              UPPER(TRIM(:DESCRIPTION))
          AND {$idColumn} <> :ID
    ";

    $duplicateRecord = singRec(
        $duplicateSql,
        [
            ':DESCRIPTION' => $normalizedDescription,
            ':ID'          => $id
        ]
    );

    $duplicateCount = (int)(
        $duplicateRecord['CNT'] ?? 0
    );

    if ($duplicateCount > 0) {
        apiResponse(
            false,
            "Another record with this description already exists.",
            null,
            409
        );
    }
}


/* ==========================================================
   UPDATE EXISTING RECORD
========================================================== */

if ($id !== '') {

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


        /* ==================================================
           UPDATE FAILED
        ================================================== */

        if ($result === false) {

            forceRollback(
                "saveMasterData.php UPDATE failed"
            );

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


        /* ==================================================
           SUCCESS
        ================================================== */

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

        forceRollback(
            "saveMasterData.php UPDATE : " .
            $e->getMessage()
        );

        endQry();


        /*
         * Do not expose internal Oracle details to the user.
         */
        apiResponse(
            false,
            "Unable to update master record.",
            null,
            500
        );
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
       INSERT FAILED
    ====================================================== */

    if ($newId === false) {

        forceRollback("saveMasterData.php INSERT failed");

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

    forceRollback(
        "saveMasterData.php INSERT : " .
        $e->getMessage()
    );

    endQry();

    apiResponse(false, "Unable to add master record.", null, 500);
}