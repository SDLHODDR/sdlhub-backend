<?php

require_once __DIR__ . '/../repositories/TelegramCountRepository.php';
require_once __DIR__ . '/../../../core/Response.php';

class TelegramCountController
{
    private $repository;

    public function __construct()
    {
        $this->repository = new TelegramCountRepository();
    }

    public function getCounts()
    {
        try {
            $counts = $this->repository->getCounts();
            Response::json(true, 'Telegram counts fetched', $counts);
        } catch (Throwable $error) {
            http_response_code(500);
            Response::json(false, 'Unable to fetch Telegram counts', []);
        }
    }
}
