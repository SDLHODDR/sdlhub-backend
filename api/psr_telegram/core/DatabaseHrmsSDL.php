<?php

class DatabaseHrmsSDL
{
    private static $connection;

    public static function connect()
    {
        if (!self::$connection) {

            $config = require __DIR__ . '/../config/databasehrmssdl.php';

            self::$connection = oci_connect(
                $config['username'],
                $config['password'],
                $config['host'],
                $config['charset']
            );

            if (!self::$connection) {

                $e = oci_error();

                die(json_encode([
                    'success' => false,
                    'message' => $e['message']
                ]));
            }
        }

        return self::$connection;
    }
}