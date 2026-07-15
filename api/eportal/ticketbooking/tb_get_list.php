<?php
require_once "tb_head.php";

$result = [];

// ============================
// PAGINATED QUERY
// ============================
$query = "
SELECT 
    REQ_DATE, ID, SITE_CODE, TRVL_CLASS, TRVL_EMP, EMP_CODE, PERSON_NAME,
    decode(TRVL_MODE , 'F','Flight','T','Train','B','Bus') TRVL_MODE,
    TRVL_DATE, TRVL_FROM_LOC, TRVL_TO_LOC, TRVL_FT_NAME, TRVL_FT_NO,
    EVENT_ID,
    to_char(TTNT_DEPR_TIME,'hh24:mi') TTNT_DEPR_TIME,
    to_char(TTNT_ARVL_TIME,'hh24:mi') TTNT_ARVL_TIME,
    REMARKS, STATUS, TRVL_TKT_ID
FROM epplive.BCS_TRVLTKT_REQUEST
WHERE REQ_BY = " . $empCode . "
ORDER BY ID DESC";

$TbrDetails = multiRec($query);

// ============================
// FORMAT RESPONSE
// ============================
if (!empty($TbrDetails)) {

    foreach ($TbrDetails as $tb) {

        $closeTicket = in_array($tb['STATUS'], ['A','R','T']) ? "Close" : "-";

        $authRemarks = "";
        if ($tb['STATUS'] == "R") {
            $remarksRow = singRec("
                SELECT REMARKS 
                FROM EPT_USER_TASKS 
                WHERE TRAN_CODE = '".$tb['ID']."' 
                ORDER BY ID DESC FETCH FIRST 1 ROWS ONLY
            ");
            $authRemarks = $remarksRow["REMARKS"] ?? "";
        }

        $result[] = [
            "id" => $tb['ID'] ?? '',
            "person_name" => $tb['PERSON_NAME'] ?? '',
            "trvl_mode" => $tb['TRVL_MODE'] ?? '',
            "trvl_date" => $tb['TRVL_DATE'] ?? '',
            "trvl_from_location" => $tb['TRVL_FROM_LOC'] ?? '',
            "trvl_to_loc" => $tb['TRVL_TO_LOC'] ?? '',
            "trvl_ft_name" => $tb['TRVL_FT_NAME'] ?? '',
            "trvl_ft_no" => $tb['TRVL_FT_NO'] ?? '',
            "ttnt_depr_time" => $tb['TTNT_DEPR_TIME'] ?? '',
            "status" => $tb['STATUS'] ?? '',
            "approval" => $statusMap[$tb['STATUS']] ?? $tb['STATUS'] ?? '',
            "statusColor" => $statusColorMap[$tb['STATUS']] ?? "secondary",
            "cancel" => $closeTicket,
            "authremarks" => $authRemarks,

            // IMPORTANT: total count for pagination
            "cnt" => $totalCount
        ];
    }
}

// ============================
// FINAL RESPONSE
// ============================
echo json_encode([
    "status" => true,
    "success" => true,
    "data" => $result,
]);