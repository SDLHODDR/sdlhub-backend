<?php

require_once __DIR__ . '/../services/MemberService.php';
require_once __DIR__ . '/../../../core/Response.php';

class MemberController
{
    private $service;

    public function __construct()
    {
        $this->service = new MemberService();
    }

    public function create()
    {
        $payload = json_decode(file_get_contents("php://input"), true);

        $result = $this->service->create($payload);

        Response::json(true, 'Member added', $result);
    }

    public function saveMembers()
    {
        $payload = json_decode( file_get_contents("php://input"), true );

        $result = $this->service->create($payload);

        Response::json( true, 'Members added', $result );
    }

    public function saveMembersGroups()
    {
        $payload = json_decode( file_get_contents("php://input"), true );

        $result = $this->service->saveMembersGroups($payload);

        Response::json( true, 'Members added', $result );
    }

    public function mapMembersGroups()
    {
        $payload = json_decode( file_get_contents("php://input"), true );

        $result = $this->service->mapMembersGroups($payload);

        Response::json( true, 'Members added', $result );
    }

    public function groupJoined(){
        $payload = json_decode( file_get_contents("php://input"), true );

        $result = $this->service->groupJoined( $payload );

        Response::json( true, 'Group Joined', $result );
}

    public function list()
    {
        $result = $this->service->list();

        Response::json( true, 'Members fetched', $result );
    }

    public function listGroupMembers()
    {
        $result = $this->service->listGroupMembers();

        Response::json( true, 'Members fetched', $result );
    }

    public function getMembers()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->fetchMembers($payload);

        Response::json(true, 'Members fetched', $data);
    }

    public function getGroupMembers()
    {
        $payload = json_decode(
            file_get_contents(
                "php://input"
            ),
            true
        );

        $data = $this->service
            ->fetchGroupMembers(
                $payload
            );

        Response::json(
            true,
            'Members fetched',
            $data
        );
    }

    public function saveMemberDM()
    {
        $payload = json_decode(
            file_get_contents(
                "php://input"
            ),
            true
        );

        $result =
            $this->service
                ->saveMemberDM(
                    $payload
                );

        Response::json(
            true,
            'Message queued',
            $result
        );
    }

    public function fetchMemberGroups()
    {
        $payload = json_decode(file_get_contents("php://input"), true);
        
        $data = $this->service->fetchMembersGroups($payload);

        Response::json(true, 'Members fetched', $data);
    }

    public function listRegion()
    {
        $data = $this->service->listRegion();
        Response::json(true, 'Members fetched', $data);
    }

    public function verifyJoin()
    {
        $memberId = $_GET['member_id'];

        $groupId = $_GET['group_id'];

        $repo = new MemberGroupRepository();

        $status = $repo->getStatus( $memberId, $groupId );

        Response::json(true, 'Status fetched', $status);
    }
}