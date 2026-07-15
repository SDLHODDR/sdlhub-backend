<?php

ob_start();

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../cors.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/env.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../config/functions.php";
require_once __DIR__ ."/../config/utils.php";

$empCode = $_SESSION['emp_code'] ?? '';

if (!$empCode) {   
	apiResponse(false,"Unauthorized Access",null,401);
}

$documents = [];

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
    WHERE EMP_CODE = '$empCode'
)
ORDER BY HED.CHG_ON DESC
";

$empDocs = multiRec($sql);

if (!empty($empDocs)) {
    foreach ($empDocs as $doc) {
        $documents[] = [
            "docId" => $doc['DOCID'],
            "docType" => $doc['DOCTYP_CODE'],
            "docDesc" => $doc['DOCTYP_DESC'],
            "docDate" => $doc['CHG_ON'],
            "previewUrl" => $_ENV["PUBLIC_PATH"] ."/". $doc['DOC_PATH'],
        ];
    }
}

echo json_encode([
    "status" => true,
    "data" => $documents
]);

exit;
