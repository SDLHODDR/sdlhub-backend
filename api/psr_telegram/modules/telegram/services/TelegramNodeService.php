<?php

class TelegramNodeService
{
    private $baseUrl =
        'http://localhost:5001';

    public function createGroup($data)
    {
        return $this->post('/create-group', $data );
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
        $ch = curl_init(
            $this->baseUrl . $url
        );

        curl_setopt(
            $ch,
            CURLOPT_RETURNTRANSFER,
            true
        );

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($payload)
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);

        curl_close($ch);

        return json_decode($response, true);
    }
}