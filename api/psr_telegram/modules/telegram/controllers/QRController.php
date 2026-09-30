<?php

require_once '../services/QRService.php';

class QRController
{
    public function generate()
    {
        $payload =
            json_decode(
                file_get_contents("php://input"),
                true
            );

        $qrService = new QRService();

        $path = $qrService->generate(
            $payload['invite_link'],
            'group_' . time()
        );

        Response::json(
            true,
            'QR generated',
            [
                'path' => $path
            ]
        );
    }
}