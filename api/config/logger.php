<?php
require_once "db.php";
require_once "session.php";

function writeErrorLog($message)
{ 
    $sql___func___con = db_eportal();

    if (!$sql___func___con) {
        return;
    }

    $user = $_SESSION['emp_code'] ?? 'SYSTEM';

    $sql = "BEGIN error_log.write(:perror, :puser); END;";

    $stmt = oci_parse($sql___func___con, $sql);

    oci_bind_by_name($stmt, ":perror", $message);
    oci_bind_by_name($stmt, ":puser", $user);

    @oci_execute($stmt);

    oci_free_statement($stmt);
}