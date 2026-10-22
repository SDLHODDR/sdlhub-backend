<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../services/InvitationService.php';
require_once __DIR__ . '/../../../core/Response.php';

class InvitationController
{
    private $service;

    public function __construct()
    {
        $this->service = new InvitationService();
    }

    public function generate()
    {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $result = $this->service->generate($payload);
        Response::json(true, 'Employee invitations processed', $result);
    }

    public function mine()
    {
       $employeeCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
        $result = $this->service->mine((string)$employeeCode);
        Response::json(true, 'Employee invitations fetched', $result);
    }
}