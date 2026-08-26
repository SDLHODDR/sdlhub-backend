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
       INPUT
    ============================================================ */

    $id = $input["id"] ?? null;

    $status = strtoupper(
        trim(
            (string)($input["status"] ?? "")
        )
    );

    $reason = trim(
        (string)($input["reason"] ?? "")
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
       VALIDATE STATUS
    ============================================================ */

    if (!in_array(
        $status,
        ["A", "I"],
        true
    )) {

        apiResponse(
            false,
            "Invalid room status.",
            null,
            422
        );
    }

    /* ============================================================
       REASON REQUIRED WHEN INACTIVE
    ============================================================ */

    if ($status === "I" && $reason === "") {

        apiResponse(
            false,
            "Reason is required when making the room inactive.",
            null,
            422
        );
    }

    /* ============================================================
       LIMIT REASON
    ============================================================ */

    if (strlen($reason) > 500) {

        apiResponse(
            false,
            "Reason cannot exceed 500 characters.",
            null,
            422
        );
    }

    /* ============================================================
       ESCAPE REASON
    ============================================================ */

    $reasonEsc = str_replace(
        "'",
        "''",
        $reason
    );

    /* ============================================================
       CHECK ROOM
    ============================================================ */

    $room = multiRec("
        SELECT
            ID,
            ROOM_LABEL,
            STATUS
        FROM EPT_CONF_ROOMS
        WHERE ID = {$id}
    ");

    if (empty($room)) {

        apiResponse(
            false,
            "Conference room not found.",
            null,
            404
        );
    }

    $roomLabel = $room[0]["ROOM_LABEL"] ?? "";

    /* ============================================================
       UPDATE STATUS
    ============================================================ */

    if ($status === "I") {

        $updateSql = "
            UPDATE EPT_CONF_ROOMS
            SET
                STATUS = 'I',
                REASON = '{$reasonEsc}'
            WHERE ID = {$id}
        ";

    } else {

        /*
         * When room becomes active again,
         * the previous inactive reason is cleared.
         */

        $updateSql = "
            UPDATE EPT_CONF_ROOMS
            SET
                STATUS = 'A',
                REASON = NULL
            WHERE ID = {$id}
        ";
    }

    /* ============================================================
       EXECUTE UPDATE
    ============================================================ */

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
                ?? "Unable to prepare status update."
        );
    }

    if (!oci_execute(
        $stmt,
        OCI_NO_AUTO_COMMIT
    )) {

        $error = oci_error($stmt);

        throw new Exception(
            $error["message"]
                ?? "Unable to update room status."
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
       STATUS TEXT
    ============================================================ */

    $statusText =
        $status === "A"
            ? "Active"
            : "Inactive";

    /* ============================================================
       LOG
    ============================================================ */

    logOracleError(
        [
            "message" =>
                "Conference room status updated. " .
                "ID: {$id}, " .
                "Room: {$roomLabel}, " .
                "Status: {$statusText}, " .
                "Reason: {$reason}, " .
                "Employee: {$empCode}"
        ],
        "updateConferenceRoomStatus.php"
    );

    /* ============================================================
       RESPONSE
    ============================================================ */

    apiResponse(
        true,
        "Conference room marked as {$statusText}.",
        [
            "id" => $id,
            "status" => $status,
            "statusText" => $statusText,
            "reason" => $status === "I"
                ? $reason
                : ""
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
        "updateConferenceRoomStatus.php"
    );

    apiResponse(
        false,
        "Unable to update conference room status.",
        null,
        500
    );
}