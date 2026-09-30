<?php

class Response
{
    public static function json($success, $message, $data = [])
    {
        header('Content-Type: application/json');

        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);

        exit;
    }
}