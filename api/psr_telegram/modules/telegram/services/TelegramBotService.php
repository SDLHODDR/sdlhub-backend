<?php

class TelegramBotService
{
    private $baseUrl =
        'http://localhost:5003';

    public function sendGroupMessage($data)
    {
        return $this->post('/send-group-message', $data );
    }

    public function sendDm($data)
    {
        return $this->post('/send-dm', $data );
    }

    private function post($url, $payload)
    {
        $ch = curl_init($this->baseUrl . $url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json']);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}