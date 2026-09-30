<?php

require_once __DIR__ . '/../repositories/BroadcastRepository.php';

class BroadcastService
{
    private $repo;

    public function __construct()
    {
        $this->repo = new BroadcastRepository();
    }

    public function create($payload)
    {
        $this->repo->create($payload);

        return true;
    }
}