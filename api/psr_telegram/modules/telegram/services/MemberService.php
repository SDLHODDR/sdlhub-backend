<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../repositories/MemberRepository.php';
require_once __DIR__ . '/../repositories/TeamSDLRepository.php';
require_once 'WhatsAppNodeService.php';

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
        // echo "<pre/>";
        // print_r($saveData);
        // exit;

        if ($saveData['success']) {
            try {
                // $payload = [
                //     'mobile' => $saveData['data']['member_phone'],
                //     'message' => $saveData['data']['groups']['invite_link']
                // ];

                // $telegramResponse = $this->whatsApp->inviteGroupMsg( $payload );

                $message = "";
                foreach ( $saveData['data']['groups'] as $group )
                {
                    $message .= "Telegram Group Invite:\n";
                    $message .= $group['INVITE_LINK'];
                    $message .= "\n\n";
                }
                $payload = [
                    'mobile' => $saveData['data']['member_phone'],
                    'message' => trim($message)
                ];
                
                $telegramResponse = $this->whatsApp->inviteGroupMsg( $payload );

                if (
                    !$telegramResponse ||
                    !isset($telegramResponse['success'])
                ) {
                    throw new Exception( 'WhatsApp service unavailable' );
                }

                return [
                    "success" => true,
                    "message" => "Groups invited successfully",
                ];
            } catch (Exception $e) {
                throw new Exception( 'WhatsApp Node Service is down. ' . $e->getMessage() );
            }
        }
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