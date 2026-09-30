<?php

require_once __DIR__ . '/../repositories/QueueRepository.php';
require_once __DIR__ . '/WhatsAppNodeService.php';
require_once __DIR__ . '/TelegramBotService.php';

class QueueProcessorService
{
    private $repo;
    private $whatsApp;
    private $telegramBot;

    public function __construct()
    {
        $this->repo = new QueueRepository();
        $this->whatsApp = new WhatsAppNodeService();
        $this->telegramBot = new TelegramBotService();
    }

    public function processPendingMessages()
    {
        $messages =  $this->repo->getPendingMessages();
        echo "Found " . count($messages) . " pending messages\n";
        foreach ($messages as $message)
        {
            echo "Processing MSG_ID: " . $message['MSG_ID'] . "\n";

            try {
                $this->processMessage( $message );
            } catch (Exception $e) {
                echo "Failed: " . $e->getMessage() . "\n";

                $this->repo->markFailed( $message['MSG_ID'], $e->getMessage() );
            }
        }
    }

    private function processMessage( $message )
    {
        switch ( $message['MESSAGE_TYPE'] )
        {
            case 'WHATSAPP_CAPTURE_CHATID':
                
                echo "Sending WhatsApp...\n";
                
                $this->processWhatsappCapture( $message );
                
                echo "Success\n";
                
                break;

            case 'TELEGRAM_GROUP_BROADCAST':
                
                echo "Sending Telegram Group Message...\n";
                
                $this->processTelegramGroupBroadcast( $message );
                
                echo "Success\n";
                
                break;

            case 'TELEGRAM_MEMBER_DM':

                $this->processMemberDM( $message );

                break;
        }
    }

    private function processMemberDM( $row )
    {
      $payload = [ 'chat_id' => $row[ 'USER_CHAT_ID' ], 'text' => $row[ 'PAYLOAD' ] ];

      $payloadData = $payload['text'];
        if ($payloadData instanceof OCILob)
        {
            $payloadData = $payloadData->load();
        }

        $payloadMem = json_decode(
            $payloadData,
            true
        );

        $telegramMemberId = $payload['chat_id'] ?? null;
        $messageTel = $payloadMem['message'] ?? null;

       $response = $this->telegramBot->sendDm([
                'telegram_chat_id' => $telegramMemberId,
                'message' => $messageTel
            ]);

            if ( !$response || empty($response['success'])) {
                throw new Exception( 'Telegram group message sending failed' );
            }
            
            $this->repo->markSent( $row['MSG_ID'] );
    }

    private function processTelegramGroupBroadcast( $message )
    {
        $payloadData = $message['PAYLOAD'];
        if ($payloadData instanceof OCILob)
        {
            $payloadData = $payloadData->load();
        }

        $payload = json_decode(
            $payloadData,
            true
        );

        $telegramGroupId = "-100" . ($message['TELEGRAM_GROUP_ID']) ?? null;
        $messageTel = $payload['message'] ?? null;
        
        $response = $this->telegramBot->sendGroupMessage([
                'telegram_group_id' => $telegramGroupId,
                'message' => $messageTel
            ]);

            if ( !$response || empty($response['success'])) {
                throw new Exception( 'Telegram group message sending failed' );
            }
            
            $this->repo->markSent( $message['MSG_ID'] );
       
    }

    private function processWhatsappCapture( $message )
    {
        
        //$payload = json_decode( $message['PAYLOAD'], true );
        $payloadData = $message['PAYLOAD'];
        if ($payloadData instanceof OCILob)
        {
            $payloadData = $payloadData->load();
        }

        $payload = json_decode(
            $payloadData,
            true
        );
        // print_r($message);
        // //exit;

        $group = $this->repo->getGroupById( $payload['group_id'] );

        $inviteLink = "https://t.me/sdlitTechBot?start=" . $payload['employee_code'] . "_" . $group['TELEGRAM_GROUP_ID'];

        $msg =
            "Hello {$payload['member_name']},\n\n" .
            "You have been assigned to Telegram Group:\n" .
            "{$group['TITLE']}\n\n" .
            "Click below to start Telegram Bot:\n" .
            $inviteLink;

        //print $payload['mobile'];
        //if($payload['mobile'] == '9167655538') {
            // print_r($payload); 
            // print_r($msg);
            // exit;    
            // sending for test numbers
            $response = $this->whatsApp->inviteGroupMsg([
                'mobile' => $payload['mobile'],
                'message' => $msg
            ]);

            if ( !$response || empty($response['success'])) {
                throw new Exception( 'WhatsApp sending failed' );
            }
            
            $this->repo->markSent( $message['MSG_ID'] );
        //}
    }
}