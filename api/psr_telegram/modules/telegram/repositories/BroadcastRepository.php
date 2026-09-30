<?php

require_once __DIR__ . '/../../../core/BaseRepository.php';

class BroadcastRepository extends BaseRepository
{
    public function create($data)
    {
        $sql = "
            INSERT INTO broadcasts
            (
                title,
                message,
                status,
                created_on
            )
            VALUES
            (
                :title,
                :message,
                'PENDING',
                SYSDATE
            )
        ";

        $this->execute($sql, [
            ':title' => $data['title'],
            ':message' => $data['message']
        ]);

        return true;
    }

    public function pending()
    {
        $sql = "
            SELECT *
            FROM broadcasts
            WHERE status = 'PENDING'
        ";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function markCompleted($id)
    {
        $sql = "
            UPDATE broadcasts
            SET status = 'COMPLETED'
            WHERE id = :id
        ";

        $this->execute($sql, [
            ':id' => $id
        ]);
    }
}