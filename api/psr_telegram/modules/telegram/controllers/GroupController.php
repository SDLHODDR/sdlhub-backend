<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../services/GroupService.php';
require_once __DIR__ . '/../../../core/Response.php';

class GroupController
{
    private $service;

    public function __construct()
    {
        $this->service = new GroupService();
    }

    public function create()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        //echo "--------Payload----------<br/>"; print_r($payload); exit;

        $result = $this->service->create($payload);

        Response::json(true, 'Group created', $result);
    }

    public function list()
    {
        //$data = $this->service->list();
        $data = $this->service->listDivHQ();    
        Response::json(true, 'Groups fetched', $data);
    }

    public function listRegion()
    {
        $data = $this->service->list();

        Response::json(true, 'Groups fetched', $data);
    }

    public function listDivisions()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->listDivision($payload);

        Response::json(true, 'Division fetched', $data);
    }

    public function listDepartments()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->listDepartments($payload);

        Response::json(true, 'Department fetched', $data);
    }

    public function saveBroadcastMessage()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->saveBroadcastMessage($payload);

        Response::json(true, 'Broadcast message saved', $data);
    }

    public function getHRMSMembers()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->listHRMSMembers($payload);

        Response::json(true, 'Members fetched', $data);
    }
    

    public function listCompanies()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->listCompanies($payload);

        Response::json(true, 'Companies fetched', $data);
    }

    public function gettDivisionHQs()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->listHQDivision($payload);

        Response::json(true, 'Division fetched', $data);
    }

}