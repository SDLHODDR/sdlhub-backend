<?php

require_once 'TelegramService.php';

class InviteService
{
    private $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function generate($chatId)
    {
        return $this->telegram->createInviteLink($chatId);
    }
}