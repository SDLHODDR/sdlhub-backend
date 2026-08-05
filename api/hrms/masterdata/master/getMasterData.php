<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

ob_start();

/* ==========================================================
   CONFIG
========================================================== */

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();

/*
 * IMPORTANT:
 * Common DB functions use this global connection.
 */
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";


/* ==========================================================
   SESSION VALIDATION
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
   REQUEST METHOD
========================================================== */

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    apiResponse(
        false,
        "Invalid request method.",
        null,
        405
    );
}


/* ==========================================================
   GET TAB NAME
========================================================== */

$tabName = trim($_GET["tab"] ?? "");

if ($tabName === "") {

    apiResponse(
        false,
        "Master table is required.",
        null,
        400
    );
}


/* ==========================================================
   SECURITY VALIDATION
========================================================== */

/*
 * TAB_NAME is eventually used as an Oracle table name.
 *
 * Bind variables cannot be used for table names.
 * Therefore, strictly validate the value before using it.
 */

$tabName = strtoupper($tabName);

if (!preg_match('/^[A-Z0-9_]+$/', $tabName)) {

    apiResponse(
        false,
        "Invalid master table.",
        null,
        400
    );
}


/* ==========================================================
   GET MASTER TABLE CONFIGURATION
========================================================== */

$sqlColumns = "
    SELECT
        COL_NAME,
        COL_SEQ
    FROM HR_MST_TABLES
    WHERE UPPER(TAB_NAME) = :TAB_NAME
    ORDER BY COL_SEQ
";

$columns = multiRec(
    $sqlColumns,
    [
        ":TAB_NAME" => $tabName
    ]
);


/* ==========================================================
   VALIDATE MASTER CONFIGURATION
========================================================== */

if (
    empty($columns) ||
    count($columns) < 2
) {

    apiResponse(
        false,
        "Master table configuration is invalid.",
        null,
        400
    );
}


/* ==========================================================
   GET ID / DESCRIPTION COLUMNS
========================================================== */

/*
 * Based on HR_MST_TABLES:
 *
 * COL_SEQ = 1 -> ID column
 * COL_SEQ = 2 -> Description column
 */

$idColumn = strtoupper(
    trim($columns[0]["COL_NAME"] ?? "")
);

$descriptionColumn = strtoupper(
    trim($columns[1]["COL_NAME"] ?? "")
);


if (
    $idColumn === "" ||
    $descriptionColumn === ""
) {

    apiResponse(
        false,
        "Master table column configuration is invalid.",
        null,
        400
    );
}


/* ==========================================================
   FETCH MASTER DATA
========================================================== */

/*
 * Column names and table name are already validated /
 * retrieved from HR_MST_TABLES.
 */

$sqlData = "
    SELECT
        {$idColumn} AS MASTER_ID,
        {$descriptionColumn} AS DESCRIPTION
    FROM {$tabName}
    ORDER BY 2
";

$records = multiRec($sqlData);


/* ==========================================================
   FORMAT RESPONSE DATA
========================================================== */

$data = [];

foreach ($records as $row) {

    $data[] = [
        "id" => $row["MASTER_ID"] ?? "",
        "description" => $row["DESCRIPTION"] ?? ""
    ];
}


/* ==========================================================
   SUCCESS RESPONSE
========================================================== */

apiResponse(
    true,
    "Master data fetched successfully.",
    [
        "records" => $data,

        "columns" => [
            "id" => $idColumn,
            "description" => $descriptionColumn
        ]   
    ],
    200
);