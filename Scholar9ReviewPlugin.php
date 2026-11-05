<?php

/**
 * Scholar9 Transparent Peer Review for OJS
 *
 * Copyright (c) 2026 SEQUENCE RESEARCH & DEVELOPMENT PRIVATE LIMITED
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
namespace APP\plugins\generic\scholar9Review;

require_once __DIR__ . '/classes/Scholar9ReviewDAO.php';
require_once __DIR__ . '/classes/Scholar9ReviewSchemaMigration.php';
require_once __DIR__ . '/classes/Scholar9ApiClient.php';
require_once __DIR__ . '/classes/Scholar9SettingsForm.php';
require_once __DIR__ . '/classes/Scholar9SyncService.php';
require_once __DIR__ . '/classes/OjsJournalMetadataProvider.php';
require_once __DIR__ . '/pages/Scholar9ReviewHandler.php';

use APP\core\Application;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Mail;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\Notification;
use PKP\mail\Mailable;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class Scholar9ReviewPlugin extends GenericPlugin
{
    // ── Bootstrap ──────────────────────────────────────────────────────────────

    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        Scholar9ReviewDAO::install();

        // OJS 3.5 removed the legacy workflow template hook. Register these
        // panels only on versions where the hooks exist.
        if (version_compare(Application::get()->getCurrentVersion()->getVersionString(), '3.5.0', '<')) {
            Hook::add('Template::Workflow', $this->addEditorPanel(...));
            Hook::add('Templates::Author::Submission', $this->addAuthorPanel(...));
        } else {
            // OJS 3.5 renders the editorial workflow as a Vue application. Load
            // a registered workflow-store extension before the dashboard app is
            // mounted. The integration receives the active submission from OJS;
            // it never discovers, clones, hides, or repositions DOM elements.
            Hook::add('TemplateManager::display', $this->registerDashboardIntegration(...));
        }
        Hook::add('LoadHandler', $this->loadHandler(...));
        Hook::add('ReviewAssignment::add', $this->onOjsReviewerAssigned(...));

        return true;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.scholar9Review.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.scholar9Review.description');
    }

    public function getInstallMigration(): Scholar9ReviewSchemaMigration
    {
        return new Scholar9ReviewSchemaMigration();
    }

    /**
     * Register the OJS 3.5 workflow component and its static configuration.
     *
     * This is deliberately scoped to the editorial dashboard template. The
     * workflow component itself receives submissionId reactively from OJS, so
     * switching between submissions cannot reuse another submission's state.
     */
    public function registerDashboardIntegration(string $hookName, array $args): void
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $template = (string) $args[1];

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return;
        }

        // The workflow stylesheet also contains the small, scoped styles for
        // the native Sync to Scholar9 modal. Registering it for journal backend
        // pages ensures AJAX-loaded modal content is styled without injecting
        // a custom overlay or inline executable code.
        $styleFile = $this->getPluginPath() . '/css/workflow.css';
        $styleVersion = (string) (@\filemtime($styleFile) ?: '1');
        $templateMgr->addStyleSheet(
            'scholar9ReviewWorkflow',
            $request->getBaseUrl() . '/' . $styleFile . '?v=' . \rawurlencode($styleVersion),
            [
                'contexts' => ['backend'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );

        if ($template !== 'dashboard/editors.tpl') {
            return;
        }

        $router = $request->getRouter();
        $settingsUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            null,
            'management',
            'settings',
            ['website'],
            [],
            'plugins'
        );
        $config = [
            'configured' => (new Scholar9ApiClient($this, $context->getId()))->isConfigured(),
            'autoSync' => (bool) $this->getSetting($context->getId(), 'autoSync'),
            'settingsUrl' => $settingsUrl,
            'actionUrl' => $router->url($request, null, 'scholar9review', 'action'),
            'statusUrl' => $router->url($request, null, 'scholar9review', 'status'),
            'csrfToken' => $request->getSession()->token(),
            'maxReviewers' => 5,
            'maxMessageLength' => Scholar9ApiClient::MAX_MESSAGE_LENGTH,
            'scholar9Origin' => (new Scholar9ApiClient($this, $context->getId()))->publicOrigin(),
        ];

        $templateMgr->addJavaScript(
            'scholar9ReviewConfig',
            'window.scholar9ReviewConfig = ' . \json_encode(
                $config,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) . ';',
            [
                'contexts' => ['backend'],
                'inline' => true,
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST - 1,
            ]
        );
        $scriptFile = $this->getPluginPath() . '/js/workflowIntegration.js';
        $scriptVersion = (string) (@\filemtime($scriptFile) ?: '1');
        $templateMgr->addJavaScript(
            'scholar9ReviewDashboard',
            $request->getBaseUrl() . '/' . $scriptFile . '?v=' . \rawurlencode($scriptVersion),
            [
                'contexts' => ['backend'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );
    }

    // ── Plugin settings action ──────────────────────────────────────────────────

    public function getActions($request, $verb): array
    {
        $router = $request->getRouter();

        $pluginArgs = [
            'plugin' => $this->getName(),
            'category' => 'generic',
        ];

        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, [
                            'verb'     => 'settings',
                            'plugin'   => $pluginArgs['plugin'],
                            'category' => $pluginArgs['category'],
                        ]),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                ),
                new LinkAction(
                    'syncToScholar9',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, [
                            'verb' => 'syncToScholar9',
                            'plugin' => $pluginArgs['plugin'],
                            'category' => $pluginArgs['category'],
                        ]),
                        __('plugins.generic.scholar9Review.sync.title'),
                        'basic',
                        true,
                        'scholar9SyncForm'
                    ),
                    __('plugins.generic.scholar9Review.sync.button'),
                    'refresh'
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    public function manage($args, $request): JSONMessage
    {
        $context = $request->getContext();

        if ($request->getUserVar('verb') === 'settings') {
            $form = new Scholar9SettingsForm($this, $context->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification(
                        $request->getUser()->getId(),
                        Notification::NOTIFICATION_TYPE_SUCCESS,
                        ['contents' => __('plugins.generic.scholar9Review.settings.saved')]
                    );
                    return new JSONMessage(true);
                }
            } else {
                $form->initData();
            }

            return new JSONMessage(true, $form->fetch($request));
        }

        if ($request->getUserVar('verb') === 'syncToScholar9') {
            if (!$context) {
                return new JSONMessage(false, __('plugins.generic.scholar9Review.sync.noJournal'));
            }

            $client = new Scholar9ApiClient($this, (int) $context->getId());
            $metadataProvider = new OjsJournalMetadataProvider();
            $metadata = $metadataProvider->build($context, $request);
            $isSave = (bool) $request->getUserVar('save');
            if ($isSave) {
                $profileInput = $request->getUserVar('profile');
                $metadata = $metadataProvider->applyEditableOverrides(
                    $metadata,
                    \is_array($profileInput) ? $profileInput : []
                );
                $missingFields = $metadataProvider->validateReviewedPayload($metadata);
            } else {
                $missingFields = $metadataProvider->missingRequiredFields($context, $metadata);
            }

            // Opening the action performs validation first. The AJAX modal
            // therefore displays either a focused missing-fields panel or the
            // confirmation form, never a browser alert stacked above another
            // modal.
            if (!$isSave) {
                return $this->renderJournalSyncDialog(
                    $request,
                    $context,
                    $client,
                    $metadata,
                    $missingFields,
                    $client->hasBaseUrl()
                        ? null
                        : __('plugins.generic.scholar9Review.sync.baseUrlMissing')
                );
            }

            if (!$request->checkCSRF()) {
                return $this->renderJournalSyncDialog(
                    $request,
                    $context,
                    $client,
                    $metadata,
                    $missingFields,
                    null,
                    __('form.csrfInvalid')
                );
            }
            if (!$client->hasBaseUrl()) {
                return $this->renderJournalSyncDialog(
                    $request,
                    $context,
                    $client,
                    $metadata,
                    $missingFields,
                    __('plugins.generic.scholar9Review.sync.baseUrlMissing')
                );
            }
            if ($missingFields) {
                return $this->renderJournalSyncDialog(
                    $request,
                    $context,
                    $client,
                    $metadata,
                    $missingFields
                );
            }
            // A pending unauthenticated registration is a terminal local
            // state until credentials are approved and configured. Enforce
            // this server-side so a crafted POST cannot create duplicates.
            $storedSyncStatus = (string) $this->getSetting(
                (int) $context->getId(),
                'journalSyncStatus'
            );
            if (!$client->isConfigured() && $storedSyncStatus === 'pending') {
                return $this->renderJournalSyncDialog(
                    $request,
                    $context,
                    $client,
                    $metadata,
                    $missingFields
                );
            }
            $response = $client->isConfigured()
                ? $client->syncJournal($metadata)
                : $client->requestApiAccess($metadata);

            if (!$response || ($response['success'] ?? true) === false) {
                $message = $client->getLastError() ?: __('plugins.generic.scholar9Review.sync.failed');
                return $this->renderJournalSyncDialog(
                    $request,
                    $context,
                    $client,
                    $metadata,
                    $missingFields,
                    null,
                    $this->safeSyncError($message)
                );
            }

            $data = \is_array($response['data'] ?? null) ? $response['data'] : [];
            $isPending = !$client->isConfigured();

            $isDuplicate = $isPending && !empty($data['duplicate']);
            if ($isPending && !$isDuplicate) {
                $this->notifyApiAccessRequest($context, $metadata, $data);
            }
            $this->updateSetting((int) $context->getId(), 'journalSyncStatus', $isPending ? 'pending' : 'synced', 'string');
            $this->updateSetting((int) $context->getId(), 'journalSyncReference', (string) ($data['application_ref'] ?? ''), 'string');
            $this->updateSetting((int) $context->getId(), 'journalSyncAt', \gmdate('c'), 'string');

            if ($isPending && !empty($data['api_slug']) && !$this->getSetting((int) $context->getId(), 'journalSlug')) {
                $this->updateSetting((int) $context->getId(), 'journalSlug', (string) $data['api_slug'], 'string');
            }

            $successMessage = __($isDuplicate
                ? 'plugins.generic.scholar9Review.sync.pending'
                : ($isPending
                    ? 'plugins.generic.scholar9Review.sync.requested'
                    : 'plugins.generic.scholar9Review.sync.completed'));
            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification(
                $request->getUser()->getId(),
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => $successMessage]
            );

            // The native AJAX form modal closes itself after this successful
            // empty response and emits the normal OJS notification event.
            $message = new JSONMessage(true, '', '0', [
                'message' => $successMessage,
                'syncStatus' => $isPending ? 'pending' : 'synced',
                'applicationRef' => (string) ($data['application_ref'] ?? ''),
            ]);
            $message->setEvent('notifyUser');
            return $message;
        }

        return parent::manage($args, $request);
    }

    /**
     * Render every recoverable sync state inside the native OJS AJAX modal.
     * A successful JSON response containing replacement markup keeps the modal
     * open and prevents OJS' generic failed-response browser alert.
     */
    private function renderJournalSyncDialog(
        $request,
        $context,
        Scholar9ApiClient $client,
        array $metadata,
        array $missingFields,
        ?string $blockingError = null,
        ?string $formError = null
    ): JSONMessage {
        $templateMgr = TemplateManager::getManager($request);
        $journalName = (string) ($metadata['journal']['name'] ?? $context->getLocalizedName());
        $journal = \is_array($metadata['journal'] ?? null) ? $metadata['journal'] : [];
        $editorialBoard = [];
        foreach ((array) ($journal['editorial_board'] ?? []) as $member) {
            if (!\is_array($member)) {
                continue;
            }
            $editorialBoard[] = \implode(' | ', \array_filter([
                (string) ($member['role'] ?? ''),
                (string) ($member['name'] ?? ''),
                (string) ($member['email'] ?? ''),
            ], static fn(string $value): bool => $value !== ''));
        }
        $indexing = \is_array($journal['indexing_agencies'] ?? null)
            ? $journal['indexing_agencies']
            : [];
        $storedSyncStatus = (string) $this->getSetting(
            (int) $context->getId(),
            'journalSyncStatus'
        );
        $syncRequestPending = !$client->isConfigured() && $storedSyncStatus === 'pending';
        $syncRequestApproved = $client->isConfigured() || $storedSyncStatus === 'synced';
        $templateMgr->assign([
            'pluginName' => $this->getName(),
            // Translation interpolation is not escaped by Smarty's translate
            // helper, so encode this display-only value at the boundary.
            'journalName' => \htmlspecialchars(
                $journalName,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            'configured' => $client->isConfigured(),
            'syncRequestPending' => $syncRequestPending,
            'syncRequestApproved' => $syncRequestApproved,
            'syncRequestReference' => (string) $this->getSetting(
                (int) $context->getId(),
                'journalSyncReference'
            ),
            'missingFields' => $missingFields,
            'issueFrequencyMissing' => \trim((string) ($metadata['journal']['issue_frequency'] ?? '')) === '',
            'blockingError' => $blockingError,
            'formError' => $formError,
            'syncProfile' => $journal,
            'syncApiSlug' => (string) ($metadata['api_slug'] ?? ''),
            'syncLanguages' => \implode(', ', (array) ($journal['languages'] ?? [])),
            'syncSubjects' => \implode(', ', (array) ($journal['subjects'] ?? [])),
            'syncEditorialBoard' => \implode("\n", $editorialBoard),
            'syncIndexing' => $indexing,
            'syncSystem' => [
                'journalId' => (string) ($metadata['journal_id'] ?? ''),
                'sourceUrl' => (string) ($metadata['source_url'] ?? ''),
                'contextId' => (string) ($metadata['source_context_id'] ?? ''),
                'ojsVersion' => (string) ($metadata['ojs_version'] ?? ''),
                'journalPath' => (string) ($journal['path'] ?? ''),
                'journalUrl' => (string) ($journal['url'] ?? ''),
                'photoUrl' => (string) ($journal['photo_url'] ?? ''),
                'primaryLocale' => (string) ($journal['primary_locale'] ?? ''),
                'supportedLocales' => \implode(', ', (array) ($journal['supported_locales'] ?? [])),
                'publicationPlatform' => (string) ($journal['publication_platform'] ?? ''),
            ],
        ]);

        return new JSONMessage(
            true,
            $templateMgr->fetch($this->getTemplateResource('syncDialog.tpl'))
        );
    }

    /**
     * Notify OJSCloud when a journal submits a new Scholar9 API-access request.
     * A mail-delivery problem must never invalidate an accepted remote request.
     * Credentials, webhook secrets and other authentication material are omitted.
     */
    private function notifyApiAccessRequest($context, array $metadata, array $responseData): void
    {
        try {
            $journal = \is_array($metadata['journal'] ?? null) ? $metadata['journal'] : [];
            $journalName = trim((string) ($journal['name'] ?? $context->getLocalizedName() ?? 'Unnamed journal'));
            $journalName = (string) preg_replace('/[\r\n]+/', ' ', $journalName);
            $contactEmail = trim((string) ($metadata['owner_email'] ?? $context->getData('contactEmail') ?? ''));
            $contactName = trim((string) ($metadata['owner_name'] ?? $context->getData('contactName') ?? $journalName));
            $contactName = (string) preg_replace('/[\r\n]+/', ' ', $contactName);
            $hasReplyTo = (bool) filter_var($contactEmail, FILTER_VALIDATE_EMAIL);

            $details = [
                'Application reference' => (string) ($responseData['application_ref'] ?? 'Not supplied'),
                'Journal name' => $journalName,
                'Journal short name' => (string) ($journal['short_name'] ?? ''),
                'Journal API slug' => (string) ($responseData['api_slug'] ?? $metadata['api_slug'] ?? ''),
                'Applicant / contact' => $contactName,
                'Applicant email' => $contactEmail,
                'Publisher / institution' => (string) ($journal['publisher'] ?? $metadata['org_name'] ?? ''),
                'Journal URL' => (string) ($journal['url'] ?? ''),
                'OJS source URL' => (string) ($metadata['source_url'] ?? ''),
                'OJS context ID' => (string) ($metadata['source_context_id'] ?? ''),
                'OJS version' => (string) ($metadata['ojs_version'] ?? ''),
                'Online ISSN' => (string) ($journal['online_issn'] ?? ''),
                'Print ISSN' => (string) ($journal['print_issn'] ?? ''),
                'Country' => (string) ($journal['country'] ?? ''),
                'Use case' => (string) ($metadata['use_case'] ?? ''),
                'Requested at (UTC)' => \gmdate('Y-m-d H:i:s'),
            ];

            $escape = static fn(string $value): string => \htmlspecialchars(
                $value !== '' ? $value : 'Not supplied',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $rows = '';
            foreach ($details as $label => $value) {
                $rows .= '<tr><th align="left" style="padding:6px 12px 6px 0;vertical-align:top">'
                    . $escape($label)
                    . '</th><td style="padding:6px 0;vertical-align:top">'
                    . $escape(\trim((string) $value))
                    . '</td></tr>';
            }

            $body = '<p>A new Scholar9 API-access request was submitted from OJS.</p>'
                . '<table cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
                . '<p>This notification intentionally excludes API credentials, tokens, and webhook secrets.</p>';
            $mailable = new class extends Mailable {
            };
            $mailable
                ->to('inquiry@ojscloud.com', 'OJSCloud Inquiry')
                ->subject('Scholar9 API access request - ' . ($journalName !== '' ? $journalName : 'Unnamed journal'))
                ->body($body);
            if ($hasReplyTo) {
                $mailable->replyTo($contactEmail, $contactName);
            }

            Mail::send($mailable);
        } catch (\Throwable $exception) {
            \error_log('[Scholar9] API access request notification could not be sent: ' . $exception->getMessage());
        }
    }

    /** Keep remote diagnostic text useful without allowing markup or log spam. */
    private function safeSyncError(string $message): string
    {
        $message = \strip_tags($message);
        $message = (string) \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $message);
        $message = \trim($message);
        if ($message === '') {
            return (string) __('plugins.generic.scholar9Review.sync.failed');
        }

        return \function_exists('mb_substr')
            ? \mb_substr($message, 0, 500, 'UTF-8')
            : \substr($message, 0, 500);
    }

    /**
     * Build the canonical, submission-independent journal payload sent to Scholar9.
     * Only journal configuration is included; users, submissions and credentials
     * are never exposed by this operation.
     */
    private function buildJournalSyncPayload($context, $request): array
    {
        return (new OjsJournalMetadataProvider())->build($context, $request);
    }

    // ── Template hooks ─────────────────────────────────────────────────────────

    public function addEditorPanel(string $hookName, array $params): bool
    {
        /** @var \APP\template\TemplateManager $templateMgr */
        $templateMgr = $params[0];
        $output      = &$params[2];

        $request    = Application::get()->getRequest();
        $context    = $request->getContext();
        $submission = $templateMgr->getTemplateVars('submission');

        if (!$submission || !$context) {
            return false;
        }

        $dao    = new Scholar9ReviewDAO();
        $record = $dao->getBySubmission($context->getId(), $submission->getId());
        $client = new Scholar9ApiClient($this, $context->getId());

        $templateMgr->assign([
            's9_record'     => $record,
            's9_configured' => $client->isConfigured(),
            's9_csrf_token' => $request->getSession()->token(),
            's9_ajax_url'   => $request->getRouter()->url(
                $request, null, 'scholar9review', 'action'
            ),
        ]);

        $output .= $templateMgr->fetch($this->getTemplateResource('editorPanel.tpl'));
        return false;
    }

    public function addAuthorPanel(string $hookName, array $params): bool
    {
        /** @var \APP\template\TemplateManager $templateMgr */
        $templateMgr = $params[0];
        $output      = &$params[2];

        $request    = Application::get()->getRequest();
        $context    = $request->getContext();
        $submission = $templateMgr->getTemplateVars('submission');

        if (!$submission || !$context) {
            return false;
        }

        $dao    = new Scholar9ReviewDAO();
        $record = $dao->getBySubmission($context->getId(), $submission->getId());

        if (!$record || $record['status'] === 'pending') {
            return false;
        }

        $templateMgr->assign(['s9_record' => $record]);
        $output .= $templateMgr->fetch($this->getTemplateResource('authorPanel.tpl'));
        return false;
    }

    // ── Page handler ───────────────────────────────────────────────────────────

    public function loadHandler(string $hookName, array $params): bool
    {
        $page = &$params[0];
        $handler = &$params[3];

        if ($page !== 'scholar9review') {
            return false;
        }

        // OJS 3.5 requires LoadHandler callbacks to provide the handler
        // instance directly. HANDLER_CLASS was removed and now throws.
        $handler = new Scholar9ReviewHandler();
        return true;
    }

    // ── OJS reviewer assignment hook ────────────────────────────────────────────

    public function onOjsReviewerAssigned(string $hookName, array $params): bool
    {
        $reviewAssignment = $params[0];

        $submission = \APP\facades\Repo::submission()->get($reviewAssignment->getSubmissionId());
        if (!$submission) {
            return false;
        }

        $contextId = (int) $submission->getData('contextId');
        $dao    = new Scholar9ReviewDAO();
        $reviewRoundId = (int) ($reviewAssignment->getReviewRoundId() ?? 0);
        $record = $dao->getBySubmission(
            $contextId,
            $reviewAssignment->getSubmissionId(),
            $reviewRoundId
        );
        // Reviews created before round-aware integration used the submission-
        // level record (round 0). Preserve compatibility with those records.
        if (!$record && $reviewRoundId !== 0) {
            $record = $dao->getBySubmission(
                $contextId,
                $reviewAssignment->getSubmissionId(),
                0
            );
        }

        if (!$record || empty($record['s9_paper_id'])) {
            return false;
        }

        $client = new Scholar9ApiClient($this, $contextId);
        if (!$client->isConfigured()) {
            return false;
        }

        $sync = new Scholar9SyncService($this, $client, $dao);
        $sync->pushOjsReviewer($record, $reviewAssignment);

        return false;
    }
}
