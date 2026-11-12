<?php
/*
 * GET ?path=sdlhub/cv/... | sdlhub/photo/...  -> a candidate CV / photo uploaded through the
 * SDL Hub careers portal (stored outside the web root). Only for users with a Recruitment page,
 * and only paths that belong to a candidate. Legacy paths stay on the old careers server.
 */
require_once __DIR__ . '/recruitmentCommon.php';
require_once __DIR__ . '/../../careers/careersFiles.php';
require_once __DIR__ . '/../../config/env.php';

try {
    recruitmentRequirePage($empCode, RECRUITMENT_ALL_PAGES);
    session_write_close();
    $path = trim((string)($_GET['path'] ?? ''));
    $owner = $path === '' ? [] : recruitmentRow("SELECT ID FROM HR_CANDIDATE_INFO
        WHERE (DOC_PATH = :path OR IMAGE_PATH = :path) AND ROWNUM = 1", [':path' => $path]);
    $full = empty($owner['ID']) ? null : careersStoredFilePath($path);
    if ($full === null) {
        throw new RecruitmentRequestException('Document not found.', 404);
    }
    oci_close($sql___func___con);
    careersStreamFile($full, basename($full));
} catch (RecruitmentRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'viewCandidateDocument.php');
    apiResponse(false, 'Unable to open the document.', null, 500);
}
