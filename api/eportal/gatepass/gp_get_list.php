<?php

require_once "gp_head.php";

try {

    //$status = $data['status'] ?? '';

    // ============================
    // WHERE CLAUSE
    // ============================
    $where = "GP.CHG_BY = '".$empCode."'";

    // if (!empty($status) && $status !== "ALL") {
    //     $where .= " AND GP.STATUS = '".$status."'";
    // }

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
            GP.CHG_ON as created_on,
            UT.TRAN_CODE as TRNCD,
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
            WHERE EMP_CODE_FOR <> '" . $empCode . "' and ID <> '21'
        ) UT 
            ON UT.TRAN_CODE = TO_CHAR(GP.ID)  -- Fix for ORA-01722
        AND UT.RN = 1

        WHERE $where
    )";


    $GpDetails = multiRec($query);

    // ============================
    // FORMAT DATA
    // ============================
    $result = [];

    $currentDate = date('d-M-y');

    //print_r($GpDetails);

    foreach ($GpDetails as $gp) {

        $gpassDate = $gp['GPASS_DATE'] ?? '';

        // minimal date formatting
        $formattedDate = $gpassDate 
            ? date('d-M-Y', strtotime($gpassDate)) 
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
            "created_on" => $gp['CREATED_ON'],
            "dateTimePass" => $diff,
            "postremarks" => $gp["POST_REMARKS"] ?? '',
            "cancel" => $closeTicket,
            "authremarks" => $gp["AUTH_REMARKS"] ?? ""
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */
    if($result || !empty($result)){
        apiResponse(
            true,
            "Outdoor Duty fetched successfully.",
            $result
        );
    } else {
        apiResponse(false, "Unable to fetch outdoor duty data.", null, 404);
    }
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch outdoorduty slice data.", null, 500);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}