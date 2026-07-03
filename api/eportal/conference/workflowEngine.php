<?php

class workflowEngine
{

    private $config;

    public function __construct($workflow)
    {
        $this->config = require __DIR__."/".$workflow.".php";
    }

    /* ================= VALIDATE TRANSITION ================= */

    public function can($currentStatus, $action)
    {

        if (!isset($this->config['transitions'][$action])) {
            return false;
        }

        $allowedFrom = $this->config['transitions'][$action]['from'];

        return in_array($currentStatus, $allowedFrom);
    }

    /* ================= GET NEXT STATUS ================= */

    public function nextStatus($action)
    {

        if (!isset($this->config['transitions'][$action])) {
            return null;
        }

        return $this->config['transitions'][$action]['to'];
    }

}