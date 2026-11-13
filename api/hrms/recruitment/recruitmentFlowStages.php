<?php
/* Loads the stage files and maps each flow task to its loader and action handler. */
require_once __DIR__ . '/recruitmentFlowCommon.php';
require_once __DIR__ . '/recruitmentFlowCandidates.php';
require_once __DIR__ . '/recruitmentFlowInterview.php';
require_once __DIR__ . '/recruitmentFlowFeedback.php';
require_once __DIR__ . '/recruitmentFlowOffer.php';

/** [loader, action] callables of a flow task. */
function recruitmentFlowStage($workflowTaskId)
{
    switch ((int)$workflowTaskId) {
        case RECRUITMENT_TASK_SEND_SUITABLE:
        case RECRUITMENT_TASK_SHORTLIST:
            return ['recruitmentFlowLoadCandidatesTask', 'recruitmentFlowCandidatesAction'];
        case RECRUITMENT_TASK_SCHEDULE_INTERVIEW:
        case RECRUITMENT_TASK_INTERVIEW_CONFIRMATION:
        case RECRUITMENT_TASK_ASSIGN_INTERVIEWER:
            return ['recruitmentFlowLoadInterviewTask', 'recruitmentFlowInterviewAction'];
        case RECRUITMENT_TASK_INTERVIEW_SESSION:
        case RECRUITMENT_TASK_RECOMMENDER_SESSION:
            return ['recruitmentFlowLoadFeedbackTask', 'recruitmentFlowFeedbackAction'];
        case RECRUITMENT_TASK_GENERATE_OFFER:
        case RECRUITMENT_TASK_ANNEXURE_APPROVAL:
        case RECRUITMENT_TASK_PRINT_OFFER:
        case RECRUITMENT_TASK_OFFER_ACCEPTANCE:
            return ['recruitmentFlowLoadOfferTask', 'recruitmentFlowOfferAction'];
    }
    throw new RecruitmentRequestException('A valid task is required.', 422);
}
