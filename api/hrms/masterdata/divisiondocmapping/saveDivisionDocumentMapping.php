<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

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
   READ BASIC PARAMETERS
========================================================== */

$companyId = trim(
    (string)($input['companyId'] ?? '')
);

$divisionId = trim(
    (string)($input['divisionId'] ?? '')
);

$departmentId = trim(
    (string)($input['departmentId'] ?? '')
);


/* ==========================================================
   SINGLE DESIGNATION
========================================================== */

/*
 * New React page sends:
 *
 * designationId: "284"
 *
 * But we also support the old:
 *
 * designationIds: ["284"]
 */

$designationId = '';

if (
    isset($input['designationId']) &&
    $input['designationId'] !== ''
) {

    $designationId = trim(
        (string)$input['designationId']
    );

} elseif (
    isset($input['designationIds'])
) {

    if (is_array($input['designationIds'])) {

        $designationId = trim(
            (string)($input['designationIds'][0] ?? '')
        );

    } else {

        $designationId = trim(
            (string)$input['designationIds']
        );
    }
}


/* ==========================================================
   DOCUMENT MAPPINGS
========================================================== */

$documentMappings = $input['documentMappings'] ?? [];


/* ==========================================================
   BASIC VALIDATION
========================================================== */

if ($companyId === '') {

    apiResponse(
        false,
        "Company is required.",
        null,
        400
    );
}

if ($divisionId === '') {

    apiResponse(
        false,
        "Division is required.",
        null,
        400
    );
}

if ($departmentId === '') {

    apiResponse(
        false,
        "Department is required.",
        null,
        400
    );
}

if ($designationId === '') {

    apiResponse(
        false,
        "Designation is required.",
        null,
        400
    );
}


/* ==========================================================
   NUMERIC VALIDATION
========================================================== */

if (
    !ctype_digit($companyId) ||
    !ctype_digit($divisionId) ||
    !ctype_digit($departmentId) ||
    !ctype_digit($designationId)
) {

    apiResponse(
        false,
        "Invalid Company, Division, Department or Designation.",
        null,
        400
    );
}


/* ==========================================================
   DOCUMENT MAPPING VALIDATION
========================================================== */

if (!is_array($documentMappings)) {

    apiResponse(
        false,
        "Invalid document mapping data.",
        null,
        400
    );
}

if (empty($documentMappings)) {

    apiResponse(
        false,
        "At least one document mapping is required.",
        null,
        400
    );
}


/* ==========================================================
   NORMALIZE DOCUMENT MAPPINGS
========================================================== */

/*
 * Expected:
 *
 * "documentMappings": {
 *      "1": "35",
 *      "2": "35",
 *      "3": "36"
 * }
 */

$normalizedMappings = [];

foreach ($documentMappings as $docId => $orgLocId) {

    $docId = trim(
        (string)$docId
    );


    /*
     * Support both:
     *
     * "1": "35"
     *
     * and, just in case:
     *
     * "1": {
     *     "value": "35"
     * }
     */

    if (is_array($orgLocId)) {

        $orgLocId = $orgLocId['value'] ?? '';
    }

    $orgLocId = trim(
        (string)$orgLocId
    );


    if ($docId === '') {
        continue;
    }


    if ($orgLocId === '') {

        apiResponse(
            false, 
            "Organization location is required for document ID " . $docId . ".",
            null,
            400
        );
    }


    if (!ctype_digit($docId)) {

        apiResponse(
            false,
            "Invalid document ID: " . $docId,
            null,
            400
        );
    }

    if (!ctype_digit($orgLocId)) {

        apiResponse(
            false,
            "Invalid organization location ID: " . $orgLocId,
            null,
            400
        );
    }

    $normalizedMappings[$docId] = $orgLocId;
}

if (empty($normalizedMappings)) {

    apiResponse(
        false,
        "No valid document mappings were provided.",
        null,
        400
    );
}

/* ==========================================================
   TRANSACTION
========================================================== */

startQry();

try {

    /* ======================================================
       VALIDATE DESIGNATION
    ====================================================== */

    $designationSql = "
        SELECT
            DESI_ID
        FROM HR_DESIGNATION
        WHERE DESI_ID = :DESI_ID
    ";

    $designation = singRec(
        $designationSql,
        [
            ':DESI_ID' => $designationId
        ]
    );

    if (empty($designation)) {

        throw new Exception(
            "Invalid designation selected."
        );
    }

    /* ======================================================
       VALIDATE ORGANIZATION LOCATIONS
    ====================================================== */

    $orgLocationData = [];

    foreach ($normalizedMappings as $docId => $orgLocId) {

        if (isset($orgLocationData[$orgLocId])) {
            continue;
        }

        $orgLocationSql = "
            SELECT
                HL.ID,
                HL.ORG_ID
            FROM HR_ORGANOGRAM HO
            INNER JOIN HR_ORGANOGRAM_LOC HL
                ON HL.ORG_ID = HO.ID
            WHERE HL.ID = :ORG_LOC_ID
        ";


        $orgLocation = singRec(
            $orgLocationSql,
            [
                ':ORG_LOC_ID' => $orgLocId
            ]
        );


        if (empty($orgLocation)) {

            throw new Exception(
                "Invalid organization location selected: " .
                $orgLocId
            );
        }


        $orgId = trim(
            (string)($orgLocation['ORG_ID'] ?? '')
        );


        if ($orgId === '') {

            throw new Exception(
                "Organization ID not found for organization location: " .
                $orgLocId
            );
        }


        $orgLocationData[$orgLocId] = [
            'orgLocId' => $orgLocId,
            'orgId'    => $orgId
        ];
    }


    /* ======================================================
       VALIDATE DOCUMENT TYPES
    ====================================================== */

    foreach ($normalizedMappings as $docId => $orgLocId) {

        $documentSql = "
            SELECT
                DOCTYP_ID
            FROM HR_DOC_TYPES
            WHERE DOCTYP_ID = :DOC_ID
              AND DOC_TYPE = 'D'
        ";


        $document = singRec(
            $documentSql,
            [
                ':DOC_ID' => $docId
            ]
        );


        if (empty($document)) {

            throw new Exception(
                "Invalid document type selected: " .
                $docId
            );
        }
    }


    /* ======================================================
       DELETE EXISTING MAPPINGS
    ====================================================== */

    /*
     * New requirement:     *
     * ONLY ONE DESIGNATION.
     */

    $deleteResult = execQry([
        'type' => 'delete',
        'table' => 'HR_DIVSN_DOC',
        'where' => [
            'COMP_ID'  => $companyId,
            'DEPT_ID'  => $departmentId,
            'DIVSN_ID' => $divisionId,
            'DESI_ID'  => $designationId
        ]
    ]);


    if ($deleteResult === false) {
        throw new Exception(
            "Unable to remove existing document mappings."
        );
    }

    /* ======================================================
       INSERT NEW MAPPINGS
    ====================================================== */

    $insertedCount = 0;

    foreach ($normalizedMappings as $docId => $orgLocId) {

        $orgId = $orgLocationData[$orgLocId]['orgId'];

        $insertResult = execQry([
            'type' => 'insert',
            'table' => 'HR_DIVSN_DOC',
            'data' => [

                'COMP_ID'    => $companyId,
                'DEPT_ID'    => $departmentId,
                'DESI_ID'    => $designationId,
                'DIVSN_ID'   => $divisionId,
                'ORG_LOC_ID' => $orgLocId,
                'ORG_ID'     => $orgId,
                'DOC_ID'     => $docId,
                'CHG_BY'     => $_SESSION['emp_code'],
                'CHG_ON'     => 'SYSDATE'
            ]
        ]);


        if ($insertResult === false) {

            throw new Exception(
                "Unable to save document mapping for document " .
                $docId
            );
        }

        $insertedCount++;
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
        "Division document mapping saved successfully.",
        [
            'companyId'      => $companyId,
            'divisionId'     => $divisionId,
            'departmentId'   => $departmentId,
            'designationId'  => $designationId,
            'documentCount'  => count($normalizedMappings),
            'insertedCount'  => $insertedCount
        ],
        200
    );


} catch (Throwable $e) {

    /* ======================================================
       ROLLBACK
    ====================================================== */

    forceRollback(
        "saveDivisionDocumentMapping.php : " .
        $e->getMessage()
    );

    logOracleError(
        "saveDivisionDocumentMapping.php : " .
        $e->getMessage()
    );

    apiResponse( false, "Unable to save division document mapping.", null, 500);
}