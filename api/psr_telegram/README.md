# Telegram Integration Runtime

PHP owns Oracle access and performs Telegram Bot API sends directly. `mtproto-service/server.js` is retained for MTProto-only operations such as group creation. The existing WhatsApp Web service delivers group invite links to employee phone numbers, so employees can join without starting the Telegram bot.

Do not use the MTProto user account to send unsolicited private messages to employee phone contacts. Telegram may restrict the account for rate or spam behavior. A bot can send group messages, but a private bot message requires the user to have started or contacted the bot; joining a group alone is not sufficient.

Configure these values in the Node runtime and in the PHP runtime where noted:

- `TELEGRAM_API_ID`, `TELEGRAM_API_HASH`, and `TELEGRAM_SESSION`: Node MTProto credentials.
- `TELEGRAM_INTERNAL_API_KEY`: required by Node and PHP; use the same private value in both runtimes.
- `TELEGRAM_BOT_TOKEN` (or `BOT_TOKEN`): PHP Bot API credential for bot messages to known chat IDs.
- `TELEGRAM_MTPROTO_URL`: PHP URL for the Node service; defaults to `http://127.0.0.1:5001`.

The PHP runtime must receive these values through its environment, `$_ENV`, or `$_SERVER`; the repository's `api/config/env.php` does not load `.env` files. Do not commit credentials. The separate Telegram bot polling service still handles `/start` registration; consolidating that webhook/update handling into PHP is a separate step. The WhatsApp service remains active for invite-link delivery.