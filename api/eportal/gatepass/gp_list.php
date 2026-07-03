<?php
require_once "gp_head.php";

// ============================
// INPUT
// ============================
$page  = isset($data['page']) ? (int)$data['page'] : 1;
$limit = isset($data['limit']) ? (int)$data['limit'] : 10;
$search = $data['search'] ?? '';
$status = $data['status'] ?? '';

$offset = ($page - 1) * $limit;

// ============================
// WHERE CLAUSE
// ============================
$where = "GP.CHG_BY = '".$empCode."'";

if (!empty($status) && $status !== "ALL") {
    $where .= " AND GP.STATUS = '".$status."'";
}

if (!empty($search)) {
    $search = strtoupper($search);
    $where .= " AND (
        UPPER(GP.REMARKS) LIKE '%$search%' 
        OR UPPER(GP.OUT_TYPE) LIKE '%$search%'
    )";
}

// ============================
// TOTAL COUNT
// ============================
$totalRow = singRec("
    SELECT COUNT(*) AS TOTAL 
    FROM EPT_EMPLOYEE_GPASS GP
    WHERE $where
");

$totalRecords = $totalRow['TOTAL'] ?? 0;

// ============================
// MAIN QUERY (NO N+1)
// ============================
$query = "
SELECT * FROM (
    SELECT 
        GP.ID,
        GP.GPASS_DATE,
        GP.OUT_TYPE,
        GP.STATUS,
        GP.REMARKS,
        GP.POST_REMARKS,
        UT.REMARKS AS AUTH_REMARKS,

        ROW_NUMBER() OVER (
            ORDER BY GP.ID DESC
        ) RN

    FROM EPT_EMPLOYEE_GPASS GP

    -- Get latest remark per TRAN_CODE
    LEFT JOIN (
        SELECT 
            TRAN_CODE,
            REMARKS,
            ROW_NUMBER() OVER (
                PARTITION BY TRAN_CODE 
                ORDER BY ID DESC
            ) RN
        FROM EPT_USER_TASKS
    ) UT 
        ON UT.TRAN_CODE = TO_CHAR(GP.ID)  -- Fix for ORA-01722
       AND UT.RN = 1

    WHERE $where
)
WHERE RN > $offset AND RN <= ".($offset + $limit);
// $query = "
// SELECT * FROM (
//     SELECT 
//         GP.ID,
//         GP.GPASS_DATE,
//         GP.OUT_TYPE,
//         GP.STATUS,
//         GP.REMARKS,
//         GP.POST_REMARKS,
//         UT.REMARKS AS AUTH_REMARKS,

//         ROW_NUMBER() OVER (
//             ORDER BY GP.GPASS_DATE DESC) RN

//     FROM EPT_EMPLOYEE_GPASS GP

//     -- Get latest remark per TRAN_CODE
//     LEFT JOIN (
//         SELECT 
//             TRAN_CODE,
//             REMARKS,
//             ROW_NUMBER() OVER (
//                 PARTITION BY TRAN_CODE 
//                 ORDER BY ID DESC
//             ) RN
//         FROM EPT_USER_TASKS
//     ) UT 
//         ON UT.TRAN_CODE = TO_CHAR(GP.ID)  -- Fix for ORA-01722
//        AND UT.RN = 1

//     WHERE $where
//     ORDER BY GP.ID DESC
// )
// WHERE RN > $offset AND RN <= ".($offset + $limit);

$GpDetails = multiRec($query);

// ============================
// FORMAT DATA
// ============================
$result = [];

$currentDate = date('d-M-y');

foreach ($GpDetails as $gp) {

    $gpassDate = $gp['GPASS_DATE'] ?? '';

    // minimal date formatting
    $formattedDate = $gpassDate 
        ? date('d M Y', strtotime($gpassDate)) 
        : '';

    $diff = strtotime($gpassDate) - strtotime($currentDate);

    $closeTicket = (
        in_array($gp['STATUS'], ['A','R','T']) && $diff >= 0
    ) ? "Close" : "-";

    $result[] = [
        "id" => $gp['ID'],
        "asondate" => $formattedDate,
        "outtype" => $outTypeMap[$gp['OUT_TYPE']] ?? $gp['OUT_TYPE'],
        "approval" => $statusMap[$gp['STATUS']] ?? $gp['STATUS'],
        "statusColor" => $statusColorMap[$gp['STATUS']] ?? "secondary",
        "remarks" => $gp['REMARKS'] ?? '',
        "status" => $gp['STATUS'],
        "dateTimePass" => $diff,
        "postremarks" => $gp["POST_REMARKS"] ?? '',
        "cancel" => $closeTicket,
        "authremarks" => $gp["AUTH_REMARKS"] ?? ""
    ];
}

// ============================
// RESPONSE
// ============================
echo json_encode([
    "status" => true,
    "data" => $result,
    "total" => $totalRecords
]);

ob_end_flush();