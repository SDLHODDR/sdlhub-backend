<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

require_once __DIR__ . "/../../config/functions.php";


/* ==========================================================
   RESPONSE HEADER
========================================================== */

header("Content-Type: application/json; charset=utf-8");


try {

    /* ==========================================================
       INPUT

       GET request:
       ?roomId=4&date=24-JUL-26&transactionId=1464

       Also support JSON body if required in future.
    ========================================================== */

    $jsonData = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($jsonData)) {
        $jsonData = [];
    }


    /*
     * GET takes priority because this API is called using GET.
     */

    $roomId =
        $_GET['roomId']
        ?? $_GET['ROOM_ID']
        ?? $_GET['room_id']
        ?? $jsonData['roomId']
        ?? $jsonData['ROOM_ID']
        ?? $jsonData['room_id']
        ?? '';


    $date =
        $_GET['date']
        ?? $_GET['DATE']
        ?? $jsonData['date']
        ?? $jsonData['DATE']
        ?? '';


    $transactionId =
        $_GET['transactionId']
        ?? $_GET['TRANSACTION_ID']
        ?? $_GET['transaction_id']
        ?? $jsonData['transactionId']
        ?? $jsonData['TRANSACTION_ID']
        ?? $jsonData['transaction_id']
        ?? '';


    /* ==========================================================
       TRIM INPUT
    ========================================================== */

    $roomId = trim((string) $roomId);
    $date = trim((string) $date);
    $transactionId = trim((string) $transactionId);


    /* ==========================================================
       VALIDATION
    ========================================================== */

    if ($roomId === '') {

        throw new Exception(
            "Room ID is required."
        );
    }


    if ($date === '') {

        throw new Exception(
            "Date is required."
        );
    }


    /* ==========================================================
       NORMALIZE DATE

       Supported:

       24-JUL-26
       24-JUL-2026
       24-07-26
       24-07-2026
       2026-07-24

       Oracle query will use:
       DD-MM-YYYY
    ========================================================== */

    $bookingDate = null;


    /*
     * ----------------------------------------------------------
     * FORMAT 1:
     * DD-MMM-YY
     *
     * Example:
     * 24-JUL-26
     * ----------------------------------------------------------
     */

    if (
        preg_match(
            '/^(\d{1,2})-([A-Za-z]{3})-(\d{2})$/',
            $date,
            $matches
        )
    ) {

        $day = str_pad(
            $matches[1],
            2,
            "0",
            STR_PAD_LEFT
        );

        $monthText =
            strtoupper($matches[2]);

        $year =
            (int) $matches[3];

        /*
         * Convert 26 -> 2026
         */
        $year =
            $year < 50
                ? 2000 + $year
                : 1900 + $year;


        $months = [
            "JAN" => "01",
            "FEB" => "02",
            "MAR" => "03",
            "APR" => "04",
            "MAY" => "05",
            "JUN" => "06",
            "JUL" => "07",
            "AUG" => "08",
            "SEP" => "09",
            "OCT" => "10",
            "NOV" => "11",
            "DEC" => "12",
        ];


        if (!isset($months[$monthText])) {

            throw new Exception(
                "Invalid date format."
            );
        }


        $month =
            $months[$monthText];


        $bookingDate =
            "{$day}-{$month}-{$year}";
    }


    /*
     * ----------------------------------------------------------
     * FORMAT 2:
     * DD-MMM-YYYY
     *
     * Example:
     * 24-JUL-2026
     * ----------------------------------------------------------
     */

    elseif (
        preg_match(
            '/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/',
            $date,
            $matches
        )
    ) {

        $day = str_pad(
            $matches[1],
            2,
            "0",
            STR_PAD_LEFT
        );

        $monthText =
            strtoupper($matches[2]);

        $year =
            $matches[3];


        $months = [
            "JAN" => "01",
            "FEB" => "02",
            "MAR" => "03",
            "APR" => "04",
            "MAY" => "05",
            "JUN" => "06",
            "JUL" => "07",
            "AUG" => "08",
            "SEP" => "09",
            "OCT" => "10",
            "NOV" => "11",
            "DEC" => "12",
        ];


        if (!isset($months[$monthText])) {

            throw new Exception(
                "Invalid date format."
            );
        }


        $month =
            $months[$monthText];


        $bookingDate =
            "{$day}-{$month}-{$year}";
    }


    /*
     * ----------------------------------------------------------
     * FORMAT 3:
     * DD-MM-YYYY
     *
     * Example:
     * 24-07-2026
     * ----------------------------------------------------------
     */

    elseif (
        preg_match(
            '/^(\d{1,2})-(\d{1,2})-(\d{4})$/',
            $date,
            $matches
        )
    ) {

        $day = str_pad(
            $matches[1],
            2,
            "0",
            STR_PAD_LEFT
        );

        $month = str_pad(
            $matches[2],
            2,
            "0",
            STR_PAD_LEFT
        );

        $year =
            $matches[3];


        if (
            (int) $month < 1 ||
            (int) $month > 12
        ) {

            throw new Exception(
                "Invalid month."
            );
        }


        if (
            (int) $day < 1 ||
            (int) $day > 31
        ) {

            throw new Exception(
                "Invalid day."
            );
        }


        $bookingDate =
            "{$day}-{$month}-{$year}";
    }


    /*
     * ----------------------------------------------------------
     * FORMAT 4:
     * DD-MM-YY
     *
     * Example:
     * 24-07-26
     * ----------------------------------------------------------
     */

    elseif (
        preg_match(
            '/^(\d{1,2})-(\d{1,2})-(\d{2})$/',
            $date,
            $matches
        )
    ) {

        $day = str_pad(
            $matches[1],
            2,
            "0",
            STR_PAD_LEFT
        );

        $month = str_pad(
            $matches[2],
            2,
            "0",
            STR_PAD_LEFT
        );

        $shortYear =
            (int) $matches[3];

        $year =
            $shortYear < 50
                ? 2000 + $shortYear
                : 1900 + $shortYear;


        if (
            (int) $month < 1 ||
            (int) $month > 12
        ) {

            throw new Exception(
                "Invalid month."
            );
        }


        $bookingDate =
            "{$day}-{$month}-{$year}";
    }


    /*
     * ----------------------------------------------------------
     * FORMAT 5:
     * YYYY-MM-DD
     *
     * Example:
     * 2026-07-24
     * ----------------------------------------------------------
     */

    elseif (
        preg_match(
            '/^(\d{4})-(\d{1,2})-(\d{1,2})$/',
            $date,
            $matches
        )
    ) {

        $year =
            $matches[1];

        $month = str_pad(
            $matches[2],
            2,
            "0",
            STR_PAD_LEFT
        );

        $day = str_pad(
            $matches[3],
            2,
            "0",
            STR_PAD_LEFT
        );


        if (
            (int) $month < 1 ||
            (int) $month > 12
        ) {

            throw new Exception(
                "Invalid month."
            );
        }


        if (
            (int) $day < 1 ||
            (int) $day > 31
        ) {

            throw new Exception(
                "Invalid day."
            );
        }


        $bookingDate =
            "{$day}-{$month}-{$year}";
    }


    else {

        throw new Exception(
            "Invalid date format. Expected DD-MMM-YY, DD-MM-YYYY or YYYY-MM-DD."
        );
    }


    /* ==========================================================
       DATABASE CONNECTION
    ========================================================== */

    $conn = db_eportal();


    if (!$conn) {

        throw new Exception(
            "Unable to connect to ePortal database."
        );
    }


    /* ==========================================================
       AVAILABILITY QUERY
    ========================================================== */

    $availabilitySql = "
        SELECT
            ID,
            ROOM_ID,
            TO_CHAR(
                ASON_DATE,
                'DD-MM-YYYY'
            ) AS AS_ON_DATE,

            TO_CHAR(
                START_TIME,
                'HH24:MI'
            ) AS START_TIME,

            TO_CHAR(
                END_TIME,
                'HH24:MI'
            ) AS END_TIME,

            BOOK_TIME,
            BOOK_BY_EMP,
            REMARKS,
            NOOF_ATTD,
            STATUS

        FROM EPT_CONF_ROOM_TRAN

        WHERE ROOM_ID = :room_id

          AND TRUNC(ASON_DATE) =
              TO_DATE(
                  :booking_date,
                  'DD-MM-YYYY'
              )

          AND STATUS <> 'R'
    ";


    /* ==========================================================
       EXCLUDE CURRENT TRANSACTION
    ========================================================== */

    if ($transactionId !== '') {

        $availabilitySql .= "
            AND ID <> :transaction_id
        ";
    }


    $availabilitySql .= "
        ORDER BY START_TIME
    ";


    /* ==========================================================
       PREPARE
    ========================================================== */

    $stmt =
        oci_parse(
            $conn,
            $availabilitySql
        );


    if (!$stmt) {

        $error =
            oci_error($conn);

        throw new Exception(
            $error['message']
            ?? "Unable to prepare availability query."
        );
    }


    /* ==========================================================
       BIND ROOM ID
    ========================================================== */

    oci_bind_by_name(
        $stmt,
        ":room_id",
        $roomId
    );


    /* ==========================================================
       BIND DATE
    ========================================================== */

    oci_bind_by_name(
        $stmt,
        ":booking_date",
        $bookingDate
    );


    /* ==========================================================
       BIND TRANSACTION ID
    ========================================================== */

    if ($transactionId !== '') {

        oci_bind_by_name(
            $stmt,
            ":transaction_id",
            $transactionId
        );
    }


    /* ==========================================================
       EXECUTE
    ========================================================== */

    $executed =
        oci_execute($stmt);


    if (!$executed) {

        $error =
            oci_error($stmt);

        throw new Exception(
            $error['message']
            ?? "Unable to load room availability."
        );
    }


    /* ==========================================================
       FETCH BOOKINGS
    ========================================================== */

    $bookings = [];


    while (
        $row =
            oci_fetch_assoc($stmt)
    ) {

        $bookings[] = [

            "ID" =>
                $row["ID"] ?? "",

            "ROOM_ID" =>
                $row["ROOM_ID"] ?? "",

            "AS_ON_DATE" =>
                $row["AS_ON_DATE"] ?? "",

            "START_TIME" =>
                $row["START_TIME"] ?? "",

            "END_TIME" =>
                $row["END_TIME"] ?? "",

            "BOOK_TIME" =>
                $row["BOOK_TIME"] ?? "",

            "BOOK_BY_EMP" =>
                $row["BOOK_BY_EMP"] ?? "",

            "REMARKS" =>
                $row["REMARKS"] ?? "",

            "NOOF_ATTD" =>
                $row["NOOF_ATTD"] ?? "",

            "STATUS" =>
                $row["STATUS"] ?? "",
        ];
    }


    /* ==========================================================
       FREE STATEMENT
    ========================================================== */

    oci_free_statement($stmt);


    /* ==========================================================
       RESPONSE
    ========================================================== */

    echo json_encode(
        [
            "status" => true,

            "room_id" =>
                $roomId,

            "date" =>
                $date,

            "booking_date" =>
                $bookingDate,

            "transaction_id" =>
                $transactionId,

            "bookings" =>
                $bookings,
        ],
        JSON_UNESCAPED_UNICODE
    );


} catch (Throwable $e) {

    http_response_code(500);


    echo json_encode(
        [
            "status" => false,

            "message" =>
                $e->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE
    );
}