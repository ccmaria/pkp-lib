<?php

/**
 * @file pages/authorDashboard/PKPAuthorDashboardHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPAuthorDashboardHandler
 * @ingroup pages_authorDashboard
 *
 * @brief Handle requests for the author dashboard.
 */

use APP\handler\Handler;
use APP\template\TemplateManager;
use APP\workflow\EditorDecisionActionsManager;

use PKP\log\SubmissionEmailLogEntry;
use PKP\security\authorization\AuthorDashboardAccessPolicy;
use PKP\security\Role;
use PKP\services\PKPSchemaService;
use PKP\submission\PKPSubmission;
use PKP\submission\SubmissionFile;
use PKP\workflow\WorkflowStageDAO;

abstract class PKPAuthorDashboardHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_AUTHOR],
            [
                'submission',
                'readSubmissionEmail',
            ]
        );
    }


    //
    // Implement template methods from PKPHandler
    //
    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new AuthorDashboardAccessPolicy($request, $args, $roleAssignments), true);

        return parent::authorize($request, $args, $roleAssignments);
    }


    //
    // Public handler operations
    //
    /**
     * Displays the author dashboard.
     *
     * @param $args array
     * @param $request PKPRequest
     */
    public function submission($args, $request)
    {
        // Pass the authorized submission on to the template.
        $this->setupTemplate($request);

        $templateMgr = TemplateManager::getManager($request);
        return $templateMgr->display('authorDashboard/authorDashboard.tpl');
    }


    /**
     * Fetches information about a specific email and returns it.
     *
     * @param $args array
     * @param $request Request
     *
     * @return JSONMessage JSON object
     */
    public function readSubmissionEmail($args, $request)
    {
        $submissionEmailLogDao = DAORegistry::getDAO('SubmissionEmailLogDAO'); /** @var SubmissionEmailLogDAO $submissionEmailLogDao */
        $user = $request->getUser();
        $submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
        $submissionEmailId = $request->getUserVar('submissionEmailId');

        $submissionEmailFactory = $submissionEmailLogDao->getByEventType($submission->getId(), SubmissionEmailLogEntry::SUBMISSION_EMAIL_EDITOR_NOTIFY_AUTHOR, $user->getId());
        while ($email = $submissionEmailFactory->next()) { // validate the email id for this user.
            if ($email->getId() == $submissionEmailId) {
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign('submissionEmail', $email);
                return $templateMgr->fetchJson('authorDashboard/submissionEmail.tpl');
            }
        }
    }

    /**
     * Get the SUBMISSION_FILE_... file stage based on the current
     * WORKFLOW_STAGE_... workflow stage.
     *
     * @param $currentStage int WORKFLOW_STAGE_...
     *
     * @return int SUBMISSION_FILE_...
     */
    protected function _fileStageFromWorkflowStage($currentStage)
    {
        switch ($currentStage) {
            case WORKFLOW_STAGE_ID_SUBMISSION:
                return SubmissionFile::SUBMISSION_FILE_SUBMISSION;
            case WORKFLOW_STAGE_ID_EXTERNAL_REVIEW:
                return SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION;
            case WORKFLOW_STAGE_ID_EDITING:
                return SubmissionFile::SUBMISSION_FILE_FINAL;
            default:
                return null;
        }
    }


    //
    // Protected helper methods
    //
    /**
     * Setup common template variables.
     */
    public function setupTemplate($request)
    {
        parent::setupTemplate($request);
        AppLocale::requireComponents(
            LOCALE_COMPONENT_PKP_ADMIN,
            LOCALE_COMPONENT_PKP_MANAGER,
            LOCALE_COMPONENT_PKP_SUBMISSION,
            LOCALE_COMPONENT_APP_SUBMISSION,
            LOCALE_COMPONENT_PKP_EDITOR,
            LOCALE_COMPONENT_APP_EDITOR,
            LOCALE_COMPONENT_PKP_GRID
        );

        $templateMgr = TemplateManager::getManager($request);
        $submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
        $user = $request->getUser();
        $submissionContext = $request->getContext();
        if ($submission->getContextId() !== $submissionContext->getId()) {
            $submissionContext = Services::get('context')->get($submission->getContextId());
        }

        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /** @var UserGroupDAO $userGroupDao */
        $contextUserGroups = $userGroupDao->getByRoleId($submission->getData('contextId'), Role::ROLE_ID_AUTHOR)->toArray();
        $workflowStages = WorkflowStageDAO::getWorkflowStageKeysAndPaths();

        $stageNotifications = [];
        foreach (array_keys($workflowStages) as $stageId) {
            $stageNotifications[$stageId] = false;
        }

        $editDecisionDao = DAORegistry::getDAO('EditDecisionDAO'); /** @var EditDecisionDAO $editDecisionDao */
        $stageDecisions = $editDecisionDao->getEditorDecisions($submission->getId());

        // Add an upload revisions button when in the review stage
        // and the last decision is to request revisions
        $uploadFileUrl = '';
        if (in_array($submission->getData('stageId'), [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW])) {
            $fileStage = $this->_fileStageFromWorkflowStage($submission->getData('stageId'));
            $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
            $lastReviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), $submission->getData('stageId'));
            if ($fileStage && is_a($lastReviewRound, 'ReviewRound')) {
                $editDecisionDao = DAORegistry::getDAO('EditDecisionDAO'); /** @var EditDecisionDAO $editDecisionDao */
                $editorDecisions = $editDecisionDao->getEditorDecisions($submission->getId(), $submission->getData('stageId'), $lastReviewRound->getRound());
                if (!empty($editorDecisions)) {
                    $lastDecision = end($editorDecisions)['decision'];
                    $revisionDecisions = [
                        EditorDecisionActionsManager::SUBMISSION_EDITOR_DECISION_PENDING_REVISIONS,
                        EditorDecisionActionsManager::SUBMISSION_EDITOR_DECISION_RESUBMIT
                    ];
                    if (in_array($lastDecision, $revisionDecisions)) {
                        $actionArgs['submissionId'] = $submission->getId();
                        $actionArgs['stageId'] = $submission->getData('stageId');
                        $actionArgs['uploaderRoles'] = Role::ROLE_ID_AUTHOR;
                        $actionArgs['fileStage'] = $fileStage;
                        $actionArgs['reviewRoundId'] = $lastReviewRound->getId();
                        $uploadFileUrl = $request->getDispatcher()->url(
                            $request,
                            PKPApplication::ROUTE_COMPONENT,
                            null,
                            'wizard.fileUpload.FileUploadWizardHandler',
                            'startWizard',
                            null,
                            $actionArgs
                        );
                    }
                }
            }
        }

        $supportedSubmissionLocales = $submissionContext->getSupportedSubmissionLocales();
        $localeNames = AppLocale::getAllLocales();
        $locales = array_map(function ($localeKey) use ($localeNames) {
            return ['key' => $localeKey, 'label' => $localeNames[$localeKey]];
        }, $supportedSubmissionLocales);

        $latestPublication = $submission->getLatestPublication();

        $submissionApiUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_API, $submissionContext->getData('urlPath'), 'submissions/' . $submission->getId());
        $latestPublicationApiUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_API, $submissionContext->getData('urlPath'), 'submissions/' . $submission->getId() . '/publications/' . $latestPublication->getId());

        $contributorsGridUrl = $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_COMPONENT,
            null,
            'grid.users.author.AuthorGridHandler',
            'fetchGrid',
            null,
            [
                'submissionId' => $submission->getId(),
                'publicationId' => '__publicationId__',
            ]
        );

        $submissionLibraryUrl = $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_COMPONENT,
            null,
            'modals.documentLibrary.DocumentLibraryHandler',
            'documentLibrary',
            null,
            ['submissionId' => $submission->getId()]
        );

        $titleAbstractForm = new PKP\components\forms\publication\PKPTitleAbstractForm($latestPublicationApiUrl, $locales, $latestPublication);
        $citationsForm = new PKP\components\forms\publication\PKPCitationsForm($latestPublicationApiUrl, $latestPublication);

        // Import constants
        import('classes.components.forms.publication.PublishForm');

        $templateMgr->setConstants([
            'STATUS_QUEUED' => PKPSubmission::STATUS_QUEUED,
            'STATUS_PUBLISHED' => PKPSubmission::STATUS_PUBLISHED,
            'STATUS_DECLINED' => PKPSubmission::STATUS_DECLINED,
            'STATUS_SCHEDULED' => PKPSubmission::STATUS_SCHEDULED,
            'FORM_TITLE_ABSTRACT' => FORM_TITLE_ABSTRACT,
            'FORM_CITATIONS' => FORM_CITATIONS,
        ]);

        // Get the submission props without the full publication details. We'll
        // retrieve just the publication information that we need separately to
        // reduce the amount of data passed to the browser
        $propNames = Services::get('schema')->getSummaryProps(PKPSchemaService::SCHEMA_SUBMISSION);
        $propNames = array_filter($propNames, function ($propName) {
            return $propName !== 'publications';
        });
        $submissionProps = Services::get('submission')->getProperties(
            $submission,
            $propNames,
            [
                'request' => $request,
                'userGroups' => $contextUserGroups,
            ]
        );

        // Get an array of publication identifiers
        $publicationList = [];
        foreach ($submission->getData('publications') as $publication) {
            $publicationList[] = Services::get('publication')->getProperties(
                $publication,
                ['id', 'datePublished', 'status', 'version'],
                [
                    'context' => $submissionContext,
                    'submission' => $submission,
                    'request' => $request,
                ]
            );
        }

        // Get full details of the working publication and the currently published publication
        $workingPublicationProps = Services::get('publication')->getFullProperties(
            $submission->getLatestPublication(),
            [
                'context' => $submissionContext,
                'submission' => $submission,
                'request' => $request,
                'userGroups' => $contextUserGroups,
            ]
        );
        if ($submission->getLatestPublication()->getId() === $submission->getCurrentPublication()->getId()) {
            $currentPublicationProps = $workingPublicationProps;
        } else {
            $currentPublicationProps = Services::get('publication')->getFullProperties(
                $submission->getCurrentPublication(),
                [
                    'context' => $submissionContext,
                    'submission' => $submission,
                    'request' => $request,
                    'userGroups' => $contextUserGroups,
                ]
            );
        }

        // Check if current author can edit metadata
        $userRoles = $this->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);
        $canEditPublication = true;
        if (!in_array(Role::ROLE_ID_SITE_ADMIN, $userRoles) && !Services::get('submission')->canEditPublication($submission->getId(), $user->getId())) {
            $canEditPublication = false;
        }

        // Check if current author can access ArticleGalleyGrid within production stage
        $canAccessProductionStage = true;
        $userAllowedStages = $this->getAuthorizedContextObject(ASSOC_TYPE_ACCESSIBLE_WORKFLOW_STAGES);
        if (!array_key_exists(WORKFLOW_STAGE_ID_PRODUCTION, $userAllowedStages)) {
            $canAccessProductionStage = false;
        }

        $state = [
            'canEditPublication' => $canEditPublication,
            'components' => [
                FORM_TITLE_ABSTRACT => $titleAbstractForm->getConfig(),
                FORM_CITATIONS => $citationsForm->getConfig(),
            ],
            'contributorsGridUrl' => $contributorsGridUrl,
            'currentPublication' => $currentPublicationProps,
            'publicationFormIds' => [
                FORM_TITLE_ABSTRACT,
                FORM_CITATIONS,
            ],
            'representationsGridUrl' => $canAccessProductionStage ? $this->_getRepresentationsGridUrl($request, $submission) : '',
            'submission' => $submissionProps,
            'publicationList' => $publicationList,
            'workingPublication' => $workingPublicationProps,
            'submissionApiUrl' => $submissionApiUrl,
            'submissionLibraryLabel' => __('grid.libraryFiles.submission.title'),
            'submissionLibraryUrl' => $submissionLibraryUrl,
            'supportsReferences' => !!$submissionContext->getData('citations'),
            'statusLabel' => __('semicolon', ['label' => __('common.status')]),
            'uploadFileModalLabel' => __('editor.submissionReview.uploadFile'),
            'uploadFileUrl' => $uploadFileUrl,
            'versionLabel' => __('semicolon', ['label' => __('admin.version')]),
        ];

        // Add the metadata form if one or more metadata fields are enabled
        $metadataFields = ['coverage', 'disciplines', 'keywords', 'languages', 'rights', 'source', 'subjects', 'agencies', 'type'];
        $metadataEnabled = false;
        foreach ($metadataFields as $metadataField) {
            if ($submissionContext->getData($metadataField)) {
                $metadataEnabled = true;
                break;
            }
        }
        if ($metadataEnabled) {
            $vocabSuggestionUrlBase = $request->getDispatcher()->url($request, PKPApplication::ROUTE_API, $submissionContext->getData('urlPath'), 'vocabs', null, null, ['vocab' => '__vocab__']);
            $metadataForm = new PKP\components\forms\publication\PKPMetadataForm($latestPublicationApiUrl, $locales, $latestPublication, $submissionContext, $vocabSuggestionUrlBase);
            $templateMgr->setConstants([
                'FORM_METADATA' => FORM_METADATA,
            ]);
            $state['components'][FORM_METADATA] = $metadataForm->getConfig();
            $state['publicationFormIds'][] = FORM_METADATA;
        }

        $templateMgr->setState($state);

        $templateMgr->assign([
            'metadataEnabled' => $metadataEnabled,
            'pageComponent' => 'WorkflowPage',
            'pageTitle' => join(__('common.titleSeparator'), [
                $submission->getShortAuthorString(),
                $submission->getLocalizedTitle()
            ]),
            'submission' => $submission,
            'workflowStages' => $workflowStages,
            'canAccessProductionStage' => $canAccessProductionStage,
        ]);
    }

    /**
     * Get the URL for the galley/publication formats grid with a placeholder for
     * the publicationId value
     *
     * @param Request $request
     * @param Submission $submission
     *
     * @return string
     */
    abstract protected function _getRepresentationsGridUrl($request, $submission);
}
