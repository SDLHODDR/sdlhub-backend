<?php
/*
 * GET. Called by the frontend while the user is active (SessionTimeoutGuard.jsx).
 * This is the only request that extends the session; see config/session.php.
 */
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config/session.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['emp_code'])) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Session expired.']);
    exit;
}

$_SESSION['last_activity'] = time();
session_write_close();

echo json_encode(['status' => true, 'idle_timeout' => SESSION_IDLE_TIMEOUT]);
