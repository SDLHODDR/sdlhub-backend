<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../core/BaseRepository.php';
require_once __DIR__ . '/../services/TelegramNodeService.php';

class MemberRepository extends BaseRepository
{
    public function create($data)
    {
        $sql = " INSERT INTO psr_telegram_members ( 
            EMPLOYEE_CODE, 
            MOBILE_NUMBER, 
            FIRST_NAME, 
            LAST_NAME,
            COMPANY_ID, 
            DEPARTMENT_ID, 
            DIVISION_ID, 
            CREATED_ON ) 
        VALUES (
            :employee_code, 
            :mobile_number, 
            :first_name, 
            :last_name, 
            :company_id, 
            :department_id, 
            :division_id, 
            SYSDATE ) ";

        $this->execute($sql, [ 
            ':employee_code' => $data['employee_code'],
            ':mobile_number' => $data['mobile_number'], 
            ':first_name'    => $data['first_name'], 
            ':last_name'     => $data['last_name'],
            ':company_id'    => $data['company_id'],
            ':department_id' => $data['department_id'],
            ':division_id'   => $data['division_id'],
        ]);

        return true;
    }

    public function getAll()
    {
        $sql = "SELECT ID, EMPLOYEE_CODE, MOBILE_NUMBER, TELEGRAM_CHAT_ID, FIRST_NAME, LAST_NAME, 
        TELEGRAM_JOINED, ACTIVE_FLAG, CREATED_ON FROM psr_telegram_members ORDER BY ID DESC ";
        $stmt = $this->execute($sql);
        return $this->fetchAll($stmt);
    }

    public function fetchMembersGroups($data)
    {
        //print_r($data); exit;
        //$sql = "SELECT * FROM PSR_TELEGRAM_GROUPS WHERE DIVSN_ID = '" . $data["division_id"] . "'";
        $sql = "SELECT * FROM PSR_TELEGRAM_GROUPS WHERE ACTIVE_FLAG = 1 ";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function fetchGroupMembers($data)
    {
      if (empty($data)) {
        return [];
      }

      $memberIds = [];

      foreach ($data as $member) {
        if ( !empty( $member['id'] ) ) {
          $memberIds[] = (int)$member['id'];
        }
      }

      if ( empty($memberIds) ) {
        return [];
      }

      $ids = implode( ',', $memberIds );

      $sql = " SELECT MEMBER_ID, MEMBER_TELEGRAM_ID FROM PSR_MEMBER_GROUP_MAPPING WHERE MEMBER_ID IN ($ids) ";

      $stmt = $this->execute( $sql );

      return $this->fetchAll( $stmt);
    }

    public function saveMemberDM( $data ) {
      $payload = json_encode([ 'message' => trim( $data['message'] ) ]);
      
      foreach ( $data['member_data'] as $member ) {
        $telegramId = $member[ 'member_telegram_id' ];

        $sql = " INSERT INTO PSR_MESSAGE_QUEUE ( MESSAGE_TYPE, USER_CHAT_ID, USER_TELEGRAM_ID, PAYLOAD, STATUS, CREATED_ON ) VALUES (
          'TELEGRAM_MEMBER_DM',
          '{$telegramId}',
          '{$telegramId}',
          '{$payload}',
          'PENDING',
          SYSDATE
        )";

        $this->execute( $sql );
      }
      return true;
    }

    public function getGroupMembers( ) {
        // //$sql = "SELECT 
        // PTM.ID, PTM.EMPLOYEE_CODE, PTM.MOBILE_NUMBER, PTM.TELEGRAM_CHAT_ID, PTM.FIRST_NAME, 
        // PTM.LAST_NAME, PTM.TELEGRAM_JOINED, PTM.ACTIVE_FLAG, PTM.CREATED_ON, PMGM.JOINED_ON 
        // FROM PSR_TELEGRAM_MEMBERS PTM 
        // LEFT JOIN PSR_MEMBER_GROUP_MAPPING PMGM 
        // ON PTM.ID = PMGM.MEMBER_ID
        // ORDER BY PTM.ID DESC ";

        $sql =" SELECT
                M.ID,
                M.EMPLOYEE_CODE,
                M.MOBILE_NUMBER,
                M.TELEGRAM_CHAT_ID,
                M.FIRST_NAME,
                M.LAST_NAME,
                M.TELEGRAM_JOINED,
                M.ACTIVE_FLAG,
                MGM.JOINED_ON,
                TO_CHAR(M.CREATED_ON,'DD-MON-RR') CREATED_ON,

                COUNT(DISTINCT TG.ID) GROUP_COUNT,

                LISTAGG(
                    TG.TITLE || ' (' || MGM.GROUP_STATUS || ')',
                    CHR(10)
                ) WITHIN GROUP (ORDER BY TG.TITLE) GROUP_NAMES

            FROM PSR_TELEGRAM_MEMBERS M

            LEFT JOIN PSR_MEMBER_GROUP_MAPPING MGM
                ON MGM.MEMBER_ID = M.ID

            LEFT JOIN PSR_TELEGRAM_GROUPS TG
                ON TG.ID = MGM.GROUP_ID

            GROUP BY
                M.ID,
                M.EMPLOYEE_CODE,
                M.MOBILE_NUMBER,
                M.TELEGRAM_CHAT_ID,
                M.FIRST_NAME,
                M.LAST_NAME,
                M.TELEGRAM_JOINED,
                M.ACTIVE_FLAG,
                M.CREATED_ON,
                MGM.JOINED_ON

            ORDER BY M.ID DESC";

        $stmt = $this->execute($sql);
        //print_r($this->fetchAll($stmt)); exit;

        return $this->fetchAll($stmt);
    }

    public function mapMembersGroups($data)
    {
        $groupId = $data['group_id'] ?? null;
        $memberId = $data['emp_code'] ?? null;
        $chatId = $data['telegram_chat_id'] ?? null;

        $groupSql = "SELECT INVITE_LINK, TITLE FROM PSR_TELEGRAM_GROUPS WHERE TELEGRAM_GROUP_ID = '{$groupId}'";
        $groupStmt = $this->execute($groupSql);
        $groupArrs = $this->fetchAll($groupStmt);
        $inviteLink = $groupArrs[0]["INVITE_LINK"] ?? null;

        //$msg = "Hello Member,
            // Click below to join the Telegram Group:
            // " . $inviteLink;

        $msg = $groupArrs;    

        $returnArr["groups"][] = [
            "id" => $groupId,
            "GROUP_NAME" => $groupArrs[0]['TITLE'] ?? null,
            "INVITE_LINK" => $msg
        ];

        $this->updateTelegramMemberChatId(
            $memberId,
            $chatId
        );

        $this->updateQueueChatId(
            $memberId,
            $chatId
        );

        return [
            "success" => true,
            "message" => "Groups assigned successfully",
            "data" => $returnArr
        ];    
        // $sql = "
        // SELECT
        //     g.TITLE AS GROUP_NAME,
        //     g.INVITE_LINK
        // FROM PSR_TELEGRAM_GROUPS g
        // INNER JOIN PSR_MEMBER_GROUP_MAP m
        //     ON m.GROUP_ID = g.ID
        // INNER JOIN PSR_TELEGRAM_MEMBERS p
        //     ON p.ID = m.MEMBER_ID
        // WHERE p.EMPLOYEE_CODE = :emp_code
        // ";

        // $stmt = $this->execute(
        //     $sql,
        //     [
        //         ':emp_code' => $data["emp_code"]
        //     ]
        // );

        // return $this->fetchAll($stmt);
    }

    public function updateTelegramMemberChatId( $empCode, $chatId )
    {
        $sql = " UPDATE PSR_TELEGRAM_MEMBERS SET TELEGRAM_CHAT_ID = :chat_id 
        WHERE EMPLOYEE_CODE = :emp_code ";

        $stmt = oci_parse( $this->conn, $sql );

        oci_bind_by_name( $stmt, ":chat_id", $chatId );

        oci_bind_by_name( $stmt, ":emp_code", $empCode );

        oci_execute( $stmt, OCI_COMMIT_ON_SUCCESS );

        return true;
    }

    public function saveMembersGroups($data)
    {
        // echo '<pre/>';
        // print_r($data);
        // exit;

        // $members  = $data['member_data'] ?? [];
        // $groupIds = $data['group_ids'] ?? [];
        
        // foreach ($members as $member){
        //     foreach ($groupIds as $groupId){
        //         $exists = $this->isMemberGroupMapped( $member['id'], $groupId );

        //         if ($exists) { continue; }
                
        //         $this->saveMemberGroupMapping( $member, $groupId );
        //         $this->createMessageQueue( $member, $groupId );
        //     }
        // }

        // return [
        //     "success" => true,
        //     "message" => "Groups assigned successfully"
        // ];





        // print_r($data["member_data"]);
        // exit;
        foreach ($data["member_data"] as $key => $valmemb) {
            # code...
            $memberId = $valmemb["id"];
            $memberPhone = $valmemb["mobile"];
            $groupIds = $data["group_ids"];

        // $telegram = new TelegramNodeService();
            
            $returnArr = [];
            $returnArr["member_id"] = $memberId;
            $returnArr["member_phone"] = $memberPhone;
            $returnArr["groups"] = [];

            foreach ($groupIds as $groupId) {
                //$groupSql = " SELECT INVITE_LINK FROM PSR_TELEGRAM_GROUPS WHERE ID = '" . $groupId . "' ";
                // Fetch group details
                $groupSql = "SELECT TELEGRAM_GROUP_ID, TITLE FROM PSR_TELEGRAM_GROUPS WHERE ID = '{$groupId}'";
                $groupStmt = $this->execute($groupSql);
                $groupArrs = $this->fetchAll($groupStmt);
                
                // // Generate employee invite
                // $response = $telegram->generateEmployeeInvite([
                //     'telegram_group_id' => $groupArrs[0]['TELEGRAM_GROUP_ID'],
                //     'employee_code' => $memberId
                // ]);



                // // SAVE INVITE MAPPING HERE
                // $insertSql = "INSERT INTO PSR_MEMBER_GROUP_INVITES ( MEMBER_ID, GROUP_ID, INVITE_HASH, INVITE_LINK, STATUS, CREATED_ON ) VALUES ( :member_id, :group_id, :invite_hash, :invite_link, 'PENDING', SYSDATE )";

                // $params = [
                //     ':member_id' => $memberId,
                //     ':group_id' => $groupId,
                //     ':invite_hash' => $response['data']['invite_hash'],
                //     ':invite_link' => $response['data']['invite_link']
                // ];
                // $this->execute($insertSql, $params);


                //$inviteLink = $groupArrs[0]["INVITE_LINK"] ?? null;

                // $returnArr["groups"]["id"] = $groupId;
                // $returnArr["groups"]["invite_link"] = $inviteLink;

                // // Insert mapping
                // $insertSql = "
                //     INSERT INTO PSR_MEMBER_GROUP_MAP
                //     ( MEMBER_ID, GROUP_ID, INVITE_STATUS, INVITE_LINK, INVITED_ON, CREATED_ON )
                //     VALUES ( :member_id, :group_id, :invite_status, :invite_link, SYSDATE, SYSDATE )";

                // $params = [
                //     ":member_id" => $memberId,
                //     ":group_id" => $groupId,
                //     ":invite_status" => "Pending",
                //     ":invite_link" => $inviteLink
                // ];
                
                // $this->execute($insertSql, $params);

                // $response = $telegram ->generateEmployeeInvite([
                //     'telegram_group_id' => $groupArrs[0]['TELEGRAM_GROUP_ID'],
                //      'employee_code' => $memberId
                // ]);

                $msg = "Hello Member,
                Click below to get your Telegram Group Invite:
                https://t.me/sdlitTechBot?start=" . $memberId . "_" . $groupArrs[0]['TELEGRAM_GROUP_ID'] ?? null;

                $returnArr["groups"][] = [
                    "id" => $groupId,
                    "telegram_group_id" => $groupArrs[0]['TELEGRAM_GROUP_ID'] ?? null,
                    "GROUP_NAME" => $groupArrs[0]['TITLE'] ?? null,
                    "INVITE_LINK" => $msg
                ];
            }
        }

        return [
            "success" => true,
            "message" => "Groups assigned successfully",
            "data" => $returnArr
        ];
        
    }

    private function isMemberGroupMapped( $memberId, $groupId )
    {
        $sql = " SELECT COUNT(*) CNT FROM PSR_MEMBER_GROUP_MAPPING WHERE MEMBER_ID = :member_id 
        AND GROUP_ID = :group_id";

        $stmt = oci_parse( $this->conn, $sql );

        oci_bind_by_name( $stmt, ":member_id", $memberId );
        oci_bind_by_name( $stmt, ":group_id", $groupId );
        oci_execute($stmt);
        $row = oci_fetch_assoc($stmt);

        return $row['CNT'] > 0;
    }

    private function createMessageQueue( $member,$groupId){
        $telegramMember = $this->getTelegramMember( $member['employeeCode'] );

        if ( !empty( $telegramMember['TELEGRAM_CHAT_ID'] ))
        {
            $this->createTelegramMessageQueue( $member, $groupId, $telegramMember['TELEGRAM_CHAT_ID']);
        } else {
            $this->createWhatsAppCaptureChatIdQueue( $member, $groupId);
        }
    }

    private function saveMemberGroupMapping( $member, $groupId )
    {
        $sql = "INSERT INTO
            PSR_MEMBER_GROUP_MAPPING
            ( MEMBER_ID, MEMBER_FULLNAME, MEMBER_PHONE, MEMBER_EMPCODE, MEMBER_TELEGRAM_ID, GROUP_ID, GROUP_STATUS, CREATED_ON )
            VALUES
            ( :member_id, :member_name, :member_phone, :member_empcode, :telegram_id, :group_id, 'PENDING', SYSDATE )";

        $stmt = oci_parse( $this->conn, $sql );

        oci_bind_by_name( $stmt, ":member_id", $member['id'] );
        oci_bind_by_name( $stmt, ":member_name", $member['name'] );
        oci_bind_by_name( $stmt, ":member_phone", $member['mobile'] );
        oci_bind_by_name( $stmt, ":member_empcode", $member['employeeCode'] );

        $telegramId = $member['username'] ?? null;

        oci_bind_by_name( $stmt, ":telegram_id", $telegramId );
        oci_bind_by_name( $stmt, ":group_id", $groupId );
        oci_execute($stmt);
    }

    private function createWhatsAppCaptureChatIdQueue( $member, $groupId )
    {
        $payload = json_encode([
            'member_id' => $member['id'],
            'member_name' => $member['name'],
            'mobile' => $member['mobile'],
            'employee_code' => $member['employeeCode'],
            'group_id' => $groupId,
            'message' => ''
        ]);

        $sql = "INSERT INTO PSR_MESSAGE_QUEUE ( MESSAGE_TYPE, USER_NUMBER, USER_EMP_CODE, USER_CHAT_ID, PAYLOAD, STATUS, CREATED_ON ) VALUES ( :message_type, :mobile, :emp_code, :chat_id, :payload, 'PENDING', SYSDATE )";

        $stmt = oci_parse( $this->conn, $sql );

        $messageType = 'WHATSAPP_CAPTURE_CHATID';
        $chatId = $member['username'] ?? null;

        oci_bind_by_name( $stmt, ":message_type", $messageType );
        oci_bind_by_name( $stmt, ":mobile", $member['mobile'] );
        oci_bind_by_name( $stmt, ":emp_code", $member['employeeCode'] );
        oci_bind_by_name( $stmt, ":chat_id", $chatId );
        oci_bind_by_name( $stmt, ":payload", $payload );

        oci_execute($stmt);
    }

    private function getTelegramMember( $employeeCode )
    {
        $sql = " SELECT * FROM PSR_TELEGRAM_MEMBER WHERE EMPLOYEE_CODE = :employee_code AND ACTIVE_FLAG = 'Y' ";

        $stmt = oci_parse( $this->conn, $sql );

        oci_bind_by_name( $stmt, ":employee_code", $employeeCode );

        oci_execute($stmt);

        return oci_fetch_assoc($stmt);
    }

    

    public function updateQueueChatId( $empCode, $chatId )
    {
        $sql = " UPDATE PSR_MESSAGE_QUEUE 
        SET USER_CHAT_ID = :chat_id
        WHERE USER_EMP_CODE = :emp_code AND USER_CHAT_ID IS NULL ";

        $stmt = oci_parse( $this->conn, $sql );

        oci_bind_by_name( $stmt, ":chat_id", $chatId );

        oci_bind_by_name( $stmt, ":emp_code", $empCode );

        oci_execute( $stmt, OCI_COMMIT_ON_SUCCESS );

        return true;
    }

    public function groupJoined($data)
    {
        $telegramUserId = $data['telegram_user_id'];
        $telegramGroupId = $data['telegram_group_id'];

        /*
        |--------------------------------------------------------------------------
        | Get Member
        |--------------------------------------------------------------------------
        */

        $memberSql = " SELECT ID, EMPLOYEE_CODE FROM PSR_TELEGRAM_MEMBERS WHERE TELEGRAM_CHAT_ID = :telegram_user_id";
        
        $memberStmt = oci_parse( $this->conn, $memberSql );

        oci_bind_by_name( $memberStmt, ":telegram_user_id", $telegramUserId );
        oci_execute($memberStmt);
        $member = oci_fetch_assoc( $memberStmt );

        if (!$member) {
            throw new Exception( "Member not found" );
        }

        $empCode = $member['EMPLOYEE_CODE'];

        /*
        |--------------------------------------------------------------------------
        | Get Local Group ID
        |--------------------------------------------------------------------------
        */

        $groupSql = "SELECT ID FROM PSR_TELEGRAM_GROUPS WHERE TELEGRAM_GROUP_ID = :telegram_group_id ";

        $groupStmt = oci_parse( $this->conn, $groupSql );
        oci_bind_by_name( $groupStmt, ":telegram_group_id", $telegramGroupId );
        oci_execute($groupStmt);
        $group = oci_fetch_assoc( $groupStmt );

        if (!$group) {
            throw new Exception( "Group not found" );
        }

        $groupId = $group['ID'];
        /*
        |--------------------------------------------------------------------------
        | Update Queue
        |--------------------------------------------------------------------------
        */

        $queueSql = " UPDATE PSR_MESSAGE_QUEUE SET USER_TELEGRAM_ID = :telegram_user_id 
        WHERE USER_CHAT_ID = :telegram_user_id ";

        $queueStmt = oci_parse( $this->conn, $queueSql );
        oci_bind_by_name( $queueStmt, ":telegram_user_id", $telegramUserId );
        oci_execute( $queueStmt, OCI_NO_AUTO_COMMIT );

        /*
        |--------------------------------------------------------------------------
        | Update Member
        |--------------------------------------------------------------------------
        */

        $memberUpdateSql = " UPDATE PSR_TELEGRAM_MEMBERS SET TELEGRAM_JOINED = 1 
        WHERE TELEGRAM_CHAT_ID = :telegram_user_id ";

        $memberUpdateStmt = oci_parse( $this->conn, $memberUpdateSql );
        oci_bind_by_name( $memberUpdateStmt, ":telegram_user_id", $telegramUserId );
        oci_execute( $memberUpdateStmt, OCI_NO_AUTO_COMMIT );

        /*
        |--------------------------------------------------------------------------
        | Update Member Group Mapping
        |--------------------------------------------------------------------------
        */

        $mappingSql = "UPDATE PSR_MEMBER_GROUP_MAPPING SET MEMBER_TELEGRAM_ID = :telegram_user_id, GROUP_STATUS = 'JOINED', JOINED_ON = SYSDATE WHERE MEMBER_EMPCODE = :emp_code AND GROUP_ID = :group_id";

        $mappingStmt = oci_parse( $this->conn, $mappingSql );
        oci_bind_by_name( $mappingStmt, ":telegram_user_id", $telegramUserId );
        oci_bind_by_name( $mappingStmt, ":emp_code",$empCode);
        oci_bind_by_name( $mappingStmt, ":group_id", $groupId );
        oci_execute( $mappingStmt, OCI_NO_AUTO_COMMIT );

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        oci_commit(
            $this->conn
        );

        return [
            'success' => true,
            'telegram_user_id' => $telegramUserId,
            'telegram_group_id' => $telegramGroupId,
            'employee_code' => $empCode,
            'group_id' => $groupId
        ];
    }
   
}