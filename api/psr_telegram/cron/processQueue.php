<?php

require_once __DIR__ . '/../modules/telegram/services/QueueProcessorService.php';

try {
    $service = new QueueProcessorService();
    $service->processPendingMessages();
    echo date('Y-m-d H:i:s') . " Queue Processed Successfully\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " Error : " . $e->getMessage() . "\n";
}