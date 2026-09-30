<?php

require_once __DIR__ .
    '/../modules/telegram/repositories/BroadcastRepository.php';

require_once __DIR__ .
    '/../modules/telegram/services/TelegramService.php';

$repo = new BroadcastRepository();

$telegram = new TelegramService();

$broadcasts = $repo->pending();

foreach ($broadcasts as $broadcast) {

    /*
        TEMP:
        Hardcoded group ID
    */

    $telegram->sendMessage(
        '-100123456789',
        $broadcast['MESSAGE']
    );

    $repo->markCompleted($broadcast['ID']);

    echo "Broadcast Sent: {$broadcast['ID']}" . PHP_EOL;
}