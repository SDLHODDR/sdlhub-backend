<?php

require_once __DIR__ . '/Database.php';

class BaseRepository
{
    protected $conn;

    public function __construct()
    {
        $this->conn = Database::connect();
    }

    protected function execute($sql, $bindings = [])
    {
        $stmt = oci_parse($this->conn, $sql);
        
        foreach ($bindings as $key => $value) {
            oci_bind_by_name($stmt, $key, $bindings[$key]);
        }
        
        $result = oci_execute($stmt);

        if (!$result) {

            $e = oci_error($stmt);

            throw new Exception($e['message']);
        }

        return $stmt;
    }

    protected function fetchAll($stmt)
    {
        $data = [];

        while ($row = oci_fetch_assoc($stmt)) {
            $data[] = $row;
        }

        return $data;
    }
}