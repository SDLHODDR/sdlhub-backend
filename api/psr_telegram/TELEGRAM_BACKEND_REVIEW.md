# Telegram Backend Review

## Changes Made

- Active Telegram user identity is now read from `PSR_TELEGRAM_MEMBERS.TELEGRAM_CHAT_ID` when resolving DM recipients. The membership query no longer depends on `PSR_MEMBER_GROUP_MAPPING.MEMBER_TELEGRAM_ID`.
- Group-join processing no longer writes the Telegram user ID into `PSR_MEMBER_GROUP_MAPPING` or back into `PSR_MESSAGE_QUEUE`; the membership row remains the canonical identity record. Existing queue target columns are retained for runtime compatibility.
- Corrected a singular/plural table-name mismatch in the legacy member lookup (`PSR_TELEGRAM_MEMBER` to `PSR_TELEGRAM_MEMBERS`).
- Bound division and department IDs in the HRMS employee query instead of interpolating request values into SQL.
- Bound values in direct-message queue inserts and group lookup queries.
- Added ERP session and CSRF checks to browser-facing Telegram API routes. The MTProto join callback now uses the configured internal API key; the Telegram Bot API webhook requires a separately configured `TELEGRAM_WEBHOOK_SECRET` header.

## Service Folder Status

Neither service folder is unused in the current backend:

- `whatsapp-web-service` is called by `MemberService::saveMembersGroups` through `WhatsAppNodeService` and by `QueueProcessorService` for `WHATSAPP_CAPTURE_CHATID` messages.
- `telegram-bot-service` is used for `/start` employee-to-Telegram identity registration and the Bot API send operations in `TelegramBotService`.
- `mtproto-service` creates groups and can export one-use employee invitations, but its employee invite operation is not called by a PHP route and currently hardcodes a seven-day expiry.

Do not rename either folder with `_DELETE_FOLDER` until the WhatsApp invitation workflow and Bot API send/registration paths have been migrated and deployed. Renaming the folders alone would not disable external process-manager jobs and would break active PHP references.

## Schema Consolidation Required

The canonical Telegram user ID is `PSR_TELEGRAM_MEMBERS.TELEGRAM_CHAT_ID`. The following fields currently duplicate or snapshot that identity:

- `PSR_MEMBER_GROUP_MAPPING.MEMBER_TELEGRAM_ID`: redundant per-group copy; application writes were removed from the join path, but historical values and the physical column remain.
- `PSR_MESSAGE_QUEUE.USER_CHAT_ID` and `USER_TELEGRAM_ID`: duplicated target values in message queue rows. They are queue delivery data, not the canonical employee identity. Queue consumers and table nullability must be migrated before dropping either column.
- The old member lookup used singular `PSR_TELEGRAM_MEMBER`, while current member operations use plural `PSR_TELEGRAM_MEMBERS`; confirm whether the singular object exists before retiring it.
- `MemberGroupRepository` and `TelegramEventRepository` refer to unprefixed legacy objects (`member_group_map`, `telegram_members`, `telegram_groups`, `telegram_events`) while the current repositories use `PSR_` objects. Verify these objects and webhook usage before removing or redirecting them.

No Oracle DDL was available in the inspected Telegram module to verify constraints, triggers, or existing data. Before dropping columns/tables, inspect the Oracle schema, back up historical IDs, backfill/validate `PSR_TELEGRAM_MEMBERS.TELEGRAM_CHAT_ID`, update queue consumers, and check every deployed process. This review intentionally did not inspect `D:\projects\sdlhub-backend\.env` or `api\config\db.php`.

## Invitation And Notification Gaps

- PHP `POST /api/telegram/invite/generate` calls Telegram Bot API `createChatInviteLink`; it does not call the MTProto employee invite helper or persist employee-specific invitation records.
- `TelegramNodeService::generateEmployeeInvite` is not exposed by a PHP controller. The Node operation only accepts one employee/group and hardcodes a seven-day, one-use link.
- The current member-assignment path uses a shared group invite URL and sends it through WhatsApp. There is no per-employee invitation mapping, idempotency handling, or ERP notification creation in this flow.
- Telegram event matching currently depends on the employee's Telegram ID already being known. A unique-invite migration must persist an invite hash-to-employee/group mapping and include the joined invite identity in the MTProto callback before the ERP can associate a first-time join with an employee.
- No Telegram-specific count/history API was found. The generic HRMS notification endpoints use a portal-selected notification table; the Telegram type/read contract was not established here.

The protected webhook route will reject callbacks until `TELEGRAM_WEBHOOK_SECRET` is configured and that value is registered as Telegram's webhook secret token. This protects the endpoint by default rather than allowing unauthenticated membership updates.

The next schema/API migration should add a protected batch invite endpoint, persist one invitation record per employee/group, create ERP notifications, support idempotency and partial failures, and update the MTProto join callback to resolve the invitation mapping. Message/notification history APIs can then expose real states without inventing delivery outcomes.