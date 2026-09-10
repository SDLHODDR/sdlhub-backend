<?php

define('CURRENT_PORTAL', 'hrms');

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

try {

    $sql = "
        SELECT
            ID,
            EMP_CODE,
            FNAME,
            MNAME,
            LNAME,
            STATUS
        FROM HR_EMPLOYEE_INFO
        ORDER BY FNAME, MNAME, LNAME
    ";

    $employees = multiRec($sql);

    $data = [];

    foreach ($employees as $employee) {

        $nameParts = array_filter([
            trim($employee['FNAME'] ?? ''),
            trim($employee['MNAME'] ?? ''),
            trim($employee['LNAME'] ?? '')
        ], function ($value) {
            return $value !== '' && $value !== '.';
        });

        $fullName = implode(' ', $nameParts);

        $data[] = [
            'ID'       => $employee['ID'] ?? '',
            'EMP_CODE' => $employee['EMP_CODE'] ?? '',
            'FNAME'    => $employee['FNAME'] ?? '',
            'MNAME'    => $employee['MNAME'] ?? '',
            'LNAME'    => $employee['LNAME'] ?? '',
            'STATUS'   => $employee['STATUS'] ?? '',
            'EMP_NAME' => trim(
                $fullName . ' - ' . ($employee['EMP_CODE'] ?? '')
            )
        ];
    }

    apiResponse(
        true,
        'Employees fetched successfully.',
        $data,
        200
    );

} catch (Throwable $e) {

    error_log('Get Employees Error: ' . $e->getMessage());

    apiResponse(
        false,
        'Unable to fetch employees.',
        null,
        500
    );
}