<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

try {
    
    // Booking By
    $empSql = "
        select id, room_label
            from ept_conf_rooms
        where status='A'
        order by 2
    ";

    $roomOptions = multiRec($empSql);

    echo json_encode([
        "status" => true,
        "room_options" => $roomOptions,
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
