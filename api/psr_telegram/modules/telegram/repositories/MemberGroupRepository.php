<?php

require_once __DIR__ . '/../../../core/BaseRepository.php';

class MemberGroupRepository extends BaseRepository
{
    public function map($memberId, $groupId)
    {
        $sql = "
            INSERT INTO member_group_map
            (
                member_id,
                group_id,
                invited_on,
                created_on
            )
            VALUES
            (
                :member_id,
                :group_id,
                SYSDATE,
                SYSDATE
            )
        ";

        $this->execute($sql, [
            ':member_id' => $memberId,
            ':group_id' => $groupId
        ]);

        return true;
    }

    public function markJoined($telegramUserId, $telegramGroupId)
    {
        $sql = "
            UPDATE member_group_map mgm
            SET
                join_status = 'JOINED',
                joined_on = SYSDATE
            WHERE mgm.member_id IN (
                SELECT id
                FROM telegram_members
                WHERE telegram_user_id = :telegram_user_id
            )
            AND mgm.group_id IN (
                SELECT id
                FROM telegram_groups
                WHERE telegram_group_id = :telegram_group_id
            )
        ";

        $this->execute($sql, [
            ':telegram_user_id' => $telegramUserId,
            ':telegram_group_id' => $telegramGroupId
        ]);
    }
}