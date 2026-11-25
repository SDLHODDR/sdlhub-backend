<?php

/*
 * GET ?q=<text>[&status=active|all] -> up to 50 employees matching the text.
 *
 * Used by the employee search fields (EmployeeSearchSelect) instead of loading every employee.
 * The text is split into words (at most 4); each word must appear in the employee code or in
 * the name, in any order and case, so "pat har", "harsha p" and "1234" all match.
 * Exact code matches come first, then codes / first names starting with the text, then by name.
 * status=active (default) returns only active employees; status=all returns everyone with the
 * status letter (A active, R resigned, N / T new joiners).
 */

define('CURRENT_PORTAL', 'hrms');

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

const EMPLOYEE_SEARCH_MIN_LENGTH = 3;
const EMPLOYEE_SEARCH_LIMIT      = 50;

if (empty($_SESSION['emp_code'])) {
    apiResponse(false, "Session expired. Please login again.", null, 401);
}
if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}
session_write_close();

$text = trim(preg_replace('/\s+/u', ' ', (string)($_GET['q'] ?? '')));
if (mb_strlen($text) < EMPLOYEE_SEARCH_MIN_LENGTH) {
    apiResponse(false, "Type at least " . EMPLOYEE_SEARCH_MIN_LENGTH . " characters to search.", [], 422);
}
$text = mb_substr($text, 0, 100);
$activeOnly = (string)($_GET['status'] ?? 'active') !== 'all';

// LIKE patterns: %, _ and \ in the typed text are matched literally (ESCAPE '\').
$like = static fn(string $value): string => addcslashes(mb_strtoupper($value), '%_\\');

$binds = [
    ':q_exact'  => mb_strtoupper($text),
    ':q_prefix' => $like($text) . '%',
];

$wordConditions = [];
foreach (array_slice(explode(' ', $text), 0, 4) as $i => $word) {
    $binds[":w$i"] = '%' . $like($word) . '%';
    $wordConditions[] = "(UPPER(EMP_CODE) LIKE :w$i ESCAPE '\\' OR UPPER(FNAME || ' ' || MNAME || ' ' || LNAME) LIKE :w$i ESCAPE '\\')";
}

$statusCondition = $activeOnly ? "AND NVL(STATUS, 'A') = 'A'" : '';

$sql = "
    SELECT * FROM (
        SELECT ID, EMP_CODE, FNAME, MNAME, LNAME, STATUS
        FROM HR_EMPLOYEE_INFO
        WHERE EMP_CODE IS NOT NULL
          $statusCondition
          AND " . implode("\n          AND ", $wordConditions) . "
        ORDER BY
            CASE
                WHEN UPPER(EMP_CODE) = :q_exact THEN 0
                WHEN UPPER(EMP_CODE) LIKE :q_prefix ESCAPE '\\' THEN 1
                WHEN UPPER(FNAME) LIKE :q_prefix ESCAPE '\\' THEN 2
                ELSE 3
            END,
            FNAME, MNAME, LNAME
    )
    WHERE ROWNUM <= " . EMPLOYEE_SEARCH_LIMIT;

try {
    $rows = multiRec($sql, $binds);

    $data = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $name = implode(' ', array_filter(
            [trim((string)($row['FNAME'] ?? '')), trim((string)($row['MNAME'] ?? '')), trim((string)($row['LNAME'] ?? ''))],
            static fn($part) => $part !== '' && $part !== '.'
        ));

        $data[] = [
            'ID'       => (string)($row['ID'] ?? ''),
            'EMP_CODE' => (string)($row['EMP_CODE'] ?? ''),
            'NAME'     => $name,
            'STATUS'   => (string)($row['STATUS'] ?? ''),
        ];
    }

    apiResponse(true, "Employees fetched successfully.", $data, 200);
} catch (Throwable $e) {
    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine(),
        ],
        "searchEmployees.php"
    );

    apiResponse(false, "Unable to search employees.", null, 500);
} finally {
    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}
