<?php

class Router
{
    private $routes = [];

    public function register($method, $route, $action)
    {
        $this->routes[] = [
            'method' => $method,
            'route' => $route,
            'action' => $action
        ];
    }

    public function resolve()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        foreach ($this->routes as $route) {

            if (
                $route['method'] === $requestMethod &&
                $route['route'] === $requestUri
            ) {

                [$controller, $method] = $route['action'];

                require_once $controller;

                $instance = new $method[0];
            }
        }
    }
}