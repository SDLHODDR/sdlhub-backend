<?php
/* GET (getSession.php; not session.php, see careersCommon.php) -> { loggedIn, candidate, csrfToken }. Also issues the CSRF token that every careers POST needs. */
require_once __DIR__ . '/careersCommon.php';

careersRunRead(static function () {
    $candidate = null;
    if (!empty($_SESSION['careers']['candidateId'])) {
        try {
            $candidate = careersSessionCandidate(careersCandidate());
        } catch (CareersException $e) {
            $candidate = null;
        }
    }
    return ['Session fetched.', [
        'loggedIn' => $candidate !== null,
        'candidate' => $candidate,
        'csrfToken' => $_SESSION['careersCsrf'],
        // Registration form (shown before login).
        'countries' => array_map(static fn($r) => $r['DESCR'],
            careersRows("SELECT DESCR FROM EPPLIVE.BCS_COUNTRY ORDER BY DECODE(DESCR, 'India', 0, 1), DESCR")),
    ]];
}, 'careers/session.php');
