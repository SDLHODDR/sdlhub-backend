<?php
/*
 * Birthday wishes the logged-in employee received for their latest birthday
 * (old ePortal: cake icon + "You have received birthday wishes" popup in myspace/inc/ui.php).
 *
 * GET -> { count, wishes: [ { from_code, from_name, message, sent_on, profile_image } ] }
 */

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";
require_once __DIR__ . "/birthdayRules.php";

header("Content-Type: application/json");

$empCode = $_SESSION["emp_code"] ?? null;

if (!$empCode) {
    apiResponse(false, "Unauthorized Access", null, 401);
}

session_write_close();

try {

    // Read directly (not multiRec) so messages come back as typed, not HTML-encoded.
    $sql = "
        SELECT
            M.CREATED_BY,
            INITCAP(E.EMP_FNAME || ' ' || E.EMP_LNAME) AS FROM_NAME,
            M.MESSAGE,
            TO_CHAR(M.CREATED_ON, 'DD-Mon-YYYY HH24:MI') AS SENT_ON
        FROM EPT_USER_MESSAGES M
        LEFT JOIN EPT_BCS_EMPLOYEE E
            ON E.EMP_CODE = M.CREATED_BY
        JOIN EPT_BCS_EMPLOYEE ME
            ON ME.EMP_CODE = M.CREATED_FOR
        WHERE M.CREATED_FOR = :emp_code
          -- shown from the birthday itself (not before) for WISH_SHOW_DAYS days
          AND TRUNC(SYSDATE) - " . lastBirthdaySql("ME.BIRTH_DATE") . " BETWEEN 0 AND " . WISH_SHOW_DAYS . "
          -- wishes sent for that birthday, including the advance ones
          AND M.CREATED_ON >= " . lastBirthdaySql("ME.BIRTH_DATE") . " - " . WISH_ADVANCE_DAYS . "
        ORDER BY M.CREATED_ON DESC
    ";

    $stmt = oci_parse($sql___func___con, $sql);

    if (!$stmt) {
        $e = oci_error($sql___func___con);
        logOracleError($e, $sql);
        throw new Exception("Parse failed");
    }

    oci_bind_by_name($stmt, ":emp_code", $empCode);

    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        logOracleError($e, $sql);
        throw new Exception("Query failed");
    }

    $wishes = [];

    while ($row = oci_fetch_assoc($stmt)) {
        $wishes[] = [
            "from_code"     => $row["CREATED_BY"],
            "from_name"     => $row["FROM_NAME"] ?: $row["CREATED_BY"],
            "message"       => $row["MESSAGE"] ?? "",
            "sent_on"       => $row["SENT_ON"],
            "profile_image" => wishProfileUrl($row["CREATED_BY"]),
        ];
    }

    oci_free_statement($stmt);

    apiResponse(true, "Birthday wishes fetched successfully", [
        "count"  => count($wishes),
        "wishes" => $wishes,
    ]);

} catch (Throwable $e) {

    logOracleError($e);

    apiResponse(false, "Unable to fetch birthday wishes.", null, 500);

} finally {

    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}

/* Same lookup as dashboard/birthdays.php */
function wishProfileUrl($empCode)
{
    $publicPath = realpath(__DIR__ . "/../../../../public");

    if (!$publicPath || !preg_match('/^[A-Za-z0-9]+$/', (string)$empCode)) {
        return null;
    }

    $relativePath = "/assets/img/profiles/{$empCode}.jpg";

    return file_exists($publicPath . $relativePath) ? $relativePath : null;
}
