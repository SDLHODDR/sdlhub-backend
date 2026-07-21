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

try {

    /* ==========================================================
       SESSION VALIDATION
    ========================================================== */

    if (!isset($_SESSION["emp_code"])) {
       apiResponse(false,"Unauthorized Access",null,401);
    }

    /* ==========================================================
       DATABASE CONNECTION
    ========================================================== */

    $conn = db_eportal();

    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    /* ==========================================================
       READ INPUT JSON
    ========================================================== */

    $input = json_decode(file_get_contents("php://input"), true);

    $selectedDate = $input["logDate"] ?? date("Y-m-d");
    
   /* echo json_encode([
        "receivedDate" => $selectedDate
    ]);
    exit; */

    $dt = DateTime::createFromFormat("Y-m-d", $selectedDate);

    if (!$dt) {
        $dt = new DateTime();
    }

    $oracleDate = $dt->format("d-m-Y"); 

    echo "selectedDate: ".$selectedDate;
     echo "oracleDate: ".$oracleDate; exit;

    $displayDate = $dt->format("d-M-Y");

    /* ==========================================================
       ENABLE DBMS OUTPUT
    ========================================================== */

    $stmt = oci_parse($conn, "
        BEGIN
            DBMS_OUTPUT.ENABLE(NULL);
        END;
    ");

    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        throw new Exception($e["message"]);
    }

    oci_free_statement($stmt);

    /* ==========================================================
        EXECUTE PROCEDURE
    ========================================================== */

    $stmt = oci_parse($conn, "
        BEGIN
            error_log.read(
                TO_DATE(:pDate,'DD-MM-YYYY'),
                :retVal,
                :retStr
            );
        END;
    ");

    $retVal = 0;
    $retStr = "";

    oci_bind_by_name($stmt, ":pDate", $oracleDate);
    oci_bind_by_name($stmt, ":retVal", $retVal, -1, SQLT_INT);
    oci_bind_by_name($stmt, ":retStr", $retStr, 4000);

    if (!oci_execute($stmt)) {

        $e = oci_error($stmt);
        throw new Exception($e["message"]);
    }

    oci_free_statement($stmt);

    /* ==========================================================
    PROCEDURE STATUS
    ========================================================== */

    if ($retVal != 0) {

        throw new Exception(
            !empty(trim($retStr))
                ? trim($retStr)
                : "Unable to read log file."
        );
    }

    /* ==========================================================
       PREPARE DBMS_OUTPUT.GET_LINE
    ========================================================== */

    $stmt = oci_parse($conn, "

        BEGIN

            DBMS_OUTPUT.GET_LINE(
                :line,
                :status
            );

        END;

    ");

    $line = "";

    $status = 0;

    oci_bind_by_name(
        $stmt,
        ":line",
        $line,
        32767
    );

    oci_bind_by_name(
        $stmt,
        ":status",
        $status
    );

    $lines = [];

    /* ==========================================================
       READ ENTIRE OUTPUT
    ========================================================== */

    while (true) {

        oci_execute($stmt);

        if ($status != 0) {
            break;
        }

        $text = trim($line);

        if ($text !== "") {
            $lines[] = html_entity_decode($text);
        }
    }

    oci_free_statement($stmt);

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

        /* ---------- New Error ---------- */

        if (strpos($text, "Error at") === 0) {

            if (!empty($current)) {

                if (!isset($current["line"])) {
                    $current["line"] = "";
                }

                if (!isset($current["message"])) {
                    $current["message"] = "";
                }

                preg_match('/ORA-\d+/', $current["message"], $err);
                $current["errorCode"] = $err[0] ?? "";
                $current["id"] = $id++;
                $logs[] = $current;
            }

            $current = [];
            preg_match('/Error at\s*:\s*(.*)/', $text, $m);
            $current["time"] = trim($m[1] ?? "");
            continue;
        }

        /* ---------- Login User ---------- */

        if (strpos($text, "Login User") === 0) {
            preg_match('/Login User\s*:\s*(.*)/', $text, $m);
            $current["user"] = trim($m[1] ?? "");
            continue;
        }

        /* ---------- Line + Message ---------- */

        if (strpos($text, "Line No") === 0) {

            if (
                preg_match(
                    '/Line No\s*:\s*(.*?)\s*Message\s*:\s*(.*)/',
                    $text,
                    $m
                )
            ) {

                $current["line"] = trim($m[1]);

                $message = trim($m[2]);
                $current["message"] = $message;

                $current["file"] = "";
                $current["sql"] = "";

                if (preg_match('/\|\s*File\s*:\s*(.*?)\s*\|\s*SQL\s*:\s*(.*)$/is', $message, $parts)) {

                    $current["file"] = trim($parts[1]);

                    $current["sql"] = trim($parts[2]);

                    $current["message"] = trim(
                        preg_replace('/\|\s*File\s*:.*$/is', '', $message)
                    );
                }

            } else {
                preg_match('/Line No\s*:\s*(.*)/', $text, $m);
                $current["line"] = trim($m[1] ?? "");
            }

            continue;
        }

        /* ---------- Message on Next Line ---------- */

        if (strpos($text, "Message") === 0) {
            preg_match('/Message\s*:\s*(.*)/', $text, $m);
            $current["message"] = trim($m[1] ?? "");
            continue;
        }

        /* ---------- Help/File/SQL ---------- */

            if (strpos($text, "Help:") === 0) {

                if (preg_match('/Help:\s*(.*?)\s*\|\s*File\s*:\s*(.*?)\s*\|\s*SQL\s*:\s*(.*)$/', $text, $m)) {

                    $current["help"] = trim($m[1]);
                    $current["file"] = trim($m[2]);
                    $current["sql"] = trim($m[3]);
                    $current["readingSql"] = true;
                }
                continue;
            }
        
            /* ---------- SQL CONTINUATION ---------- */

            if (!empty($current["readingSql"])) {

                if (strpos($text, "-----") === 0) {

                    unset($current["readingSql"]);
                    continue;
                }

                if ($current["sql"] === "") {
                    $current["sql"] = $text;
                } else {
                    $current["sql"] .= PHP_EOL . $text;
                }

                continue;
            }

        /* ---------- Separator ---------- */

            if (strpos($text, "| SQL :") !== false) {

                $parts = explode("| SQL :", $text, 2);

                $current["sql"] = trim($parts[1]);
                $current["readingSql"] = true;

                continue;
            }

    }

    /* ---------- Last Record ---------- */

    if (!empty($current)) {

        if (!isset($current["line"])) {
            $current["line"] = "";
        }

        if (!isset($current["message"])) {
            $current["message"] = "";
        }

        preg_match('/ORA-\d+/', $current["message"], $err);
        $current["errorCode"] = $err[0] ?? "";
        $current["id"] = $id++;
        $logs[] = $current;
    }

    /* ==========================================================
       SUCCESS RESPONSE
    ========================================================== */

    $response = [
        "status" => true,
        "logDate" => $displayDate,
        "total" => count($logs),
        "logs" => array_reverse($logs),
        "message" => ""
    ];

}
/* ==========================================================
   EXCEPTION
========================================================== */
catch (Exception $e) {

    http_response_code(500);

    $response = [
        "status" => false,
        "logs" => [],
        "logDate" => "",
        "message" => $e->getMessage()
    ];

}
/* ==========================================================
   CLEANUP
========================================================== */
finally {

    if (isset($stmt) && is_resource($stmt)) {
        @oci_free_statement($stmt);
    }

    if (isset($conn) && is_resource($conn)) {
        @oci_close($conn);
    }

    ob_clean();

    echo json_encode($response);

    exit;

}
    
    
