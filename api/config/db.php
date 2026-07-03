<?php

/******** Central Login DB ********/
$login_conn = oci_connect("sdlusers","google10","192.168.20.150:1521/ora19csdl");
if(!$login_conn){ die("Central DB connection failed"); }

/******** App DB connections ********/
function db_eportal(){
    return oci_connect("eportal","google10","192.168.20.150:1521/ora19csdl");
}
function db_hrms(){
    return oci_connect("hrmslive","google10","192.168.20.150:1521/ora19csdl");
}
function db_epplive(){
    return oci_connect("epplive","google10","192.168.20.150:1521/ora19csdl");
}
function db_teamsdl(){
    return oci_connect("teamsdl","google10","192.168.20.150:1521/ora19csdl");
}
function db_eppprod(){
    return oci_pconnect('epplive', 'DXt2XqxcNB', '192.168.10.111:1521/orcl');
}

function getDBConnection($dbName) {
    $dbConfig = [
        'sdlusers' => ['user' => 'sdlusers', 'pass' => 'google10', 'conn' => '192.168.20.150:1521/ora19csdl'],
        'eportal'  => ['user' => 'eportal', 'pass' => 'google10', 'conn' => '192.168.20.150:1521/ora19csdl'],
        'hrms'     => ['user' => 'hrmslive', 'pass' => 'google10', 'conn' => '192.168.20.150:1521/ora19csdl'],
        'epp'  => ['user' => 'epplive', 'pass' => 'google10', 'conn' => '192.168.20.150:1521/ora19csdl'],
        'sfm'  => ['user' => 'teamsdl', 'pass' => 'google10', 'conn' => '192.168.20.150:1521/ora19csdl'],
    ];

    if (!isset($dbConfig[$dbName])) {
        die("Invalid DB Name: $dbName");
    }

    $conf = $dbConfig[$dbName];
    $con = oci_pconnect($conf['user'], $conf['pass'], $conf['conn']);

    if (!$con) {
        $e = oci_error();
        die("Database connection failed for $dbName: " . $e['message']);
    }
    
    return $con;
}

function closeDBConnection($con)
{
    // Check if valid connection
    if ($con) {
        @oci_close($con);  
    }
}
?>
