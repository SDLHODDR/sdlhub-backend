<?php

class TelegramService
{
    private $botToken;

    public function __construct()
    {
        $config = require __DIR__ . '/../../../config/telegram.php';

        $this->botToken = $config['bot_token'];
    }

    public function sendMessage($chatId, $message)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        $payload = [
            'chat_id' => $chatId,
            'text' => $message
        ];

        return $this->curlPost($url, $payload);
    }

    public function createInviteLink($chatId)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/createChatInviteLink";

        $payload = [
            'chat_id' => $chatId
        ];

        return $this->curlPost($url, $payload);
    }

    private function curlPost($url, $payload)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);

        curl_close($ch);

        return json_decode($response, true);
    }
}