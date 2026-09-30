<?php

class Database
{
    private static $connection;

    public static function connect()
    {
        if (!self::$connection) {

            $config = require __DIR__ . '/../config/database.php';
            
            // self::$connection = oci_connect(
            //     $config['username'],
            //     $config['password'],
            //     $config['host'],
            //     $config['charset']
            // );

            self::$connection = oci_connect($config['username'], $config['password'], $config['host']);
            

            if (!self::$connection) {
                $e = oci_error();

                die(json_encode([
                    'success' => false,
                    'where' => 'Database.php',
                    'message' => $e['message']
                ]));
            }
        }

        return self::$connection;
    }
}