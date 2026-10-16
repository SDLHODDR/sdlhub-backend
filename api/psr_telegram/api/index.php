<?php

// header("Access-Control-Allow-Origin: *");
// header("Content-Type: application/json");
require_once '../../cors.php';
require_once '../../config/session.php';
require_once '../core/Response.php';
//echo "=================="; exit;

$routes = require 'routes.php';

$method = $_SERVER['REQUEST_METHOD'];

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/*
Dynamically remove current API base path
*/
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$uri = "/api" . str_replace($scriptName, '', $uri);

$route = $routes[$method][$uri] ?? null;

if (!$route) {
    Response::json(false, 'Route not found');
}

$internalApiKey = getenv('TELEGRAM_INTERNAL_API_KEY') ?: ($_ENV['TELEGRAM_INTERNAL_API_KEY'] ?? $_SERVER['TELEGRAM_INTERNAL_API_KEY'] ?? '');
$webhookSecret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: ($_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? $_SERVER['TELEGRAM_WEBHOOK_SECRET'] ?? '');

if ($uri === '/api/telegram/members/group-joined') {
    $providedKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    if ($internalApiKey === '' || !hash_equals($internalApiKey, $providedKey)) {
        http_response_code(401);
        Response::json(false, 'Unauthorized service callback');
    }
} elseif ($uri === '/api/telegram/webhook') {
    $providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($webhookSecret === '' || !hash_equals($webhookSecret, $providedSecret)) {
        http_response_code(401);
        Response::json(false, 'Unauthorized Telegram webhook');
    }
} else {
    $employeeCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
    if ($employeeCode === '') {
        http_response_code(401);
        Response::json(false, 'Unauthorized access');
    }

    require_once '../../config/validateCsrf.php';
}

[$controllerName, $action] = $route;

require_once "../modules/telegram/controllers/$controllerName.php";

$controller = new $controllerName();

$controller->$action();