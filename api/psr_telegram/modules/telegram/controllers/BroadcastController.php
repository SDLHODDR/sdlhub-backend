<?php

require_once __DIR__ . '/../services/BroadcastService.php';
require_once __DIR__ . '/../../../core/Response.php';

class BroadcastController
{
    private $service;

    public function __construct()
    {
        $this->service = new BroadcastService();
    }

    public function create()
    {
        $payload = json_decode(file_get_contents("php://input"), true);

        $this->service->create($payload);

        Response::json(true, 'Broadcast queued');
    }
}