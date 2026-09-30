<?php

require_once __DIR__ . '/../../../core/BaseRepository.php';

class TelegramEventRepository extends BaseRepository
{
    public function store($data)
    {
        $sql = "
            INSERT INTO telegram_events
            (
                event_type,
                telegram_group_id,
                telegram_user_id,
                payload,
                created_on
            )
            VALUES
            (
                :event_type,
                :telegram_group_id,
                :telegram_user_id,
                :payload,
                SYSDATE
            )
        ";

        $this->execute($sql, [
            ':event_type' => $data['event_type'],
            ':telegram_group_id' => $data['telegram_group_id'],
            ':telegram_user_id' => $data['telegram_user_id'],
            ':payload' => json_encode($data['payload'])
        ]);
    }
}