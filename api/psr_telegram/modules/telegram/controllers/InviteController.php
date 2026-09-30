<?php

require_once __DIR__ . '/../services/InviteService.php';
require_once __DIR__ . '/../../../core/Response.php';

class InviteController
{
    private $service;

    public function __construct()
    {
        $this->service = new InviteService();
    }

    public function generate()
    {
        $payload = json_decode(file_get_contents("php://input"), true);

        $result = $this->service->generate(
            $payload['chat_id']
        );

        Response::json(true, 'Invite generated', $result);
    }
}