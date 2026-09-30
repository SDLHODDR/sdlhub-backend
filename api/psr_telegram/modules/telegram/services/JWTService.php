<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTService
{
    private static $secret = 'SECRET_KEY';

    public static function generate($user)
    {
        $payload = [

            'id' => $user['id'],

            'username' => $user['username'],

            'iat' => time(),

            'exp' => time() + 86400
        ];

        return JWT::encode(
            $payload,
            self::$secret,
            'HS256'
        );
    }

    public static function verify($token)
    {
        return JWT::decode(
            $token,
            new Key(self::$secret, 'HS256')
        );
    }
}