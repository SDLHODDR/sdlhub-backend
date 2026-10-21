<?php

require_once __DIR__ . '/../../../core/BaseRepository.php';

class TelegramCountRepository extends BaseRepository
{
    public function getCounts()
    {
        $sql = "
            SELECT
                (SELECT COUNT(*) FROM PSR_TELEGRAM_GROUPS) AS GROUP_COUNT,
                (SELECT COUNT(DISTINCT MEMBER_ID)
                 FROM PSR_MEMBER_GROUP_MAPPING
                 WHERE GROUP_STATUS = 'JOINED') AS MEMBER_COUNT,
                (
                    SELECT COUNT(*)
                    FROM PSR_MESSAGE_QUEUE
                    WHERE STATUS = 'PENDING'
                      AND MESSAGE_TYPE IN ('TELEGRAM_MEMBER_DM', 'TELEGRAM_GROUP_BROADCAST')
                ) AS PENDING_MESSAGE_COUNT
            FROM DUAL
        ";

        $stmt = $this->execute($sql);
        $rows = $this->fetchAll($stmt);

        return $rows[0] ?? [
            'GROUP_COUNT' => 0,
            'MEMBER_COUNT' => 0,
            'PENDING_MESSAGE_COUNT' => 0,
        ];
    }
}
