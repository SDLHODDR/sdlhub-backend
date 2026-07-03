<?php
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $csrf = $headers['x-csrf-token'] ?? '';

    if (
        empty($csrf) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $csrf)
    ) {
        http_response_code(403);
        echo json_encode([
            "status" => false,
            "message" => "Invalid CSRF token"
        ]);
        exit;
    }
}
