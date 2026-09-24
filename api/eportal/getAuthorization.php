<?php

require_once "gatepass/gp_head.php";

header("Content-Type: application/json; charset=UTF-8");

try {
    // 1. Session / Short In-Memory Cache (Optional: Returns instant count if fetched within 30 seconds)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $cacheKey = "AUTH_TASK_COUNT_" . ($empCode ?? '');
    if (!empty($_SESSION[$cacheKey]) && (time() - ($_SESSION[$cacheKey]['time'] ?? 0) < 30)) {
        session_write_close();
        header("Cache-Control: private, max-age=15");
        apiResponse(
            true,
            "Authorization data fetched from cache.",
            $_SESSION[$cacheKey]['data']
        );
        exit;
    }
    session_write_close();

    /*
    |--------------------------------------------------------------------------
    | OPTIMIZED SINGLE AGGREGATION QUERY
    |--------------------------------------------------------------------------
    | 1. Selects ONLY task_id instead of full rows (eut.*).
    | 2. Uses single-pass scanning with direct index-friendly filters.
    | 3. Eliminates duplicate table joins and replaces DISTINCT * with direct COUNT.
    */
    $sql = "
        WITH active_tasks AS (
            -- Branch 1: Directly assigned open tasks
            SELECT eut.TASK_ID
            FROM EPT_USER_TASKS eut
            WHERE eut.EMP_CODE_FOR = :emp_code
              AND eut.STATUS = 'O'
            
            UNION
            
            -- Branch 2: Role/Profile-based open tasks
            SELECT eut.TASK_ID
            FROM EPT_USER_TASKS eut
            INNER JOIN EPT_PROFILE_TASK ept 
                ON ept.TASK_ID = eut.TASK_ID
            WHERE eut.EMP_CODE_FOR IS NULL
              AND eut.STATUS = 'O'
              AND ept.PROFILE_ID IN (
                  SELECT ep.PROFILE_ID 
                  FROM EPT_EMP_PROFILE ep 
                  WHERE ep.EMP_CODE = :emp_code
              )
        )
        SELECT 
            t.TASK_ID,
            etm.TASK_DESC,
            COUNT(*) AS TOTAL
        FROM active_tasks t
        INNER JOIN EPT_TASK_MASTER etm 
            ON etm.ID = t.TASK_ID
        GROUP BY 
            t.TASK_ID,
            etm.TASK_DESC
        ORDER BY t.TASK_ID
    ";

    $stmt = oci_parse($sql___func___con, $sql);
    if (!$stmt) {
        $err = oci_error($sql___func___con);
        throw new Exception($err['message']);
    }

    // Prefetch all grouped categories in one network roundtrip
    oci_set_prefetch($stmt, 50);

    // Bind parameter safely to enable Oracle execution plan caching (Soft Parsing)
    oci_bind_by_name($stmt, ":emp_code", $empCode);

    if (!oci_execute($stmt)) {
        $err = oci_error($stmt);
        throw new Exception($err['message']);
    }

    $authTasksData = [];
    $overAllCnt = 0;

    while ($row = oci_fetch_assoc($stmt)) {
        $count = (int)$row['TOTAL'];
        $overAllCnt += $count;

        $authTasksData[] = [
            'TASK_ID'   => $row['TASK_ID'],
            'TASK_DESC' => $row['TASK_DESC'],
            'TOTAL'     => $count,
        ];
    }
    oci_free_statement($stmt);

    $responseData = [
        "success"  => true,
        "taskscnt" => $authTasksData,
        "SUBTOTAL" => $overAllCnt
    ];

    // Store in session cache
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION[$cacheKey] = [
        'time' => time(),
        'data' => $responseData
    ];
    session_write_close();

    header("Cache-Control: private, max-age=15");

    if (!empty($authTasksData)) {
        apiResponse(true, "Authorization data fetched successfully.", $responseData);
    } else {
        apiResponse(false, "Unable to fetch authorization duty data.", null, 200);
    }

} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch authorization slice data.", null, 500);
} finally {
    if (isset($sql___func___con) && $sql___func___con) {
        oci_close($sql___func___con);
    }
}