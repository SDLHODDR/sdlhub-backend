<?php
// Shared start-up (session, $data, $empCode, label maps)
require_once __DIR__ . "/../common/moduleHead.php";

/* Status of the Outdoor Duty task itself (used by getTable.php) */
$statusAuthColorMap = [
    'O' => 'warning',
    'C' => 'success',
    'X' => 'danger',
];

$statusAuthTextMap = [
    'O' => 'Open',
    'C' => 'Close',
    'X' => 'Cancel',
];
