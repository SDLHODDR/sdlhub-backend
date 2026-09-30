<?php

// header("Access-Control-Allow-Origin: *");
// header("Content-Type: application/json");
require_once '../../cors.php';
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

[$controllerName, $action] = $route;

require_once "../modules/telegram/controllers/$controllerName.php";

$controller = new $controllerName();

$controller->$action();