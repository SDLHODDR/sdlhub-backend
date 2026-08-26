<?php

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

try {

    /* ============================================================
       SESSION VALIDATION
    ============================================================ */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(
            false,
            "Unauthorized Access",
            null,
            401
        );
    }

    /* ============================================================
       FETCH CONFERENCE ROOMS
    ============================================================ */

    $rooms = multiRec("
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
        ORDER BY ID ASC
    ");

    /* ============================================================
       FORMAT RESPONSE
    ============================================================ */

    $conferenceRooms = [];

    foreach ($rooms as $row) {

        $conferenceRooms[] = [

            "id" => isset($row["ID"])
                ? (int)$row["ID"]
                : null,

            "roomLabel" => trim(
                (string)($row["ROOM_LABEL"] ?? "")
            ),

            "roomLocation" => trim(
                (string)($row["ROOM_LOCATION"] ?? "")
            ),

            "roomCapacity" => isset($row["ROOM_CAPACITY"])
                ? (int)$row["ROOM_CAPACITY"]
                : null,

            "roomFacility" => trim(
                (string)($row["ROOM_FACILITY"] ?? "")
            ),

            "teleExt" => trim(
                (string)($row["TELE_EXT"] ?? "")
            ),

            "status" => trim(
                (string)($row["STATUS"] ?? "")
            ),

            "reason" => trim(
                (string)($row["REASON"] ?? "")
            ),
        ];
    }

    /* ============================================================
       RESPONSE
    ============================================================ */

    apiResponse(
        true,
        "Conference rooms fetched successfully.",
        $conferenceRooms
    );

} catch (Throwable $e) {

    /* ============================================================
       LOG ERROR
    ============================================================ */

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getConferenceRoomMaintenance.php"
    );

    apiResponse(
        false,
        "Unable to fetch conference rooms.",
        null,
        500
    );
}
