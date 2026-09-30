<?php

require_once __DIR__ . '/../../../core/BaseRepository.php';

class QueueRepository extends BaseRepository
{
    public function getPendingMessages()
    {
        $sql = " SELECT * FROM PSR_MESSAGE_QUEUE  WHERE STATUS='PENDING' ORDER BY MSG_ID";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function getGroupById( $groupId )
    {
      $sql = " SELECT TELEGRAM_GROUP_ID, TITLE FROM PSR_TELEGRAM_GROUPS WHERE ID = :group_id ";
      $stmt = oci_parse( $this->conn, $sql );
      oci_bind_by_name( $stmt, ":group_id", $groupId );
      oci_execute($stmt);

      return oci_fetch_assoc($stmt);
    }

    public function markSent( $msgId )
    {
      $sql = " UPDATE PSR_MESSAGE_QUEUE 
        SET STATUS='SENT', PROCESSED_ON=SYSDATE WHERE MSG_ID=:msg_id ";

      $stmt = oci_parse( $this->conn, $sql );
      oci_bind_by_name($stmt, ':msg_id', $msgId );
      oci_execute($stmt);
    }

    public function markFailed( $msgId, $error )
    {
        $sql = " UPDATE PSR_MESSAGE_QUEUE SET STATUS='FAILED', RETRY_COUNT = NVL(RETRY_COUNT,0)+1, ERROR_MESSAGE=:error WHERE MSG_ID=:msg_id ";

        $stmt = oci_parse( $this->conn, $sql );
        oci_bind_by_name( $stmt, ':msg_id', $msgId );
        oci_bind_by_name( $stmt, ':error', $error );

        oci_execute($stmt);
    }
}