<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

class TelegramNodeService
{
    private $baseUrl;
    private $internalApiKey;

    public function __construct()
    {
        $config = require __DIR__ . '/../../../config/telegram.php';
        
        $this->baseUrl = $config['telegram_mtproto_url'] ?? '';
        $this->internalApiKey = $config['telegram_internal_api_key'] ?? '';
    }

    public function createGroup($data)
    {
       return $this->post('/create-group', $data);
    }

    public function generateEmployeeInvite($data)
    {
        return $this->post(
            '/generate-employee-invite',
            $data
        );
    }

    private function post($url, $payload)
    {
        if ($this->internalApiKey === '') {
            return [
                'success' => false,
                'message' => 'Telegram internal API key is not configured.'
            ];
        }

        $ch = curl_init($this->baseUrl . $url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Internal-Key: ' . $this->internalApiKey
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'message' => $curlError ?: 'Telegram MTProto service request failed.'
            ];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded)
            ? $decoded
            : ['success' => false, 'message' => 'Telegram MTProto service returned an invalid response.'];
    }
}