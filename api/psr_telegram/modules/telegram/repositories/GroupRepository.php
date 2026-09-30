<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../../../core/BaseRepository.php';

class GroupRepository extends BaseRepository
{
    public function create($data)
    {
        // $sql = "
        //     INSERT INTO psr_telegram_groups
        //     (
        //         telegram_group_id,
        //         title,
        //         invite_link,
        //         //DIVSN_ID,
        //         HQ_ID,
        //         DESCRIPTION,
        //         CREATED_ON
        //     )
        //     VALUES
        //     (
        //         :telegram_group_id,
        //         :title,
        //         :invite_link,
        //         :division_id,
        //         :hq_id,
        //         :description,
        //         SYSDATE
        //     )
        // ";

        // $this->execute($sql, [
        //     ':telegram_group_id' => $data['telegram_group_id'],
        //     ':title' => $data['title'],
        //     ':invite_link' => $data['invite_link'],
        //     ':division_id' => $data['payload']['DIVSN_ID'],
        //     ':hq_id' => $data['payload']['HQ_ID'] ?? null,
        //     ':description' => $data['payload']['description'] ?? null
        // ]);

        $sql = "INSERT INTO psr_telegram_groups ( telegram_group_id, title, invite_link, DESCRIPTION, CREATED_ON )  VALUES ( :telegram_group_id, :title, :invite_link, :description, SYSDATE )";

        $this->execute($sql, [
            ':telegram_group_id' => $data['telegram_group_id'],
            ':title' => $data['title'],
            ':invite_link' => $data['invite_link'],
            ':description' => $data['payload']['description'] ?? null
        ]);

        return true;
    }

    public function getAll()
    {
        $sql = "SELECT * FROM PSR_TELEGRAM_GROUPS ORDER BY ID DESC";
        
        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function getMemberCount($telegram_group_id)
    {
        $sql = "SELECT COUNT(*) as member_count 
        FROM PSR_MEMBER_GROUP_MAPPING 
        WHERE GROUP_ID = :telegram_group_id and GROUP_STATUS = 'JOINED'";

        $stmt = $this->execute($sql, [ ':telegram_group_id' => $telegram_group_id ]);
        $result = $this->fetchAll($stmt);
        
        return $result;
    }

    public function saveBroadcastMessage($data)
    {
      $payload = json_encode([ 'message' => $data['message'] ]);
      
      $sql = " INSERT INTO PSR_MESSAGE_QUEUE ( MESSAGE_TYPE, PAYLOAD, STATUS, CREATED_ON, TELEGRAM_GROUP_ID ) VALUES ( :message_type, :payload, 'PENDING', SYSDATE, :telegram_group_id ) ";

      $stmt = oci_parse($this->conn, $sql);
      $messageType = 'TELEGRAM_GROUP_BROADCAST';
      $telegramGroupId = $data['telegram_group_id'] ?? null;

      oci_bind_by_name( $stmt, ':message_type', $messageType );
      oci_bind_by_name( $stmt, ':payload', $payload );
      oci_bind_by_name( $stmt, ':telegram_group_id', $telegramGroupId );

      $result = oci_execute($stmt);

      if (!$result) {
        $error = oci_error($stmt);
        throw new Exception( $error['message'] );
      }

      return [ 'saved' => true];
    }
}