<?php
require_once "db.php";
require_once "session.php";

/*
function writeErrorLog($message)
{
    $sql___func___con = db_eportal();

    if (!$sql___func___con) {
        return;
    }

    $user = $_SESSION['emp_code'] ?? 'SYSTEM';

    $sql = "BEGIN error_log.write(:perror, :puser); END;";

    $stmt = oci_parse($sql___func___con, $sql);

    oci_bind_by_name($stmt, ":perror", $message);
    oci_bind_by_name($stmt, ":puser", $user);

    @oci_execute($stmt);

    oci_free_statement($stmt);
}
*/

/**
 * Write error log into the respective portal database.
 *
 * @param string      $message Error message
 * @param string|null $portal  Database/portal name
 *
 * Supported portals:
 * eportal
 * hrms
 * epp
 * sfm
 */

function writeErrorLog($message, $portal = null)
{
    /*
     * Use explicitly supplied portal if available.
     * Otherwise use CURRENT_PORTAL.
     */
    if ($portal === null) {
        $portal = defined('CURRENT_PORTAL')
            ? CURRENT_PORTAL
            : 'eportal';
    }

    $portal = strtolower(trim($portal));

    /*
     * Portal -> DB mapping
     */
    $portalMap = [
        'eportal' => 'eportal',
        'hrms'    => 'hrms',
        'epp'     => 'epp',
        'teamsdl' => 'sfm',
    ];

    /*
     * Unknown portal
     */
    if (!isset($portalMap[$portal])) {
        return false;
    }

    $dbName = $portalMap[$portal];

    /*
     * Get portal DB connection.
     */
    try {
        $conn = getDBConnection($dbName);
    } catch (Throwable $e) {
        return false;
    }

    if (!$conn) {
        return false;
    }

    /*
     * Logged-in employee.
     */
    $user = $_SESSION['emp_code'] ?? 'SYSTEM';

    /*
     * Convert arrays/objects to string.
     */
    if (is_array($message) || is_object($message)) {
        $message = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    $message = (string)$message;

    /*
     * Call Oracle logging procedure.
     */
    $sql = "
        BEGIN
            error_log.write(
                :perror,
                :puser
            );
        END;
    ";

    $stmt = @oci_parse($conn, $sql);

    if (!$stmt) {
        return false;
    }

    oci_bind_by_name($stmt, ":perror", $message, -1);
    oci_bind_by_name($stmt, ":puser", $user, -1);

    /*
     * Execute.
     */
    $result = @oci_execute($stmt, OCI_NO_AUTO_COMMIT);

    if (!$result) {
        @oci_free_statement($stmt);
        return false;
    }

    /*
     * Commit the log entry.
     */
    $commitResult = @oci_commit($conn);
    @oci_free_statement($stmt);

    return $commitResult;
}
