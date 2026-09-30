<?php

require_once '../services/JWTService.php';

class AuthMiddleware
{
    public static function handle()
    {
        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {

            Response::json(
                false,
                'Unauthorized'
            );
        }

        $token =
            str_replace(
                'Bearer ',
                '',
                $headers['Authorization']
            );

        try {

            JWTService::verify($token);

        } catch (Exception $e) {

            Response::json(
                false,
                'Invalid token'
            );
        }
    }
}