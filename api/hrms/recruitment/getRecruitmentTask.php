<?php
/* GET ?task_id=2|5&user_task_id= -> the request behind a task, and the recruiters for task 5.
 * Legacy recruitment_req.php opened from a task. */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    [$task, $request] = recruitmentLoadTask($_GET['task_id'] ?? 0, $_GET['user_task_id'] ?? 0, $empCode);
    session_write_close();

    $recruiters = (int)$task['TASK_ID'] === RECRUITMENT_TASK_ASSIGN ? recruitmentRecruiters() : [];

    return ['Task fetched successfully.', [
        'request' => [
            'id' => $request['ID'],
            'title' => $request['REC_TITLE'] ?? '',
            'organogram' => $request['ORGANOGRAM'] ?? '',
            'location' => $request['GEO_DESC'] ?? '',
            'positionCount' => $request['POSI_COUNT'] ?? '',
            'positionsOccupied' => $request['FILL_COUNT'] ?? '',
            'requiredPositions' => $request['REQ_COUNT'] ?? '',
            'raisedOn' => $request['REQ_DATE'] ?? '',
            'raisedBy' => $request['RAISED_BY'] ?? '',
            'tillDate' => $request['REQ_BY_DATE'] ?? '',
            'remarks' => $request['REMARKS'] ?? '',
            'authRemark' => $request['AUTH_REMARK'] ?? '',
        ],
        'recruiters' => array_map(static fn($row) => ['value' => $row['EMP_CODE'], 'label' => $row['LABEL']], $recruiters),
    ]];
}, 'getRecruitmentTask.php');
