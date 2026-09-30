<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../repositories/GroupRepository.php';
require_once __DIR__ . '/../repositories/TeamSDLRepository.php';
require_once __DIR__ . '/../repositories/HrmsSDLRepository.php';
//require_once 'TelegramService.php';
require_once 'TelegramNodeService.php';

class GroupService
{
    private $repo;
    private $telegram;
    private $teamSDL;
    private $hrmsSDL;

    public function __construct()
    {
        $this->repo = new GroupRepository();
        //$this->teamSDL = new TeamSDLRepository();
        $this->telegram = new TelegramNodeService();
        $this->hrmsSDL = new HrmsSDLRepository();
    }

    public function create($payload)
    {
        try {
            $telegramResponse = $this->telegram->createGroup($payload);

            if ( !$telegramResponse || !isset($telegramResponse['success'])
            ) {
                throw new Exception(
                    'Telegram service unavailable'
                );
            }

            if (!$telegramResponse['success']) {
                throw new Exception(
                    $telegramResponse['message']
                );
            }

            $group = $telegramResponse['data'];

            $data = [
                'telegram_group_id' => $group['telegram_group_id'],
                'title' => $group['title'],
                'invite_link' => $group['invite_link'],
                'status' => 1,
                'members' => 2,
                'qr_code' => "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . $group['invite_link'],
                'payload' => $payload
            ];

            $this->repo->create($data);

            return $data;

        } catch (Exception $e) {
            
            throw new Exception(
                'Telegram Node Service is down. ' .
                $e->getMessage()
            );
        }        
    }

    public function list()
    {
        return $this->repo->getAll();
    }

    public function saveBroadcastMessage($data)
    {
        return $this->repo->saveBroadcastMessage($data);
    }

    // public function listDivHQ()
    // {
    //     $group = $this->repo->getAll();
        
    //     $groupData = [];
    //     foreach ($group as $key => $valGRP) {
    //         $groupData[$key] = $valGRP;

    //         if(isset($valGRP["DIVSN_ID"]) || $valGRP["DIVSN_ID"] !== "" || $valGRP["DIVSN_ID"] !== null){
    //             $groupData[$key]['DivisionLabel'] = $this->teamSDL->getDivisionLabel($valGRP["DIVSN_ID"])[0]['DIVSN_DESC'];
                
    //         }
    //         if(isset($valGRP["HQ_ID"]) || $valGRP["HQ_ID"] !== "" || $valGRP["HQ_ID"] !== null){
    //             $groupData[$key]['HQLabel'] = $this->teamSDL->getHQLabel($valGRP["DIVSN_ID"], $valGRP["HQ_ID"])[0]['GEONM'];
                
    //         }
    //     }
        
    //     return $groupData;
    // }

    public function listDivHQ()
    {
      $group = $this->repo->getAll();
      $groupData = [];
      foreach ($group as $key => $valGRP) {
        $groupData[$key] = $valGRP;
          /*
          |--------------------------------------------------------------------------
          | Division Label
          |--------------------------------------------------------------------------
          */

          $groupData[$key]['member_count'] = 0;
          $memberCnt = $this->repo->getMemberCount($valGRP["ID"]);
       
          if (!empty($memberCnt)) {
            $groupData[$key]['member_count'] = $memberCnt[0]['MEMBER_COUNT'];
          }

        //   if ( isset($valGRP["DIVSN_ID"]) && $valGRP["DIVSN_ID"] !== "" && $valGRP["DIVSN_ID"] !== null ) {
        //     $division = $this->teamSDL->getDivisionLabel($valGRP["DIVSN_ID"]);
        //     if (!empty($division)) {
        //       $groupData[$key]['DivisionLabel'] = $division[0]['DIVSN_DESC'];
        //     }
        //   }

          /*
          |--------------------------------------------------------------------------
          | HQ Label
          |--------------------------------------------------------------------------
          */

        //   $groupData[$key]['HQLabel'] = null;

        //   if (isset($valGRP["HQ_ID"]) && $valGRP["HQ_ID"] !== "" && $valGRP["HQ_ID"] !== null ) {
        //     $hq = $this->teamSDL->getHQLabel( $valGRP["DIVSN_ID"], $valGRP["HQ_ID"] );
        //     if (!empty($hq)) {
        //       $groupData[$key]['HQLabel'] = $hq[0]['GEONM'];
        //     }
        //   }
        }
        return $groupData;
    }

    public function listDivision($data)
    {
        return $this->hrmsSDL->getDivision($data);
    }

    public function listCompanies($data)
    {
        return $this->hrmsSDL->getCompanies($data);
    }

    public function listDepartments($data)
    {
        return $this->hrmsSDL->getDepartments($data);
    }

    public function listHRMSMembers($data)
    {
        return $this->hrmsSDL->getHRMSMembers($data);
    }

    public function listHQDivision($data)
    {
        return $this->teamSDL->getHQDivision($data);
    }
}