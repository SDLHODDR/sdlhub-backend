<?php

require_once __DIR__ . '/TelegramService.php';

class TelegramBotService
{
    private $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function sendGroupMessage($data)
    {
        return $this->sendMessage(
            $data['telegram_group_id'] ?? null,
            $data['message'] ?? null
        );
    }

    public function sendDm($data)
    {
        return $this->sendMessage(
            $data['telegram_chat_id'] ?? null,
            $data['message'] ?? null
        );
    }

    private function sendMessage($chatId, $message)
    {
        if (empty($chatId) || !is_string($message) || trim($message) === '') {
            return [
                'success' => false,
                'message' => 'A Telegram chat ID and message are required.'
            ];
        }

        $response = $this->telegram->sendMessage($chatId, $message);

        return [
            'success' => !empty($response['ok']),
            'data' => $response['result'] ?? null,
            'message' => $response['description'] ?? null
        ];
    }
}