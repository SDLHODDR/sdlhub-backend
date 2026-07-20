<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$conn = db_eportal();

require_once __DIR__ . "/../../config/functions.php";

header('Content-Type: application/json');

$empCode = $_SESSION['emp_code'] ?? '';

if (empty($empCode)) {
    apiResponse(false, "Unauthorized access", null, 401);
}

$data = json_decode(file_get_contents("php://input"), true);

$name = trim($data['profileName'] ?? '');
$desc = trim($data['description'] ?? '');

if ($name == '') {
    apiResponse(false, "Profile Name is required.");
}

/* ===========================================
   CHECK DUPLICATE PROFILE
=========================================== */

$sql = "
    SELECT COUNT(*)
    FROM EPT_PROFILES
    WHERE UPPER(PROFILE_DESC) = UPPER(:profile_name)
";

$stmt = oci_parse($conn, $sql);
oci_bind_by_name($stmt, ":profile_name", $name);
oci_execute($stmt);

$row = oci_fetch_array($stmt, OCI_NUM);

if ($row[0] > 0) {
    apiResponse(false, "Profile already exists.");
}

oci_free_statement($stmt);

/* ===========================================
   INSERT PROFILE
=========================================== */

startQry();

$newId = executeQry(
    "
    INSERT INTO EPT_PROFILES
    (
        PROFILE_ID,
        PROFILE_DESC,
        PROFILE_DETAIL,
        STATUS,
        CHG_ON,
        CHG_BY
    )
    VALUES
    (
        NULL,
        '".$name."',
        '".$desc."',
        'A',
        SYSDATE,
        '".$empCode."'
    )
    RETURNING PROFILE_ID INTO :newId
    ",
    "newId"
);

endQry("Profile created successfully.");

echo json_encode([
    "status" => true,
    "message" => "Profile created successfully.",
    "profileId" => $newId
]);

?>