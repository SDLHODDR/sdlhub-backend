<?php

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_SERVER['TELEGRAM_BOT_TOKEN'] ?? '');
if ($botToken === '') {
    $botToken = getenv('BOT_TOKEN') ?: ($_ENV['BOT_TOKEN'] ?? $_SERVER['BOT_TOKEN'] ?? '');
}

$botUsername = getenv('TELEGRAM_BOT_USERNAME') ?: ($_ENV['TELEGRAM_BOT_USERNAME'] ?? $_SERVER['TELEGRAM_BOT_USERNAME'] ?? 'sdlitTechBot');

return [
    'bot_token' => $botToken,
    'bot_username' => $botUsername
];