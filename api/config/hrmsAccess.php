<?php
/*
|--------------------------------------------------------------------------
| HRMS PROFILE ACCESS
|--------------------------------------------------------------------------
| Task visibility is profile based (as in the legacy HRMS):
|   - A task with EMP_CODE_FOR set belongs only to that employee.
|   - An unassigned task (EMP_CODE_FOR IS NULL) is visible to users whose
|     profile has the task (HR_PROFILE_TASK) and whose profile scope covers
|     the task's company, division and department.
| Requires functions.php and an HRMS connection in $sql___func___con.
*/

// Authorization tasks that are not mapped in HR_PROFILE_TASK and are
// granted to every user with a company/division/department scope.
const HRMS_UNPROFILED_TASK_IDS = [56, 57];

/**
 * Load the user's active profile tasks and scope into the session
 * (taskId, compId, divId, deptId). Reloaded on every call so profile
 * changes apply without a re-login.
 */
function loadHrmsAccess($empCode)
{
    $empCode = str_replace("'", "''", trim((string)$empCode));
    $activeProfile = "EP.EMP_CODE = '{$empCode}'
        AND SYSDATE BETWEEN EP.EFFEC_FROM AND NVL(EP.EFFEC_TO, DATE '3000-03-01')";

    $column = static function ($rows, $key) {
        return array_values(array_unique(array_map('strval', array_column($rows ?: [], $key))));
    };

    $_SESSION['taskId'] = $column(multiRec("SELECT DISTINCT PT.TASK_ID
        FROM HR_PROFILE_TASK PT
        INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = PT.PROFILE_ID
        WHERE {$activeProfile}
          AND NVL(PT.STATUS, 'A') = 'A'
          AND SYSDATE BETWEEN NVL(PT.EFFEC_FROM, SYSDATE) AND NVL(PT.EFFEC_TO, DATE '3000-03-01')"), 'TASK_ID');

    $_SESSION['compId'] = $column(multiRec("SELECT DISTINCT PC.COMP_ID
        FROM HR_PROFILE_COMPANY PC
        INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = PC.PROFILE_ID
        WHERE {$activeProfile} AND PC.STATUS = 'A'"), 'COMP_ID');

    $_SESSION['divId'] = $column(multiRec("SELECT DISTINCT PD.DIVISION_ID
        FROM HR_PROFILE_DIVISIONS PD
        INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = PD.PROFILE_ID
        WHERE {$activeProfile} AND PD.STATUS = 'A'"), 'DIVISION_ID');

    $_SESSION['deptId'] = $column(multiRec("SELECT DISTINCT DE.DEPT_ID
        FROM HR_PROFILE_DEPARTMENT DE
        INNER JOIN HR_EMP_PROFILE EP ON EP.PROFILE_ID = DE.PROFILE_ID
        WHERE {$activeProfile} AND DE.STATUS = 'A'"), 'DEPT_ID');
}

/**
 * True when the session has a complete company/division/department scope.
 */
function hrmsHasScope()
{
    return !empty($_SESSION['compId']) && !empty($_SESSION['divId']) && !empty($_SESSION['deptId']);
}

/**
 * Task IDs the user may see when unassigned: profile tasks plus the
 * unprofiled authorization tasks (only with a full scope).
 */
function hrmsAllowedTaskIds()
{
    if (!hrmsHasScope()) {
        return [];
    }
    $ids = array_map('intval', $_SESSION['taskId'] ?? []);
    return array_values(array_unique(array_merge($ids, HRMS_UNPROFILED_TASK_IDS)));
}

/**
 * SQL condition (for alias $alias) limiting unassigned tasks to the
 * user's profile tasks and scope. Returns "1=0" when the user has none.
 */
function hrmsUnassignedTaskFilter($alias = 'TA')
{
    $taskIds = hrmsAllowedTaskIds();
    if (empty($taskIds)) {
        return '1=0';
    }
    $in = static function ($values) {
        return implode(',', array_map('intval', $values));
    };
    return "{$alias}.COMP_ID IN (" . $in($_SESSION['compId']) . ")"
        . " AND {$alias}.DIVSN_ID IN (" . $in($_SESSION['divId']) . ")"
        . " AND {$alias}.DEPT_ID IN (" . $in($_SESSION['deptId']) . ")"
        . " AND {$alias}.TASK_ID IN (" . $in($taskIds) . ")";
}

/**
 * Whether the user may act on an HR_USER_TASKS row
 * (needs TASK_ID, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID).
 */
function hrmsCanActOnTask(array $task, $empCode)
{
    $assignedTo = trim((string)($task['EMP_CODE_FOR'] ?? ''));
    if ($assignedTo !== '') {
        return $assignedTo === trim((string)$empCode);
    }
    return in_array((int)($task['TASK_ID'] ?? 0), hrmsAllowedTaskIds(), true)
        && in_array((string)($task['COMP_ID'] ?? ''), $_SESSION['compId'] ?? [], true)
        && in_array((string)($task['DIVSN_ID'] ?? ''), $_SESSION['divId'] ?? [], true)
        && in_array((string)($task['DEPT_ID'] ?? ''), $_SESSION['deptId'] ?? [], true);
}

/**
 * Find the user's open task row for a workflow task and transaction code.
 * Pass $userTaskId to target one HR_USER_TASKS row. Returns [] if the user
 * has no such task they may act on.
 */
function hrmsFindActionableTask($workflowTaskId, $tranCode, $empCode, $userTaskId = null)
{
    $rows = multiRec("SELECT ID, TASK_ID, TRAN_CODE, STATUS, EMP_CODE_FOR, COMP_ID, DIVSN_ID, DEPT_ID
        FROM HR_USER_TASKS
        WHERE TASK_ID = :task_id AND TRAN_CODE = :tran_code AND STATUS = 'O'
        ORDER BY ID DESC", [
        ':task_id' => (string)(int)$workflowTaskId,
        ':tran_code' => (string)$tranCode,
    ]);
    foreach ($rows ?: [] as $row) {
        if ($userTaskId !== null && (string)$row['ID'] !== (string)$userTaskId) {
            continue;
        }
        if (hrmsCanActOnTask($row, $empCode)) {
            return $row;
        }
    }
    return [];
}
