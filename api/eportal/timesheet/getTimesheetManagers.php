<?php
/*
 * Reporting manager list for the timesheet task form: employees of the
 * logged-in employee's department (see tsManagerOptions in tsCommon.php).
 * Response: { deptName, defaultCode, options: [{ value, label }] }
 */

require_once __DIR__ . "/tsCommon.php";

try {
    $managers = tsManagerOptions($empCode);

    apiResponse(true, "Reporting managers loaded.", [
        "deptName"    => $managers['deptName'],
        "defaultCode" => $managers['defaultCode'],
        "options"     => $managers['options'],
    ]);
} catch (Throwable $e) {
    tsFail($e, "Unable to load the reporting managers.");
}
