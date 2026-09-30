<?php

require_once __DIR__ .
    '/../repositories/TelegramEventRepository.php';

require_once __DIR__ .
    '/../repositories/MemberGroupRepository.php';

class WebhookController
{
    public function handle()
    {
        $payload =
            json_decode(file_get_contents("php://input"), true);

        $eventRepo = new TelegramEventRepository();

        $mapRepo = new MemberGroupRepository();

        /*
            MEMBER JOIN EVENT
        */

        if (isset($payload['message']['new_chat_member'])) {

            $member =
                $payload['message']['new_chat_member'];

            $chat =
                $payload['message']['chat'];

            $eventRepo->store([
                'event_type' => 'MEMBER_JOINED',
                'telegram_group_id' => $chat['id'],
                'telegram_user_id' => $member['id'],
                'payload' => $payload
            ]);

            $mapRepo->markJoined(
                $member['id'],
                $chat['id']
            );
        }

        echo json_encode([
            'success' => true
        ]);
    }
}