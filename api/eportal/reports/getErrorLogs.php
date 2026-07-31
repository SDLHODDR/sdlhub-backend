<?php

ob_start();

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json");

$response = [
    "status"  => false,
    "logs"    => [],
    "logDate" => "",
    "message" => ""
];

$conn = null;
$stmt = null;

try {

    /* ==========================================================
       SESSION VALIDATION
    ========================================================== */

    if (!isset($_SESSION["emp_code"])) {
        apiResponse(false, "Unauthorized Access", null, 401);
    }

    /* ==========================================================
       DATABASE
    ========================================================== */

    $conn = db_eportal();

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    /* ==========================================================
       INPUT
    ========================================================== */

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        $input = [];
    }

    /*
     * Supports both:
     *
     * POST JSON:
     * {
     *     "logDate": "2026-07-16"
     * }
     *
     * GET:
     * ?logDate=2026-07-16
     */

    $selectedDate =
        $input["logDate"]
        ?? $_GET["logDate"]
        ?? date("Y-m-d");

    /* ==========================================================
       DATE VALIDATION
    ========================================================== */

    $dt = DateTime::createFromFormat(
        "Y-m-d",
        $selectedDate
    );

    if (
        !$dt ||
        $dt->format("Y-m-d") !== $selectedDate
    ) {
        throw new Exception(
            "Invalid date. Expected YYYY-MM-DD."
        );
    }

    $oracleDate  = $dt->format("d-m-Y");
    $displayDate = $dt->format("d-M-Y");

    /* ==========================================================
       EXECUTE PROCEDURE
    ========================================================== */

    $nval = null;
    $vval = null;

    $sql = "
        DECLARE

            nval NUMBER;
            vval VARCHAR2(4000);

        BEGIN

            error_log.read(
                TO_DATE(:pDate, 'DD-MM-YYYY'),
                :nval,
                :vval
            );

        END;
    ";

    $stmt = oci_parse($conn, $sql);

    if (!$stmt) {

        $e = oci_error($conn);

        throw new Exception(
            "Procedure parse error: " .
            ($e["message"] ?? "Unknown error")
        );
    }

    oci_bind_by_name(
        $stmt,
        ":pDate",
        $oracleDate,
        20
    );

    oci_bind_by_name(
        $stmt,
        ":nval",
        $nval,
        20,
        SQLT_INT
    );

    oci_bind_by_name(
        $stmt,
        ":vval",
        $vval,
        4000
    );

    if (!oci_execute($stmt)) {

        $e = oci_error($stmt);

        throw new Exception(
            "error_log.read failed: " .
            ($e["message"] ?? "Unknown error")
        );
    }

    oci_free_statement($stmt);
    $stmt = null;

    /* ==========================================================
       PROCEDURE STATUS
    ========================================================== */

    if ((int)$nval !== 0) {

        throw new Exception(
            !empty(trim((string)$vval))
                ? trim($vval)
                : "Unable to read log file."
        );
    }

    /* ==========================================================
       NO LOG
    ========================================================== */

    if (empty(trim((string)$vval))) {

        $response = [
            "status"  => true,
            "logDate" => $displayDate,
            "total"   => 0,
            "logs"    => [],
            "message" => ""
        ];

        echo json_encode($response);
        exit;
    }

    /* ==========================================================
       CONVERT VVAL TO LINES
    ========================================================== */

    $lines = preg_split(
        '/\r\n|\r|\n/',
        $vval
    );

    /* ==========================================================
       PARSE LOGS
    ========================================================== */

    $logs = [];
    $current = [];
    $id = 1;

    foreach ($lines as $text) {

        $text = trim($text);

        if ($text === "") {
            continue;
        }

        /* ------------------------------------------------------
           SEPARATOR
        ------------------------------------------------------ */

        if (preg_match('/^-{5,}$/', $text)) {

            continue;
        }

        /* ------------------------------------------------------
           ERROR TIME
        ------------------------------------------------------ */

        if (preg_match(
            '/^Error at\s*:\s*(.*)$/i',
            $text,
            $m
        )) {

            /*
             * Save previous record
             */

            if (!empty($current)) {

                $current["line"] =
                    $current["line"] ?? "";

                $current["message"] =
                    $current["message"] ?? "";

                $current["file"] =
                    $current["file"] ?? "";

                $current["sql"] =
                    $current["sql"] ?? "";

                preg_match(
                    '/ORA-\d+/i',
                    $current["message"],
                    $err
                );

                $current["errorCode"] =
                    $err[0] ?? "";

                $current["id"] = $id++;

                $logs[] = $current;
            }

            /*
             * Start new record
             */

            $current = [];

            $current["time"] =
                trim($m[1]);

            continue;
        }

        /* ------------------------------------------------------
           LOGIN USER
        ------------------------------------------------------ */

        if (preg_match(
            '/^Login User\s*:\s*(.*)$/i',
            $text,
            $m
        )) {

            $current["user"] =
                trim($m[1]);

            continue;
        }

        /* ------------------------------------------------------
           LINE NO + MESSAGE
        ------------------------------------------------------ */

        if (preg_match(
            '/^Line No\s*:\s*(.*?)\s+Message\s*:\s*(.*)$/i',
            $text,
            $m
        )) {

            $current["line"] =
                trim($m[1]);

            $message =
                trim($m[2]);

            $current["message"] =
                $message;

            $current["file"] =
                $current["file"] ?? "";

            $current["sql"] =
                $current["sql"] ?? "";

            continue;
        }

        /* ------------------------------------------------------
           LINE NO ONLY
        ------------------------------------------------------ */

        if (preg_match(
            '/^Line No\s*:\s*(.*)$/i',
            $text,
            $m
        )) {

            $current["line"] =
                trim($m[1]);

            continue;
        }

        /* ------------------------------------------------------
           MESSAGE ONLY
        ------------------------------------------------------ */

        if (preg_match(
            '/^Message\s*:\s*(.*)$/i',
            $text,
            $m
        )) {

            $current["message"] =
                trim($m[1]);

            continue;
        }

        /* ------------------------------------------------------
           HELP + FILE + SQL
        ------------------------------------------------------ */

        if (preg_match(
            '/^Help\s*:\s*(.*?)\s*\|\s*File\s*:\s*(.*?)\s*\|\s*SQL\s*:\s*(.*)$/i',
            $text,
            $m
        )) {

            $current["help"] =
                trim($m[1]);

            $current["file"] =
                trim($m[2]);

            $current["sql"] =
                trim($m[3]);

            $current["readingSql"] =
                true;

            continue;
        }

        /* ------------------------------------------------------
           SQL CONTINUATION
        ------------------------------------------------------ */

        if (!empty($current["readingSql"])) {

            if (preg_match('/^-{5,}$/', $text)) {

                unset(
                    $current["readingSql"]
                );

                continue;
            }

            if (
                !isset($current["sql"]) ||
                $current["sql"] === ""
            ) {

                $current["sql"] =
                    $text;

            } else {

                $current["sql"] .=
                    PHP_EOL . $text;
            }

            continue;
        }

        /* ------------------------------------------------------
           SQL SEPARATOR
        ------------------------------------------------------ */

        if (strpos($text, "| SQL :") !== false) {

            $parts = explode(
                "| SQL :",
                $text,
                2
            );

            $current["sql"] =
                trim($parts[1] ?? "");

            $current["readingSql"] =
                true;

            continue;
        }
    }

    /* ==========================================================
       SAVE LAST RECORD
    ========================================================== */

    if (!empty($current)) {

        $current["line"] =
            $current["line"] ?? "";

        $current["message"] =
            $current["message"] ?? "";

        $current["file"] =
            $current["file"] ?? "";

        $current["sql"] =
            $current["sql"] ?? "";

        preg_match(
            '/ORA-\d+/i',
            $current["message"],
            $err
        );

        $current["errorCode"] =
            $err[0] ?? "";

        $current["id"] =
            $id++;

        unset(
            $current["readingSql"]
        );

        $logs[] = $current;
    }

    /* ==========================================================
       SUCCESS RESPONSE
    ========================================================== */

    $response = [
        "status"  => true,
        "logDate" => $displayDate,
        "total"   => count($logs),
        "logs"    => array_reverse($logs),
        "message" => ""
    ];

}
catch (Throwable $e) {

    http_response_code(500);

    $response = [
        "status"  => false,
        "logs"    => [],
        "logDate" => "",
        "message" => $e->getMessage()
    ];
}
finally {

    if ($stmt) {
        @oci_free_statement($stmt);
    }

    if ($conn) {
        @oci_close($conn);
    }

    ob_clean();

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}