<?php

class TelegramService
{
    private $botToken;

    public function __construct()
    {
        $config = require __DIR__ . '/../../../config/telegram.php';

        $this->botToken = $config['bot_token'] ?? '';
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
        if ($this->botToken === '') {
            return [
                'ok' => false,
                'description' => 'Telegram Bot API token is not configured.'
            ];
        }

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'description' => $curlError ?: 'Telegram Bot API request failed.'
            ];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded)
            ? $decoded
            : ['ok' => false, 'description' => 'Telegram Bot API returned an invalid response.'];
    }
}