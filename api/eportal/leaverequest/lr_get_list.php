<?php

require_once "lr_head.php";
include_once('numbertoword.php');

$currency_object = new Currency;

$data = json_decode(
    file_get_contents("php://input"),
    true
);

try {

    /* ============================================================
       STATUS MAP
    ============================================================ */

    $decodeStat = [
        'A' => 'Approved',
        'N' => 'Pending from Admin',
        'R' => 'Rejected',
        'T' => 'In Process',
        'X' => 'Cancelled'
    ];


    /* ============================================================
       STATUS COLOR MAP
    ============================================================ */

    $statusColorMap = [
        'A' => 'success',
        'N' => 'warning',
        'R' => 'danger',
        'T' => 'info',
        'X' => 'secondary'
    ];


    /* ============================================================
       RESULT
    ============================================================ */

    $result = [];

    $cnt = 1;


    /* ============================================================
       LEAVE YEAR RANGE
       
       Current year + previous year

       Example:
       Current year = 2026

       From = 01-Jan-2025
       To   = 01-Jan-2027

       Therefore:
       2025 + 2026 records are included.
    ============================================================ */

    $currentYear = (int)date('Y');

    $fromYear = $currentYear - 1;

    $toYear = $currentYear + 1;


    $leaveFromDate =
        "01-01-" . $fromYear;

    $leaveToDate =
        "01-01-" . $toYear;


    /* ============================================================
       FETCH TEMP / UNAPPROVED LEAVES
       
       STATUS:
       T = In Process
       R = Rejected

       Only previous year + current year
    ============================================================ */

    $tourDetail_unapproved = multiRec("
        SELECT *
        FROM EPT_BCS_EMP_LEAVES_TEMP
        WHERE EMP_CODE = '" . addslashes($empCode) . "'
          AND STATUS IN ('T', 'R')
          AND LVE_DATE_FR >= TO_DATE(
              '" . $leaveFromDate . "',
              'DD-MM-YYYY'
          )
          AND LVE_DATE_FR < TO_DATE(
              '" . $leaveToDate . "',
              'DD-MM-YYYY'
          )
        ORDER BY
            LVE_DATE_FR DESC,
            ID DESC
    ");


    /* ============================================================
       PROCESS TEMP / UNAPPROVED LEAVES
    ============================================================ */

    if (!empty($tourDetail_unapproved)) {

        foreach ($tourDetail_unapproved as $tour) {

            $totalDays =
                (float)($tour['TOTAL_DAYS'] ?? 0);


            $no_of_days_text =
                ($totalDays == 0.5)
                    ? 'Half Day'
                    : $currency_object
                        ->get_number_to_text(
                            $totalDays
                        );


            $rawStatus =
                strtoupper(
                    trim(
                        $tour['STATUS'] ?? ''
                    )
                );


            $result[] = [

                "ID" =>
                    $tour['ID'] ?? '',


                "LVE_CODE" =>
                    ucwords(
                        trim(
                            $tour['LVE_CODE'] ?? ''
                        )
                    ),


                "NO_DAYS" =>
                    $no_of_days_text,


                "LVE_DATE_FR" =>
                    !empty($tour['LVE_DATE_FR'])
                        ? date(
                            'd-M-Y',
                            strtotime(
                                $tour['LVE_DATE_FR']
                            )
                        )
                        : '',


                "LVE_DATE_TO" =>
                    !empty($tour['LVE_DATE_TO'])
                        ? date(
                            'd-M-Y',
                            strtotime(
                                $tour['LVE_DATE_TO']
                            )
                        )
                        : '',


                "REMARKS" =>
                    $tour['REASON'] ?? '',


                /*
                 * Raw status
                 *
                 * A = Approved
                 * N = Pending from Admin
                 * R = Rejected
                 * T = In Process
                 */
                "status" =>
                    $rawStatus,


                /*
                 * Human-readable status
                 */
                "STATUS" =>
                    $decodeStat[$rawStatus]
                    ?? $rawStatus,


                "statusColor" =>
                    $statusColorMap[$rawStatus]
                    ?? "secondary",


                "type" =>
                    "unapproved",


                "cnt" =>
                    $cnt++

            ];
        }
    }


    /* ============================================================
       FETCH APPROVED / PROCESSED LEAVES
       
       STATUS:
       A = Approved
       N = Pending from Admin

       Only previous year + current year
    ============================================================ */

    $tourDetail_approved = multiRec("
        SELECT *
        FROM EPT_BCS_EMP_LEAVES
        WHERE EMP_CODE = '" . addslashes($empCode) . "'
          AND STATUS IN ('A', 'N')
          AND LVE_DATE_FR >= TO_DATE(
              '" . $leaveFromDate . "',
              'DD-MM-YYYY'
          )
          AND LVE_DATE_FR < TO_DATE(
              '" . $leaveToDate . "',
              'DD-MM-YYYY'
          )
        ORDER BY
            LVE_DATE_FR DESC,
            ID DESC
    ");


    /* ============================================================
       PROCESS APPROVED / PROCESSED LEAVES
    ============================================================ */

    if (!empty($tourDetail_approved)) {

        foreach ($tourDetail_approved as $tour) {

            $totalDays =
                (float)($tour['NO_DAYS'] ?? 0);


            $no_of_days_text =
                ($totalDays == 0.5)
                    ? 'Half Day'
                    : $currency_object
                        ->get_number_to_text(
                            $totalDays
                        );


            $rawStatus =
                strtoupper(
                    trim(
                        $tour['STATUS'] ?? ''
                    )
                );


            $result[] = [

                "ID" =>
                    $tour['ID'] ?? '',


                "LVE_CODE" =>
                    ucwords(
                        trim(
                            $tour['LVE_CODE'] ?? ''
                        )
                    ),


                "NO_DAYS" =>
                    $no_of_days_text,


                "LVE_DATE_FR" =>
                    !empty($tour['LVE_DATE_FR'])
                        ? date(
                            'd-M-Y',
                            strtotime(
                                $tour['LVE_DATE_FR']
                            )
                        )
                        : '',


                "LVE_DATE_TO" =>
                    !empty($tour['LVE_DATE_TO'])
                        ? date(
                            'd-M-Y',
                            strtotime(
                                $tour['LVE_DATE_TO']
                            )
                        )
                        : '',


                /*
                 * Existing table appears to use
                 * REMARKS for approved records.
                 */
                "REMARKS" =>
                    $tour['REMARKS']
                    ?? $tour['REASON']
                    ?? '',


                /*
                 * Raw status
                 */
                "status" =>
                    $rawStatus,


                /*
                 * Human-readable status
                 */
                "STATUS" =>
                    $decodeStat[$rawStatus]
                    ?? $rawStatus,


                "statusColor" =>
                    $statusColorMap[$rawStatus]
                    ?? "secondary",


                "type" =>
                    "approved",


                "cnt" =>
                    $cnt++

            ];
        }
    }


    /* ============================================================
       SORT ALL RECORDS
       
       Since records are coming from two different tables,
       sort the final combined result again.
       
       Newest leave date first.
    ============================================================ */

    usort(
        $result,
        function ($a, $b) {

            $dateA =
                strtotime(
                    $a['LVE_DATE_FR'] ?? ''
                );


            $dateB =
                strtotime(
                    $b['LVE_DATE_FR'] ?? ''
                );


            /*
             * If dates are same,
             * sort by ID descending.
             */
            if ($dateA === $dateB) {

                return
                    ((int)($b['ID'] ?? 0))
                    <=>
                    ((int)($a['ID'] ?? 0));
            }


            /*
             * Newest first
             */
            return $dateB <=> $dateA;

        }
    );


    /* ============================================================
       RE-CREATE COUNT AFTER SORT
    ============================================================ */

    foreach (
        $result as $index => &$row
    ) {

        $row['cnt'] =
            $index + 1;

    }

    unset($row);


    /* ============================================================
       TOTAL RECORDS
    ============================================================ */

    $totalRecords =
        count($result);


    /* ============================================================
       RESPONSE
    ============================================================ */

    if (!empty($result)) {

        apiResponse(
            true,
            "Leave Request fetched successfully.",
            [
                "data" =>
                    $result,

                "total" =>
                    $totalRecords
            ]
        );

    } else {

        /*
         * Empty result is still a successful API request.
         */
        apiResponse(
            true,
            "No leave requests found.",
            [
                "data" => [],

                "total" => 0
            ]
        );
    }


    exit;


} catch (Throwable $e) {

    /* ============================================================
       ERROR HANDLING
    ============================================================ */

    logOracleError($e);


    apiResponse(
        false,
        "Unable to fetch leave request data.",
        null,
        500
    );


} finally {

    /* ============================================================
       CLOSE ORACLE CONNECTION
    ============================================================ */

    if (
        isset($sql___func___con) &&
        $sql___func___con
    ) {

        oci_close(
            $sql___func___con
        );

    }

}