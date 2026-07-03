<?php
require_once "cors.php";

session_set_cookie_params([
    'samesite' => 'None',
    'secure' => false // switch to true after HTTPS
]);

session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['emp_code'])){
    echo json_encode(["status"=>false,"message"=>"Not logged in"]);
    exit;
}

include "config/db.php";

$emp = $_SESSION['emp_code'];

$sql = "SELECT a.id, a.app_name, a.app_url, a.app_btn_id, a.app_icon
        FROM sdl_apps a
        JOIN sdl_app_access aa ON a.id = aa.app_id
        WHERE aa.emp_code = :e
        ORDER BY a.app_name";

$stid = oci_parse($login_conn,$sql);
oci_bind_by_name($stid,":e",$emp);  
oci_execute($stid);

$apps = [];
while($row = oci_fetch_assoc($stid)){
    $apps[] = $row;
}

echo json_encode([
    "status"=>true,
    "user"=>$_SESSION['name'],
    "apps"=>$apps
]);

exit;
?>
