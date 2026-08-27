<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";

try {

    /* ==========================================================
       GET ACTIVE CONFERENCE ROOMS
    ========================================================== */

    $roomSql = "
        SELECT
            ID,
            ROOM_LABEL,
            ROOM_LOCATION,
            ROOM_CAPACITY,
            ROOM_FACILITY,
            TELE_EXT,
            STATUS,
            REASON
        FROM EPT_CONF_ROOMS
        WHERE STATUS = 'A'
        ORDER BY ROOM_LABEL
    ";

    $roomOptions = multiRec($roomSql);


    /* ==========================================================
       FORMAT RESPONSE
    ========================================================== */

    $rooms = [];

    foreach ($roomOptions as $room) {

        $facilities = [];

        /*
         * ROOM_FACILITY is stored in room master.
         *
         * Example:
         *
         * "Projector, Air Conditioner, White Board"
         *
         * Convert it into an array for React.
         */

        if (
            isset($room['ROOM_FACILITY']) && trim($room['ROOM_FACILITY']) !== '' ) {

            $facilityString = trim($room['ROOM_FACILITY']);

            /*
             * Support comma separated facilities.
             */

            $facilityParts = preg_split(
                '/\s*,\s*/',
                $facilityString
            );

            foreach ($facilityParts as $facility) {

                $facility = trim($facility);

                if ($facility !== '') {
                    $facilities[] = $facility;
                }
            }
        }

        $rooms[] = [
            "ID" => $room['ID'],
            "ROOM_LABEL" => $room['ROOM_LABEL'],
            "ROOM_LOCATION" => $room['ROOM_LOCATION'],
            "ROOM_CAPACITY" => $room['ROOM_CAPACITY'],
            "ROOM_FACILITY" => $room['ROOM_FACILITY'],
            "TELE_EXT" => $room['TELE_EXT'],
            "STATUS" => $room['STATUS'],
            "REASON" => $room['REASON'],
            "FACILITIES" => $facilities,
        ];
    }

    /* ==========================================================
       RESPONSE
    ========================================================== */

    echo json_encode([
        "status" => true,
        "room_options" => $rooms,
    ]);


} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage(),
    ]);
}

/*Response

{
  "status": true,
  "room_options": [
    {
      "ID": 1,
      "ROOM_LABEL": "Conference Room A",
      "ROOM_LOCATION": "First",
      "ROOM_CAPACITY": 30,
      "ROOM_FACILITY": "Projector, Air Conditioner, White Board",
      "TELE_EXT": "1234",
      "STATUS": "A",
      "REASON": null,
      "FACILITIES": [
        "Projector",
        "Air Conditioner",
        "White Board"
      ]
    }
  ]
} */    