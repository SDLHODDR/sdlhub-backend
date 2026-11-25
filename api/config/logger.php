<?php
require_once 'db.php';
require_once 'session.php';

/*
 * function writeErrorLog($message)
 * {
 *     $sql___func___con = db_eportal();
 *
 *     if (!$sql___func___con) {
 *         return;
 *     }
 *
 *     $user = $_SESSION['emp_code'] ?? 'SYSTEM';
 *
 *     $sql = "BEGIN error_log.write(:perror, :puser); END;";
 *
 *     $stmt = oci_parse($sql___func___con, $sql);
 *
 *     oci_bind_by_name($stmt, ":perror", $message);
 *     oci_bind_by_name($stmt, ":puser", $user);
 *
 *     @oci_execute($stmt);
 *
 *     oci_free_statement($stmt);
 * }
 */

/**
 * Which portal an error belongs to, so it is written to that portal's log
 * (each schema's ERROR_LOG package writes to its own directory):
 *
 *   eportal -> EPORTAL  -> EPORTAL_LOG_DIR
 *   hrms    -> HRMSLIVE -> HRMS_LOG_DIR
 *   epp     -> EPPLIVE  -> EPP_LOG_DIR
 *   teamsdl -> TEAMSDL  -> TEAMSDL_LOG_DIR   (frontend portal key "sfm")
 *
 * Decided in this order:
 *   1. CURRENT_PORTAL, when the endpoint defines it
 *   2. the endpoint's folder, for folders that belong to one portal
 *   3. the X-Portal header the frontend sends (the portal the user is in),
 *      for shared folders such as api/common/ (profile pages used by ePortal and HRMS)
 *   4. ePortal
 */
function resolveLogPortal()
{
    if (defined('CURRENT_PORTAL')) {
        return CURRENT_PORTAL;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $folderPortals = [
        '/api/hrms/'              => 'hrms',
        '/api/careers/'           => 'hrms',     // recruitment / careers site
        '/api/eportal/'           => 'eportal',
        '/api/policyEndorsement/' => 'eportal',  // policy acceptance in ePortal
        '/api/epp/'               => 'epp',
    ];

    foreach ($folderPortals as $folder => $folderPortal) {
        if (strpos($script, $folder) !== false) {
            return $folderPortal;
        }
    }

    $headerPortal = strtolower(trim($_SERVER['HTTP_X_PORTAL'] ?? ''));
    $headerMap = ['eportal' => 'eportal', 'hrms' => 'hrms', 'epp' => 'epp', 'sfm' => 'teamsdl', 'teamsdl' => 'teamsdl'];

    return $headerMap[$headerPortal] ?? 'eportal';
}

/**
 * Write error log into the respective portal database.
 *
 * @param string      $message Error message
 * @param string|null $portal  Portal name; when null it is worked out by resolveLogPortal()
 *
 * Supported portals: eportal, hrms, epp, teamsdl
 */
function writeErrorLog($message, $portal = null)
{
    if ($portal === null) {
        $portal = resolveLogPortal();
    }

    $portal = strtolower(trim($portal));

    /*
     * Portal -> DB mapping
     */
    $portalMap = [
        'eportal' => 'eportal',
        'hrms' => 'hrms',
        'epp' => 'epp',
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
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        );
    }

    $message = (string) $message;

    /*
     * Call Oracle logging procedure.
     */
    $sql = '
        BEGIN
            error_log.write(
                :perror,
                :puser
            );
        END;
    ';

    $stmt = @oci_parse($conn, $sql);

    if (!$stmt) {
        return false;
    }

    oci_bind_by_name($stmt, ':perror', $message, -1);
    oci_bind_by_name($stmt, ':puser', $user, -1);

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

/**
 * Universal login/logout activity logger.
 *
 * @param resource $conn Oracle database connection
 * @param string|null $empCode Employee code
 * @param string $status 'Y' (Success), 'N' (Failed), 'O' (Logout)
 * @param string $appId Application ID (default '01')
 */
function recordUserAuthLog($conn, ?string $empCode, string $status, string $appId = '01'): void
{
    if (!$conn || empty($empCode)) {
        return;
    }

    try {
        $clientIp = getClientIp();

        $sql = '
            INSERT INTO SDLUSERS.SDL_USER_LOGIN_LOG (
                ID,
                EMP_CODE,
                ISSUCCESS,
                IP_ADDR,
                ATTEMPT_ON,
                APP_ID
            ) VALUES (
                SDL_USER_LOGIN_LOG_SEQ.NEXTVAL,
                :emp_code,
                :is_success,
                :ip_addr,
                SYSDATE,
                :app_id
            )
        ';

        $stmt = oci_parse($conn, $sql);
        if ($stmt) {
            oci_bind_by_name($stmt, ':emp_code', $empCode);
            oci_bind_by_name($stmt, ':is_success', $status);
            oci_bind_by_name($stmt, ':ip_addr', $clientIp);
            oci_bind_by_name($stmt, ':app_id', $appId);

            oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
            oci_free_statement($stmt);
        }
    } catch (Throwable $e) {
        // Silently log error so authentication flows are never interrupted
        error_log('Auth log error: ' . $e->getMessage());
    }
}
