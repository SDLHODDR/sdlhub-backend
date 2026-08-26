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
       REQUEST METHOD
    ============================================================ */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        apiResponse(
            false,
            "Invalid request method.",
            null,
            405
        );
    }

    /* ============================================================
       READ JSON
    ============================================================ */

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {

        apiResponse(
            false,
            "Invalid request data.",
            null,
            400
        );
    }

    /* ============================================================
       INPUT VALUES
    ============================================================ */

    $id = $input["id"] ?? null;

    $roomLabel = trim(
        (string)($input["roomLabel"] ?? "")
    );

    $roomLocation = trim(
        (string)($input["roomLocation"] ?? "")
    );

    $roomCapacity = $input["roomCapacity"] ?? null;

    $roomFacility = $input["roomFacility"] ?? [];

    $teleExt = trim(
        (string)($input["teleExt"] ?? "")
    );

    /* ============================================================
       VALIDATE ID
    ============================================================ */

    if (
        $id === null ||
        $id === "" ||
        !is_numeric($id)
    ) {

        apiResponse(
            false,
            "Invalid conference room ID.",
            null,
            422
        );
    }

    $id = (int)$id;

    /* ============================================================
       VALIDATE ROOM LABEL
    ============================================================ */

    if ($roomLabel === "") {

        apiResponse(
            false,
            "Room name is required.",
            null,
            422
        );
    }

    /* ============================================================
       VALIDATE LOCATION
    ============================================================ */

    $allowedLocations = [
        "5th Floor",
        "6th Floor"
    ];

    if (!in_array(
        $roomLocation,
        $allowedLocations,
        true
    )) {

        apiResponse(
            false,
            "Invalid room location.",
            null,
            422
        );
    }

    /* ============================================================
       VALIDATE CAPACITY
    ============================================================ */

    if (
        $roomCapacity === null ||
        $roomCapacity === "" ||
        !is_numeric($roomCapacity)
    ) {

        apiResponse(
            false,
            "Room capacity must be numeric.",
            null,
            422
        );
    }

    $roomCapacity = (int)$roomCapacity;

    if ($roomCapacity <= 0) {

        apiResponse(
            false,
            "Room capacity must be greater than zero.",
            null,
            422
        );
    }

    /* ============================================================
       VALIDATE FACILITIES
    ============================================================ */

    $allowedFacilities = [
        "Television",
        "Extension",
        "Air Condition",
        "White Board"
    ];

    if (!is_array($roomFacility)) {
        $roomFacility = [];
    }

    $roomFacility = array_values(
        array_unique(
            array_filter(
                array_map(
                    "trim",
                    $roomFacility
                )
            )
        )
    );

    foreach ($roomFacility as $facility) {

        if (!in_array(
            $facility,
            $allowedFacilities,
            true
        )) {

            apiResponse(
                false,
                "Invalid room facility selected.",
                null,
                422
            );
        }
    }

    /* ============================================================
       CONVERT FACILITIES TO COMMA SEPARATED STRING
    ============================================================ */

    $facilityString = implode(
        ",",
        $roomFacility
    );

    /* ============================================================
       ESCAPE STRING VALUES
    ============================================================ */

    $roomLabelEsc = str_replace(
        "'",
        "''",
        $roomLabel
    );

    $roomLocationEsc = str_replace(
        "'",
        "''",
        $roomLocation
    );

    $facilityStringEsc = str_replace(
        "'",
        "''",
        $facilityString
    );

    $teleExtEsc = str_replace(
        "'",
        "''",
        $teleExt
    );

    /* ============================================================
       CHECK ROOM EXISTS
    ============================================================ */

    $existingRoom = multiRec("
        SELECT
            ID,
            ROOM_LABEL
        FROM EPT_CONF_ROOMS
        WHERE ID = {$id}
    ");

    if (empty($existingRoom)) {

        apiResponse(
            false,
            "Conference room not found.",
            null,
            404
        );
    }

    /* ============================================================
       CHECK DUPLICATE ROOM NAME
    ============================================================ */

    $duplicateRoom = multiRec("
        SELECT
            ID
        FROM EPT_CONF_ROOMS
        WHERE UPPER(TRIM(ROOM_LABEL))
              = UPPER(TRIM('{$roomLabelEsc}'))
        AND ID <> {$id}
    ");

    if (!empty($duplicateRoom)) {

        apiResponse(
            false,
            "A conference room with this name already exists.",
            null,
            409
        );
    }

    /* ============================================================
       UPDATE ROOM
    ============================================================ */

    $updateSql = "
        UPDATE EPT_CONF_ROOMS
        SET
            ROOM_LABEL = '{$roomLabelEsc}',
            ROOM_LOCATION = '{$roomLocationEsc}',
            ROOM_CAPACITY = {$roomCapacity},
            ROOM_FACILITY = '{$facilityStringEsc}',
            TELE_EXT = '{$teleExtEsc}'
        WHERE ID = {$id}
    ";

    $stmt = oci_parse(
        $sql___func___con,
        $updateSql
    );

    if (!$stmt) {

        $error = oci_error(
            $sql___func___con
        );

        throw new Exception(
            $error["message"]
                ?? "Unable to prepare update query."
        );
    }

    if (!oci_execute(
        $stmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($stmt);

        throw new Exception(
            $error["message"]
                ?? "Unable to update conference room."
        );
    }

    /* ============================================================
       COMMIT
    ============================================================ */

    oci_commit(
        $sql___func___con
    );

    oci_free_statement($stmt);

    /* ============================================================
       LOG SUCCESS
    ============================================================ */

    logOracleError(
        [
            "message" =>
                "Conference room updated successfully. " .
                "ID: {$id}, " .
                "Room: {$roomLabel}, " .
                "Employee: {$empCode}"
        ],
        "saveConferenceRoom.php"
    );

    /* ============================================================
       RESPONSE
    ============================================================ */

    apiResponse(
        true,
        "Conference room updated successfully.",
        [
            "id" => $id
        ]
    );

} catch (Throwable $e) {

    /* ============================================================
       ROLLBACK
    ============================================================ */

    if (isset($sql___func___con)) {

        @oci_rollback(
            $sql___func___con
        );
    }

    /* ============================================================
       ERROR LOG
    ============================================================ */

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "saveConferenceRoom.php"
    );

    apiResponse(
        false,
        "Unable to update conference room.",
        null,
        500
    );
}

/*
{
    "id": 7,
    "roomLabel": "CF-602 + 603",
    "roomLocation": "6th Floor",
    "roomCapacity": 40,
    "roomFacility": "Television,Extension",
    "teleExt": "123"
}*/