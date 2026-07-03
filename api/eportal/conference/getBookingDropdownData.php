<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

try {

    // Booking By
    $empSql = "
        select EMP_CODE,
               (EMP_CODE || ' - ' || EMP_FNAME || ' ' || EMP_LNAME) as EMP_NAME
        from EPT_bcs_employee
        where status='A'
        order by 2
    ";

    $employees = multiRec($empSql);

    // Division
    $divSql = "
        select divsn_id, divsn_desc
        from EPT_hr_divisions
        order by 1
    ";

    $divisions = multiRec($divSql);

    echo json_encode([
        "status" => true,
        "employees" => $employees,
        "divisions" => $divisions
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}
