<?php
/*
 * GET ?user_task_id=&ctc=&pf_basis= -> monthly amounts per CTC head for a CTC in
 * lakhs ("Get Breakup"; legacy getBreakup and getCtcStruct). Saves nothing.
 */
require_once __DIR__ . '/joiningDetailCommon.php';

try {
    $task = joiningLoadDetailTask($_GET['user_task_id'] ?? 0, $empCode);
    if ($task['SECTION'] !== 'ctc') {
        throw new JoiningRequestException('This is not a CTC task.', 422);
    }
    $ctc = trim((string)($_GET['ctc'] ?? ''));
    if (!is_numeric($ctc) || (float)$ctc <= 0) {
        throw new JoiningRequestException('Please enter a valid CTC.', 422);
    }
    $heads = joiningCtcHeads($task['TRAN_CODE']);
    if (!$heads) {
        throw new JoiningRequestException('No CTC structure is set up for this employee\'s job description.', 422);
    }
    apiResponse(true, 'CTC breakup calculated.', [
        'amounts' => joiningCtcStructure($heads, $ctc, (int)($_GET['pf_basis'] ?? 0)),
    ], 200);
} catch (JoiningRequestException $e) {
    apiResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);
} catch (Throwable $e) {
    logOracleError(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'getJoiningCtcBreakup.php');
    apiResponse(false, 'Unable to calculate the CTC breakup.', null, 500);
} finally {
    if (!empty($sql___func___con)) oci_close($sql___func___con);
}
