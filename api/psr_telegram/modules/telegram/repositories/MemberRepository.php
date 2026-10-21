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

            $sql = "
                SELECT DISTINCT
                    MGM.MEMBER_ID,
                    M.TELEGRAM_CHAT_ID AS MEMBER_TELEGRAM_ID
                FROM PSR_MEMBER_GROUP_MAPPING MGM
                INNER JOIN PSR_TELEGRAM_MEMBERS M ON M.ID = MGM.MEMBER_ID
                WHERE MGM.MEMBER_ID IN ($ids)
                    AND M.TELEGRAM_CHAT_ID IS NOT NULL
            ";

      $stmt = $this->execute( $sql );

      return $this->fetchAll( $stmt);
    }

    public function saveMemberDM( $data ) {
      $payload = json_encode([ 'message' => trim( $data['message'] ) ]);
      
      foreach ( $data['member_data'] as $member ) {
        $telegramId = $member[ 'member_telegram_id' ];

                if (empty($telegramId)) {
                    throw new InvalidArgumentException('A Telegram chat ID is required for each recipient.');
                }

                $sql = "INSERT INTO PSR_MESSAGE_QUEUE
                    (MESSAGE_TYPE, USER_CHAT_ID, USER_TELEGRAM_ID, PAYLOAD, STATUS, CREATED_ON)
                    VALUES ('TELEGRAM_MEMBER_DM', :chat_id, :telegram_user_id, :payload, 'PENDING', SYSDATE)";

                $this->execute($sql, [
                    ':chat_id' => $telegramId,
                    ':telegram_user_id' => $telegramId,
                    ':payload' => $payload,
                ]);
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

        $groupSql = "SELECT INVITE_LINK, TITLE FROM PSR_TELEGRAM_GROUPS WHERE TELEGRAM_GROUP_ID = :group_id";
        $groupStmt = $this->execute($groupSql, [':group_id' => $groupId]);
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
        $invitations = [];
        foreach ($data['member_data'] as $member) {
            $memberGroups = [];
            foreach ($data['group_ids'] as $groupId) {
                $groupStmt = $this->execute(
                    'SELECT TELEGRAM_GROUP_ID, TITLE, INVITE_LINK FROM PSR_TELEGRAM_GROUPS WHERE ID = :group_id',
                    [':group_id' => $groupId]
                );
                $group = $this->fetchAll($groupStmt)[0] ?? null;

                if (!$group || empty($group['INVITE_LINK'])) {
                    continue;
                }

                $memberGroups[] = [
                    'id' => $groupId,
                    'telegram_group_id' => $group['TELEGRAM_GROUP_ID'],
                    'GROUP_NAME' => $group['TITLE'],
                    'INVITE_LINK' => $group['INVITE_LINK']
                ];
            }

            $invitations[] = [
                'member_id' => $member['id'] ?? null,
                'member_name' => $member['name'] ?? 'Member',
                'member_phone' => $member['mobile'] ?? null,
                'groups' => $memberGroups
            ];
        }

        return [
            "success" => true,
            "message" => "Groups assigned successfully",
            "data" => ['invitations' => $invitations]
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
            ( MEMBER_ID, MEMBER_FULLNAME, MEMBER_PHONE, MEMBER_EMPCODE, GROUP_ID, GROUP_STATUS, CREATED_ON )
            VALUES
            ( :member_id, :member_name, :member_phone, :member_empcode, :group_id, 'PENDING', SYSDATE )";

        $stmt = oci_parse( $this->conn, $sql );

        oci_bind_by_name( $stmt, ":member_id", $member['id'] );
        oci_bind_by_name( $stmt, ":member_name", $member['name'] );
        oci_bind_by_name( $stmt, ":member_phone", $member['mobile'] );
        oci_bind_by_name( $stmt, ":member_empcode", $member['employeeCode'] );

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
        $sql = " SELECT * FROM PSR_TELEGRAM_MEMBERS WHERE EMPLOYEE_CODE = :employee_code AND ACTIVE_FLAG = 'Y' ";

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

        $mappingSql = "UPDATE PSR_MEMBER_GROUP_MAPPING SET GROUP_STATUS = 'JOINED', JOINED_ON = SYSDATE WHERE MEMBER_EMPCODE = :emp_code AND GROUP_ID = :group_id";

        $mappingStmt = oci_parse( $this->conn, $mappingSql );
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

    public function getPendingInvitationsForGroup($telegramGroupId)
    {
        $sql = "SELECT MGM.MEMBER_EMPCODE AS EMPLOYEE_CODE, MGM.INVITE_LINK
                FROM PSR_MEMBER_GROUP_MAPPING MGM
                INNER JOIN PSR_TELEGRAM_GROUPS TG ON TG.ID = MGM.GROUP_ID
                WHERE TG.TELEGRAM_GROUP_ID = :telegram_group_id
                  AND MGM.GROUP_STATUS = 'PENDING'
                  AND MGM.INVITE_LINK IS NOT NULL
                  AND (MGM.EXPIRES_ON IS NULL OR MGM.EXPIRES_ON > SYSDATE)";
        $stmt = $this->execute($sql, [':telegram_group_id' => $telegramGroupId]);
        return $this->fetchAll($stmt);
    }

    public function markInvitationJoined($employeeCode, $telegramGroupId, $telegramUserId)
    {
        $groupSql = 'SELECT ID FROM PSR_TELEGRAM_GROUPS WHERE TELEGRAM_GROUP_ID = :telegram_group_id';
        $groupStmt = $this->execute($groupSql, [':telegram_group_id' => $telegramGroupId]);
        $group = $this->fetchAll($groupStmt)[0] ?? null;
        if (!$group) {
            throw new Exception('Telegram group not found.');
        }

        $memberSql = "UPDATE PSR_TELEGRAM_MEMBERS
                      SET TELEGRAM_CHAT_ID = :telegram_user_id, TELEGRAM_JOINED = 1
                      WHERE EMPLOYEE_CODE = :employee_code";
        $this->execute($memberSql, [
            ':telegram_user_id' => $telegramUserId,
            ':employee_code' => $employeeCode,
        ]);

        $mappingSql = "UPDATE PSR_MEMBER_GROUP_MAPPING
                       SET GROUP_STATUS = 'JOINED', JOINED_ON = SYSDATE, TELEGRAM_USER_ID = :telegram_user_id
                       WHERE MEMBER_EMPCODE = :employee_code AND GROUP_ID = :group_id";
        $this->execute($mappingSql, [
            ':telegram_user_id' => $telegramUserId,
            ':employee_code' => $employeeCode,
            ':group_id' => $group['ID'],
        ]);

        return [
            'success' => true,
            'employee_code' => $employeeCode,
            'telegram_user_id' => $telegramUserId,
            'telegram_group_id' => $telegramGroupId,
            'group_id' => $group['ID'],
        ];
    }

    public function getInvitationForEmployeeGroup($employeeCode, $groupId)
    {
        $sql = "SELECT GROUP_STATUS, INVITE_LINK,
                   TO_CHAR(EXPIRES_ON, 'DD-MON-YYYY HH:MI AM') AS EXPIRES_AT,
                   CASE WHEN EXPIRES_ON > SYSDATE THEN 1 ELSE 0 END AS IS_ACTIVE
                FROM PSR_MEMBER_GROUP_MAPPING
                WHERE MEMBER_EMPCODE = :employee_code AND GROUP_ID = :group_id";
        $stmt = $this->execute($sql, [
            ':employee_code' => $employeeCode,
            ':group_id' => $groupId,
        ]);
        return $this->fetchAll($stmt)[0] ?? null;
    }

    public function saveEmployeeInvitation($employee, $groupId, $invitation)
    {
        $memberSql = 'SELECT ID FROM PSR_TELEGRAM_MEMBERS WHERE EMPLOYEE_CODE = :employee_code';
        $memberStmt = $this->execute($memberSql, [':employee_code' => $employee['employee_code']]);
        $member = $this->fetchAll($memberStmt)[0] ?? null;
        if (!$member) {
            $insertMemberSql = "INSERT INTO PSR_TELEGRAM_MEMBERS
                        (EMPLOYEE_CODE, MOBILE_NUMBER, FIRST_NAME, LAST_NAME,
                         DEPARTMENT_ID, DIVISION_ID, CREATED_ON)
                        VALUES (:employee_code, :mobile_number, :first_name, :last_name,
                            :department_id, :division_id, SYSDATE)";
            $this->execute($insertMemberSql, [
                ':employee_code' => $employee['employee_code'],
                ':mobile_number' => $employee['mobile_number'] ?? null,
                ':first_name' => $employee['first_name'] ?? '',
                ':last_name' => $employee['last_name'] ?? '',
                ':department_id' => $employee['department_id'] ?? null,
                ':division_id' => $employee['division_id'] ?? null,
            ]);
            $memberStmt = $this->execute($memberSql, [':employee_code' => $employee['employee_code']]);
            $member = $this->fetchAll($memberStmt)[0] ?? null;
        }
        if (!$member) {
            throw new Exception('Unable to register Telegram employee mapping.');
        }

        $existing = $this->getInvitationForEmployeeGroup($employee['employee_code'], $groupId);
        if (($existing['GROUP_STATUS'] ?? '') === 'JOINED') {
            return ['status' => 'JOINED', 'invite_link' => null, 'expires_at' => null];
        }

        $expiry = (int)$invitation['expires_at'];
        $bindings = [
            ':member_id' => $member['ID'],
            ':member_name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
            ':member_phone' => $employee['mobile_number'] ?? null,
            ':member_empcode' => $employee['employee_code'],
            ':group_id' => $groupId,
            ':invite_link' => $invitation['invite_link'],
            ':invite_hash' => $invitation['invite_hash'],
            ':expires_at' => $expiry,
        ];

        if ($existing) {
            $sql = "UPDATE PSR_MEMBER_GROUP_MAPPING
                    SET INVITE_LINK = :invite_link, INVITE_HASH = :invite_hash,
                        EXPIRES_ON = TO_DATE('1970-01-01', 'YYYY-MM-DD') + :expires_at / 86400,
                        GROUP_STATUS = 'PENDING', CREATED_ON = SYSDATE
                    WHERE MEMBER_EMPCODE = :member_empcode AND GROUP_ID = :group_id";
        } else {
            $sql = "INSERT INTO PSR_MEMBER_GROUP_MAPPING
                    (MEMBER_ID, MEMBER_FULLNAME, MEMBER_PHONE, MEMBER_EMPCODE, GROUP_ID,
                     GROUP_STATUS, INVITE_LINK, INVITE_HASH, EXPIRES_ON, CREATED_ON)
                    VALUES (:member_id, :member_name, :member_phone, :member_empcode, :group_id,
                            'PENDING', :invite_link, :invite_hash,
                            TO_DATE('1970-01-01', 'YYYY-MM-DD') + :expires_at / 86400, SYSDATE)";
        }
        if ($existing) {
            $bindings = [
                ':member_empcode' => $employee['employee_code'],
                ':group_id' => $groupId,
                ':invite_link' => $invitation['invite_link'],
                ':invite_hash' => $invitation['invite_hash'],
                ':expires_at' => $expiry,
            ];
        }
        $this->execute($sql, $bindings);

        return [
            'status' => 'GENERATED',
            'invite_link' => $invitation['invite_link'],
            'expires_at' => $expiry,
        ];
    }

    public function listInvitationsForEmployee($employeeCode)
    {
           $sql = "SELECT MGM.MAP_ID, TG.TITLE AS GROUP_NAME, MGM.INVITE_LINK,
                       TO_CHAR(MGM.EXPIRES_ON, 'DD-MON-YYYY HH:MI AM') AS EXPIRES_AT,
                       CASE WHEN MGM.GROUP_STATUS = 'PENDING' AND MGM.EXPIRES_ON <= SYSDATE
                           THEN 'EXPIRED' ELSE MGM.GROUP_STATUS END AS GROUP_STATUS,
                       CASE WHEN MGM.GROUP_STATUS = 'PENDING' AND MGM.EXPIRES_ON > SYSDATE
                           THEN 1 ELSE 0 END AS IS_ACTIVE
                FROM PSR_MEMBER_GROUP_MAPPING MGM
                INNER JOIN PSR_TELEGRAM_GROUPS TG ON TG.ID = MGM.GROUP_ID
                WHERE MGM.MEMBER_EMPCODE = :employee_code
                ORDER BY MGM.CREATED_ON DESC";
        $stmt = $this->execute($sql, [':employee_code' => $employeeCode]);
        return $this->fetchAll($stmt);
    }
   
}