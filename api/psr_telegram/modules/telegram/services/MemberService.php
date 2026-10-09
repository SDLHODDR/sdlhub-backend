<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../repositories/MemberRepository.php';
require_once __DIR__ . '/../repositories/TeamSDLRepository.php';
require_once __DIR__ . '/WhatsAppNodeService.php';

class MemberService
{
    private $repo;
    private $teamSDL;
    private $whatsApp;

    public function __construct()
    {
        $this->repo = new MemberRepository();
        //$this->teamSDL = new TeamSDLRepository();
        $this->whatsApp = new WhatsAppNodeService();
    }

   
    public function create($payload)
    {
      if (!isset($payload['members']) || !is_array($payload['members'])) {
        return false;
      }
      
      $payLoadArr = [];
        
      $payLoadArr["company_id"]    = $payload["company_id"];
      $payLoadArr["department_id"] = $payload["department_id"];
      $payLoadArr["division_id"]   = $payload["division_id"];
        
      foreach ($payload['members'] as $member) {
        $nameParts = explode(' ', trim($member['empName']), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        $payLoadArr["employee_code"] = $member['empCode'];
        $payLoadArr["mobile_number"] = $member['empPhone'];
        $payLoadArr["first_name"] = $firstName;   
        $payLoadArr["last_name"] = $lastName;   

        $this->repo->create($payLoadArr);
      }

      return true;
    }
    

    public function list()
    {
        return $this->repo->getAll();
    }

    public function listGroupMembers()
    {
        return $this->repo->getGroupMembers();
    }

    public function fetchMembers($data)
    {
        return $this->teamSDL->fetchMembers($data);

        //$this->repo->createMembers($members);

        //return true;
    }

    public function fetchGroupMembers($data)
    {
        return $this->repo->fetchGroupMembers($data);

        //$this->repo->createMembers($members);

        //return true;
    }

    public function saveMemberDM(
        $data
    )
    {
        return $this->repo
            ->saveMemberDM(
                $data
            );
    }

     public function fetchMembersGroups($data)
    {
        return $this->repo->fetchMembersGroups($data);

        //$this->repo->createMembers($members);

        //return true;
    }

    public function mapMembersGroups($data)
    {
        return $saveData = $this->repo->mapMembersGroups($data);
    }

    public function groupJoined( $data )
    {
        return $this->repo->groupJoined( $data );
    }

    public function saveMembersGroups($data)
    {
        $saveData = $this->repo->saveMembersGroups($data);
        if (empty($saveData['success'])) {
            return $saveData;
        }

        $results = [];
        foreach ($saveData['data']['invitations'] ?? [] as $invitation) {
            $message = '';
            foreach ($invitation['groups'] as $group) {
                $message .= "Telegram Group Invite: {$group['GROUP_NAME']}\n";
                $message .= $group['INVITE_LINK'] . "\n\n";
            }

            if ($message === '' || empty($invitation['member_phone'])) {
                $results[] = [
                    'member_id' => $invitation['member_id'],
                    'status' => 'FAILED',
                    'message' => $message === '' ? 'No group invite link is available.' : 'Employee phone number is missing.'
                ];
                continue;
            }

            $response = $this->whatsApp->inviteGroupMsg([
                'mobile' => $invitation['member_phone'],
                'message' => trim($message)
            ]);

            $sent = !empty($response['success']);
            $results[] = [
                'member_id' => $invitation['member_id'],
                'status' => $sent ? 'SENT' : 'FAILED',
                'message' => $response['message'] ?? null,
                'error_code' => $response['error_code'] ?? null
            ];
        }

        $allSent = !empty($results) && count(array_filter(
            $results,
            static fn($result) => $result['status'] !== 'SENT'
        )) === 0;

        return [
            'success' => $allSent,
            'message' => $allSent ? 'Telegram invitations sent.' : 'Some Telegram invitations could not be sent.',
            'data' => $results
        ];
    }

    public function listRegion()
    {
        /*
        // Step 1: Manager logic (reuse)
        $name  = singRec("
            SELECT epplive.hr_get_emp_mgr('".$empCode."', SYSDATE) AS EMP_CODE 
            FROM dual
        ");
        $name1 = findParentOrgEmp($empCode);
        $manager = !empty($name['EMP_CODE']) ? $name['EMP_CODE'] : $name1;
        
        // Step 2: Final query with categories
        $fetOPtions = getOptionsCustom("
            SELECT emp_code, emp_name, category
            FROM (

                -- Self
                SELECT 
                    '".$empCode."' AS emp_code,
                    epplive.get_emp_name('".$empCode."') AS emp_name,
                    'Self' AS category
                FROM dual

                UNION

                -- My Manager
                SELECT 
                    emp_code,
                    epplive.get_emp_name(emp_code),
                    'My Manager'
                FROM epplive.bcs_employee
                WHERE status = 'A'
                AND emp_code = '".$manager."'

                UNION

                -- My Colleagues
                SELECT 
                    emp_code,
                    epplive.get_emp_name(emp_code),
                    'My Colleague'
                FROM epplive.bcs_employee
                WHERE status = 'A'
                AND report_to = '".$manager."'
                AND emp_code <> '".$empCode."'

                UNION

                -- My Team
                SELECT 
                    emp_code,
                    epplive.get_emp_name(emp_code),
                    'My Team'
                FROM epplive.bcs_employee
                WHERE status = 'A'
                AND report_to = '".$empCode."'

            )
            ORDER BY 
                CASE category
                    WHEN 'Self' THEN 1
                    WHEN 'My Manager' THEN 2
                    WHEN 'My Colleague' THEN 3
                    WHEN 'My Team' THEN 4
                END,
                emp_name
        ");
        //print_r($fetOPtions);
        */

        return $this->repo->getAll();
    }
}