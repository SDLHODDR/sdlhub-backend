<?php
/* GET ?start=&length=&search= -> one page of the Candidate List (11,000+ rows, so paged by the server). */
require_once __DIR__ . '/recruitmentCommon.php';

recruitmentRunRead(function () use ($empCode) {
    recruitmentRequirePage($empCode, [RECRUITMENT_PAGE_CANDIDATES]);
    session_write_close();
    return ['Candidates fetched successfully.',
        recruitmentCandidatePage($_GET['start'] ?? 0, $_GET['length'] ?? 10, $_GET['search'] ?? '')];
}, 'getCandidates.php');
