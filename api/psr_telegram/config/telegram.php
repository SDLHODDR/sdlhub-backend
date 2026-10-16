<?php

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_SERVER['TELEGRAM_BOT_TOKEN'] ?? '');
if ($botToken === '') {
    $botToken = getenv('BOT_TOKEN') ?: ($_ENV['BOT_TOKEN'] ?? $_SERVER['BOT_TOKEN'] ?? '');
}

$botUsername = getenv('TELEGRAM_BOT_USERNAME') ?: ($_ENV['TELEGRAM_BOT_USERNAME'] ?? $_SERVER['TELEGRAM_BOT_USERNAME'] ?? 'sdlitTechBot');

$telegram_api_id  = "179779";
$telegram_api_hash = "cef35740b4d4728185f7d04bc05e2ea6";
$telegram_session = "1BQANOTEuMTA4LjU2LjEwNQG7lIiJ7bob8f1son2TLH7+Lee0Z5UIARsI8V6LeSGJJKl8AJcY02jkeGgE+/GoNCEr9ZJLvny13ezFfoKCNhG6NZNblBvvVmf2u6TceGJ+9hsbiCAiljIMq8DwX0SUmNi9i3T/GE+DY2xpM0366Q16raKOsK8guW5CNX2cgHcPqBuHBsw6SYeuJTshJMjO09Kmf3N6T/DY/YbnSEr94AZeYT+ee+15NDk2DABQGnNDPsiDUqDmZ9nNfpeFeJFa+U70/t/5RxQexKZibneGw8Y9zAL2UDhbpe5NBn2NpkM3QPRHm+lHfewGVsksmfcUB5GeqkK/foaoABjCdLImXC5NJw==";
$telegram_port = "5001";
$telegram_group_joined_callback_url = "http://localhost:8080/sdlhub-backend/api/psr_telegram/api/telegram/members/group-joined";
$telegram_map_user_group_url = "http://localhost:8080/sdlhub-backend/api/psr_telegram/api/telegram/members/mapuser-group";
$telegram_internal_api_key = "1BQANOTEuMTA4LjU2LjEwNQG7lIiJ7bob8f1son2TLH7+Lee0Z5UIARsI8V6LeSGJJKl8AJcY02jkeGgE+/GoNCEr9ZJLvny13ezFfoKCNhG6NZNblBvvVmf2u6TceGJ+9hsbiCAiljIMq8DwX0SUmNi9i3T/GE+DY2xpM0366Q16raKOsK8guW5CNX2cgHcPqBuHBsw6SYeuJTshJMjO09Kmf3N6T/DY/YbnSEr94AZeYT+ee+15NDk2DABQGnNDPsiDUqDmZ9nNfpeFeJFa+U70/t/5RxQexKZibneGw8Y9zAL2UDhbpe5NBn2NpkM3QPRHm+lHfewGVsksmfcUB5GeqkK/foaoABjCdLImXC5NJw==";

$telegram_mtproto_url = "http://127.0.0.1:5001";

return [
    'bot_token' => $botToken,
    'bot_username' => $botUsername,

    'telegram_api_id' => $telegram_api_id,
    'telegram_api_hash' => $telegram_api_hash,
    'telegram_session' => $telegram_session,
    'telegram_port' => $telegram_port,
    'telegram_group_joined_callback_url' => $telegram_group_joined_callback_url,
    'telegram_map_user_group_url' => $telegram_map_user_group_url,
    'telegram_internal_api_key' => $telegram_internal_api_key,
    'telegram_mtproto_url' => $telegram_mtproto_url
];