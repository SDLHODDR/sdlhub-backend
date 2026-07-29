<?php

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/env.php";

$conn = db_eportal();
$sql___func___con = $conn;

require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ . "/../config/utils.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       DATABASE CONNECTION
    =========================================== */

    if (!$conn) {
        apiResponse(false, "Database connection failed.", null, 500);
    }

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(false, "Unauthorized access.", null, 401);
    }

    /* ===========================================
       FETCH EMPLOYEE DOCUMENTS
    =========================================== */

    $empCodeEsc = str_replace("'", "''", $empCode);

    $sql = "
        SELECT
            HED.EMP_ID,
            HED.DOC_PATH,
            HED.DOC_REF,
            HED.CHG_ON,
            HDT.DOCTYP_DESC,
            HDT.DOCTYP_CODE,
            HED.ID AS DOCID
        FROM EPT_HR_EMP_DOCS HED
        INNER JOIN EPT_HR_DOC_TYPES HDT
            ON HED.DOC_ID = HDT.DOCTYP_ID
        WHERE EMP_ID = (
            SELECT ID
            FROM EPT_HR_EMPLOYEE_INFO
            WHERE EMP_CODE = '{$empCodeEsc}'
        )
        ORDER BY HED.CHG_ON DESC
    ";

    $empDocs = multiRec($sql);

    /* ===========================================
       FORMAT RESPONSE
    =========================================== */

    $documents = [];

    foreach ($empDocs as $doc) {

        $documents[] = [
            "docId"      => $doc["DOCID"],
            "docType"    => $doc["DOCTYP_CODE"],
            "docDesc"    => $doc["DOCTYP_DESC"],
            "docDate"    => $doc["CHG_ON"],
            "previewUrl" => !empty($doc["DOC_PATH"])
                ? rtrim($_ENV["PUBLIC_PATH"], "/") . "/" . ltrim($doc["DOC_PATH"], "/")
                : null
        ];
    }

    apiResponse(true, "Employee documents fetched successfully.", $documents);

} catch (Exception $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getEmployeeDocuments.php"
    );

    apiResponse(false, "Something went wrong while fetching employee documents.", null, 500);

} finally {

    if (!empty($conn)) {
        oci_close($conn);
    }

}