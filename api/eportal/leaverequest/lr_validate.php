<?php

require_once "lr_head.php";


/* ============================================================
   CL VALIDATION
   ============================================================ */

if (isset($data['ClValidate']) && $data['ClValidate'] == true) {

    try {

        $empCode = trim($data['EMP_CODE'] ?? '');
        $frDt    = trim($data['fr_dt'] ?? '');
        $toDt    = trim($data['to_dt'] ?? '');


        /* --------------------------------------------------------
           Basic validation
        -------------------------------------------------------- */

        if ($empCode === '') {

            apiResponse(
                false,
                "Employee code is required.",
                [
                    "data" => 0
                ],
                200
            );

            exit;
        }


        if ($frDt === '' || $toDt === '') {

            apiResponse(
                false,
                "From Date and To Date are required.",
                [
                    "data" => 0
                ],
                200
            );

            exit;
        }


        /* --------------------------------------------------------
           Create date objects
        -------------------------------------------------------- */

        $fromDate = DateTime::createFromFormat(
            'Y-m-d',
            $frDt
        );

        $toDate = DateTime::createFromFormat(
            'Y-m-d',
            $toDt
        );


        if (
            !$fromDate ||
            !$toDate ||
            $fromDate->format('Y-m-d') !== $frDt ||
            $toDate->format('Y-m-d') !== $toDt
        ) {

            apiResponse(
                false,
                "Invalid leave dates.",
                [
                    "data" => 0
                ],
                200
            );

            exit;
        }


        /* --------------------------------------------------------
           From date cannot be after To date
        -------------------------------------------------------- */

        if ($fromDate > $toDate) {

            apiResponse(
                false,
                "From Date cannot be greater than To Date.",
                [
                    "data" => 0
                ],
                200
            );

            exit;
        }


        /* ========================================================
           IMPORTANT:
           Calculate inclusive number of leave days.

           21-Aug -> 21-Aug = 1 day
           21-Aug -> 22-Aug = 2 days
           21-Aug -> 23-Aug = 3 days
           ======================================================== */

        $dateDifference = $fromDate->diff($toDate);

        $requestedDays = ((int)$dateDifference->days) + 1;


        /* --------------------------------------------------------
           CL maximum 2 days per application
        -------------------------------------------------------- */

        if ($requestedDays > 2) {

            apiResponse(
                false,
                "CL can not be taken for more than 2 days!",
                [
                    "data" => 1,
                    "validation" => "CL_MAX_DAYS",
                    "requested_days" => $requestedDays,
                    "from_date" => $frDt,
                    "to_date" => $toDt
                ],
                200
            );

            exit;
        }


        /* ========================================================
           MONTH RANGE
           ======================================================== */

        $monthStart = $fromDate->format('Y-m-01');
        $monthEnd   = $fromDate->format('Y-m-t');


        /* ========================================================
           COUNT CL APPLICATIONS

           Existing approved/current leaves
           +
           Pending temporary leaves
           ======================================================== */

        $clLeaves = singRec("
            SELECT COUNT(*) AS CNT
            FROM
            (
                SELECT ID
                FROM EPT_BCS_EMP_LEAVES
                WHERE EMP_CODE = '" . addslashes($empCode) . "'
                  AND LVE_CODE = 'CL'
                  AND STATUS NOT IN ('X', 'R')
                  AND LVE_DATE_FR <= TO_DATE(
                        '" . $monthEnd . "',
                        'YYYY-MM-DD'
                  )
                  AND LVE_DATE_TO >= TO_DATE(
                        '" . $monthStart . "',
                        'YYYY-MM-DD'
                  )

                UNION ALL

                SELECT ID
                FROM EPT_BCS_EMP_LEAVES_TEMP
                WHERE EMP_CODE = '" . addslashes($empCode) . "'
                  AND LVE_CODE = 'CL'
                  AND STATUS NOT IN ('X', 'R')
                  AND LVE_DATE_FR <= TO_DATE(
                        '" . $monthEnd . "',
                        'YYYY-MM-DD'
                  )
                  AND LVE_DATE_TO >= TO_DATE(
                        '" . $monthStart . "',
                        'YYYY-MM-DD'
                  )
            )
        ");


        $clCount = (int)($clLeaves['CNT'] ?? 0);


        /* ========================================================
           MAX 3 CL APPLICATIONS PER MONTH
           ======================================================== */

        if ($clCount >= 3) {

            apiResponse(
                false,
                "CL can not be taken more than thrice in a month!",
                [
                    "data" => 1,
                    "validation" => "CL_MONTHLY_COUNT",
                    "cl_count" => $clCount,
                    "requested_days" => $requestedDays
                ],
                200
            );

            exit;
        }


        /* ========================================================
           SUCCESS
           ======================================================== */

        apiResponse(
            true,
            "CL Leaves verified.",
            [
                "data" => 1,
                "validation" => "SUCCESS",
                "cl_count" => $clCount,
                "requested_days" => $requestedDays
            ],
            200
        );

        exit;


    } catch (Throwable $e) {

        error_log(
            "CL Validation Error: " .
            $e->getMessage()
        );

        apiResponse(
            false,
            "Unable to validate CL leave.",
            [
                "data" => 0,
                "error" => $e->getMessage()
            ],
            500
        );

        exit;
    }
}


/* ============================================================
   OPTIONAL LEAVE
   ============================================================ */

if (isset($data['OlValidate']) && $data['OlValidate'] == true) {

    try {

        $empCode  = trim($data['EMP_CODE'] ?? '');
        $attdDate = trim($data['attd_date'] ?? '');


        if ($empCode === '' || $attdDate === '') {

            apiResponse(
                false,
                "Employee code and date are required.",
                [
                    "data" => 0
                ],
                200
            );

            exit;
        }


        $holVal = singRec("
            SELECT *
            FROM EPT_BCS_HOLIDAYS
            WHERE HOL_TYPE = 'O'
              AND HOL_DATE = TO_DATE(
                    '" . addslashes($attdDate) . "',
                    'YYYY-MM-DD'
              )
              AND HOL_GRP IN
              (
                  SELECT HOL_TBLNO
                  FROM EPT_BCS_EMPLOYEE
                  WHERE EMP_CODE = '" . addslashes($empCode) . "'
              )
        ");


        if (!empty($holVal['HOL_GRP'])) {

            apiResponse(
                true,
                "Optional Leave is applicable.",
                [
                    "data" => 1
                ],
                200
            );

        } else {

            apiResponse(
                true,
                "Optional Leave is not applicable.",
                [
                    "data" => 0
                ],
                200
            );
        }

        exit;


    } catch (Throwable $e) {

        error_log(
            "OL Validation Error: " .
            $e->getMessage()
        );

        apiResponse(
            false,
            "Unable to validate Optional Leave.",
            [
                "data" => 0
            ],
            500
        );

        exit;
    }
}


/* ============================================================
   INVALID REQUEST
   ============================================================ */

apiResponse(
    false,
    "Invalid leave validation request.",
    null,
    400
);

exit;