<?php

namespace APP\plugins\generic\scholar9Review;

use APP\core\Application;
use APP\facades\Repo;
use PKP\handler\PKPHandler;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

class Scholar9ReviewHandler extends PKPHandler
{
    private const MAX_WEBHOOK_BODY_BYTES = 1048576;

    /**
     * Floor between two editor-requested (forced, cache-bypassing) syncs for
     * the same review. A forced sync always calls the live Scholar9 API, so
     * without this floor a few quick double-clicks can burn through that
     * journal's remote rate limit on their own.
     */
    private const MIN_FORCED_SYNC_INTERVAL_SECONDS = 15;

    public function __construct()
    {
        parent::__construct();
        // This handler returns JSON and has its own operation-specific access
        // controls below. The normal page policy redirects failures to an HTML
        // login page, which breaks AJAX and prevents public signed webhooks.
        $this->setEnforceRestrictedSite(false);
        $this->addRoleAssignment(
            [
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ],
            ['action', 'status']
        );
    }

    /**
     * Access is enforced per operation because this route serves both
     * authenticated editor JSON and a public HMAC-signed webhook.
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        return true;
    }

    private function plugin(): \PKP\plugins\GenericPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', 'scholar9reviewplugin');
        if (!$plugin) {
            throw new \RuntimeException('Scholar9 Review plugin is unavailable.');
        }
        return $plugin;
    }

    // ── Webhook — public, HMAC-verified ────────────────────────────────────

    public function webhook(array $args, \PKP\core\PKPRequest $request): void
    {
        \header('Content-Type: application/json');

        if ($request->getRequestMethod() !== 'POST') {
            \http_response_code(405);
            echo \json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_WEBHOOK_BODY_BYTES) {
            \http_response_code(413);
            echo \json_encode(['error' => 'Webhook payload is too large']);
            exit;
        }

        $rawBody   = (string) \file_get_contents('php://input');
        if (\strlen($rawBody) > self::MAX_WEBHOOK_BODY_BYTES) {
            \http_response_code(413);
            echo \json_encode(['error' => 'Webhook payload is too large']);
            exit;
        }
        $payload = \json_decode($rawBody, true);
        if (!\is_array($payload)) {
            \http_response_code(400);
            echo \json_encode(['error' => 'Invalid JSON payload']);
            exit;
        }
        $signature = $_SERVER['HTTP_X_S9_SIGNATURE'] ?? '';
        if (!\preg_match('/^(?:sha256=)?[a-f0-9]{64}$/i', (string) $signature)) {
            \http_response_code(401);
            echo \json_encode(['error' => 'Invalid webhook signature']);
            exit;
        }

        $context = $request->getContext();
        if (!$context) {
            \http_response_code(400);
            exit;
        }

        $plugin = $this->plugin();
        $client = new Scholar9ApiClient($plugin, $context->getId());
        $dao    = new Scholar9ReviewDAO();
        $sync   = new Scholar9SyncService($plugin, $client, $dao);

        if ($sync->handleWebhook($payload, $rawBody, $signature, (int) $context->getId())) {
            \http_response_code(200);
            echo \json_encode(['success' => true]);
        } else {
            \http_response_code(422);
            echo \json_encode(['error' => 'Unprocessable webhook']);
        }
        exit;
    }

    // ── AJAX action — editor-authenticated ─────────────────────────────────

    public function action(array $args, \PKP\core\PKPRequest $request): void
    {
        \header('Content-Type: application/json');

        if ($request->getRequestMethod() !== 'POST') {
            $this->jsonError('Method not allowed', 405);
            exit;
        }

        $user = $request->getUser();
        $context = $request->getContext();
        if (!$this->isEditor($user, $context)) {
            $this->jsonError('You do not have permission to manage this submission', 403);
            exit;
        }

        if (!$request->checkCSRF()) {
            \http_response_code(403);
            echo \json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        $submissionId = (int) ($request->getUserVar('submissionId') ?? 0);
        $roundId      = (int) ($request->getUserVar('reviewRoundId') ?? 0);
        $op           = (string) ($request->getUserVar('op') ?? '');

        if (!$submissionId || !$context) {
            \http_response_code(400);
            echo \json_encode(['error' => 'Missing submissionId']);
            exit;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            $this->jsonError('Submission not found', 404);
            exit;
        }

        $plugin = $this->plugin();
        $client = new Scholar9ApiClient($plugin, $context->getId());
        $dao    = new Scholar9ReviewDAO();
        $sync   = new Scholar9SyncService($plugin, $client, $dao);

        if (!$client->isConfigured()) {
            \http_response_code(503);
            echo \json_encode(['error' => 'Scholar9 not configured']);
            exit;
        }

        try {
            match ($op) {
                'create'           => $this->opCreate($request, $plugin, $client, $dao, $sync, $context, $submissionId, $roundId),
                'sync'             => $this->opSync($dao, $sync, $context, $submissionId, $roundId),
                'send_message'     => $this->opSendMessage($request, $client, $dao, $context, $submissionId, $roundId),
                'rate_reviewer'    => $this->opRateReviewer($request, $client, $dao, $context, $submissionId, $roundId),
                'send_file'        => $this->opSendFile($request, $client, $dao, $context, $submissionId, $roundId),
                'change_reviewer'  => $this->opChangeReviewer($request, $client, $dao, $sync, $context, $submissionId, $roundId),
                'toggle_open'      => $this->opToggleOpen($request, $client, $dao, $context, $submissionId, $roundId),
                'toggle_full_review' => $this->opToggleFullReview($client, $dao, $context, $submissionId, $roundId),
                'search_reviewers' => $this->opSearchReviewers($request, $client),
                'parameters'       => $this->opReviewParameters($client),
                default            => $this->jsonError('Unknown operation', 400),
            };
        } catch (\Throwable $exception) {
            \error_log('[Scholar9] Action failed: ' . $exception->getMessage());
            $this->jsonError('The Scholar9 operation could not be completed. Check the server log for details.', 500);
        }
        exit;
    }

    // ── Lightweight status poll ─────────────────────────────────────────────

    public function status(array $args, \PKP\core\PKPRequest $request): void
    {
        \header('Content-Type: application/json');

        $context      = $request->getContext();
        $submissionId = (int) ($request->getUserVar('submissionId') ?? 0);
        $roundId      = (int) ($request->getUserVar('reviewRoundId') ?? 0);

        if (!$this->isEditor($request->getUser(), $context)) {
            $this->jsonError('You do not have permission to view this review', 403);
            exit;
        }

        if (!$context || !$submissionId) {
            $this->jsonError('Missing submissionId', 400);
            exit;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            $this->jsonError('Submission not found', 404);
            exit;
        }

        $dao    = new Scholar9ReviewDAO();
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);

        // Keep the browser-facing snapshot current. Webhooks normally update
        // this row immediately. The cached pull is a rate-limited fallback for
        // installations whose webhook URL is temporarily unreachable (for
        // example, a localhost development journal).
        if ($record) {
            $client = new Scholar9ApiClient($this->plugin(), (int) $context->getId());
            if ($client->isConfigured()) {
                $sync = new Scholar9SyncService($this->plugin(), $client, $dao);
                if ($sync->pullSync($record, false)) {
                    $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
                }
            }
        }

        echo \json_encode([
            'status'         => $record['status']         ?? 'not_started',
            'recommendation' => $record['recommendation'] ?? null,
            'score'          => $record['score']          ?? null,
            'last_synced_at' => $record['last_synced_at'] ?? null,
            'record'          => $this->publicRecord($record),
            'files'           => $this->submissionFiles($submissionId),
        ]);
        exit;
    }

    /**
     * Serve a file selected by an editor through a time-limited signed URL.
     */
    public function download(array $args, \PKP\core\PKPRequest $request): void
    {
        $context = $request->getContext();
        $submissionId = (int) ($request->getUserVar('submissionId') ?? 0);
        $submissionFileId = (int) ($request->getUserVar('fileId') ?? 0);
        $expires = (int) ($request->getUserVar('expires') ?? 0);
        $token = (string) ($request->getUserVar('token') ?? '');

        if (!$context || !$submissionId || !$submissionFileId || $expires < \time()) {
            $this->jsonError('This file link is invalid or has expired', 403);
            exit;
        }

        $plugin = $this->plugin();
        $secret = (string) ($plugin->getSetting($context->getId(), 'webhookSecret') ?? '');
        $expected = \hash_hmac('sha256', "{$context->getId()}:{$submissionId}:{$submissionFileId}:{$expires}", $secret);
        if ($secret === '' || !\hash_equals($expected, $token)) {
            $this->jsonError('Invalid file signature', 403);
            exit;
        }

        $submission = Repo::submission()->get($submissionId);
        $submissionFile = Repo::submissionFile()->get($submissionFileId);
        if (
            !$submission
            || !$submissionFile
            || (int) $submission->getData('contextId') !== (int) $context->getId()
            || (int) $submissionFile->getData('submissionId') !== $submissionId
        ) {
            $this->jsonError('File not found', 404);
            exit;
        }

        $fileId = (int) $submissionFile->getData('fileId');
        $filename = (string) (
            $submissionFile->getLocalizedData('name')
            ?: $submissionFile->getData('name')
            ?: "submission-file-{$submissionFileId}"
        );
        app()->get('file')->download($fileId, $filename);
        exit;
    }

    // ── Operations ───────────────────────────────────────────────────────────

    private function opCreate(
        \PKP\core\PKPRequest $request,
        \PKP\plugins\GenericPlugin $plugin,
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        Scholar9SyncService $sync,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $existing = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if ($existing && !empty($existing['s9_paper_id'])) {
            echo \json_encode(['success' => false, 'error' => 'Already sent to Scholar9', 'record' => $this->publicRecord($existing)]);
            return;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            $this->jsonError('Submission not found', 404);
            return;
        }

        $publication = $submission->getCurrentPublication();
        $locale      = (string) (
            $submission->getData('locale')
            ?: $context->getPrimaryLocale()
            ?: 'en'
        );
        $titleData = $publication?->getData('title');
        $abstractData = $publication?->getData('abstract');
        $meta        = [
            'title'    => $this->localizedValue($titleData, $locale),
            'abstract' => \strip_tags($this->localizedValue($abstractData, $locale)),
            'keywords' => '',
            'language' => $locale,
            'author'   => '',
        ];

        if ($publication) {
            $names = [];
            $authors = Repo::author()
                ->getCollector()
                ->filterByPublicationIds([(int) $publication->getId()])
                ->orderBy(\PKP\author\Collector::ORDERBY_SEQUENCE)
                ->getMany();
            foreach ($authors as $author) {
                $names[] = $author->getFullName(true, false, $locale);
            }
            $meta['author'] = \implode(', ', $names);
        }

        $submissionIdStr = $context->getPath() . '_R' . ($roundId ?: 1) . '_' . $submissionId;
        $selectedFileIds = \array_values(\array_unique(\array_filter(\array_map(
            'intval',
            \explode(',', (string) ($request->getUserVar('fileIds') ?? ''))
        ))));
        if (!$selectedFileIds) {
            $this->jsonError('Select a manuscript file for review', 422);
            return;
        }
        $reviewerIds = \array_slice(\array_values(\array_unique(\array_filter(\array_map(
            'trim',
            \explode(',', (string) ($request->getUserVar('reviewerIds') ?? ''))
        )))), 0, 5);
        if (!$reviewerIds) {
            $this->jsonError('Select at least one reviewer', 422);
            return;
        }
        $parameterIds = \array_values(\array_unique(\array_filter(\array_map(
            'trim',
            \explode(',', (string) ($request->getUserVar('parameterIds') ?? ''))
        ))));
        if (!$parameterIds) {
            $this->jsonError('Select at least one review parameter', 422);
            return;
        }

        $fileUrls = [];
        foreach ($selectedFileIds as $selectedFileId) {
            $submissionFile = Repo::submissionFile()->get($selectedFileId);
            if (!$submissionFile || (int) $submissionFile->getData('submissionId') !== $submissionId) {
                $this->jsonError('One or more selected files are invalid', 422);
                return;
            }
            $fileUrls[] = $this->signedFileUrl($request, $context, $submissionId, $selectedFileId);
        }

        $manuscriptUrl = $fileUrls[0];
        $plagiarismFileId = (int) ($request->getUserVar('plagiarismFileId') ?? 0);
        $plagiarismUrl = null;
        if ($plagiarismFileId) {
            $plagiarismFile = Repo::submissionFile()->get($plagiarismFileId);
            if (!$plagiarismFile || (int) $plagiarismFile->getData('submissionId') !== $submissionId) {
                $this->jsonError('The selected plagiarism file is invalid', 422);
                return;
            }
            $plagiarismUrl = $this->signedFileUrl($request, $context, $submissionId, $plagiarismFileId);
        }
        $supplementaryUrls = \array_slice($fileUrls, 1);
        $externalUrls = $selectedFileIds ? [$plagiarismUrl] : [$manuscriptUrl, $plagiarismUrl];
        foreach ($externalUrls as $url) {
            if ($url !== null && $url !== '' && (!\filter_var($url, FILTER_VALIDATE_URL) || \parse_url($url, PHP_URL_SCHEME) !== 'https')) {
                $this->jsonError('File URLs must be valid HTTPS URLs', 422);
                return;
            }
        }

        $recordId = $sync->createReview(
            $context->getId(), $submissionId, $roundId,
            $meta,
            $manuscriptUrl,
            $submissionIdStr,
            $plagiarismUrl,
            $supplementaryUrls,
            $reviewerIds,
            $parameterIds
        );

        if ($recordId) {
            $warning = $sync->getLastWarning();
            $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
            // Scholar9 creates the assignment synchronously. Pull the initial
            // snapshot before returning so reviewer identity/status is visible
            // on the first render rather than after a manual refresh.
            if ($record && $sync->pullSync($record)) {
                $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
            }
            echo \json_encode([
                'success' => true,
                'record' => $this->publicRecord($record),
                'warning' => $warning,
            ]);
        } else {
            $this->jsonError(
                $client->getLastError()
                    ? 'Scholar9: ' . $client->getLastError()
                    : 'Failed to create Scholar9 review',
                502,
                $client->getRetryAfterSeconds()
            );
        }
    }

    private function opSync(
        Scholar9ReviewDAO $dao,
        Scholar9SyncService $sync,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }

        $lastSyncedAt = \strtotime((string) ($record['last_synced_at'] ?? '')) ?: 0;
        $secondsSinceSync = $lastSyncedAt ? \time() - $lastSyncedAt : null;
        if ($secondsSinceSync !== null && $secondsSinceSync < self::MIN_FORCED_SYNC_INTERVAL_SECONDS) {
            // Serve the just-synced snapshot instead of forcing another live
            // Scholar9 call; this is what actually protects the account's
            // rate limit from rapid repeat clicks of the Sync button.
            echo \json_encode(['success' => true, 'record' => $this->publicRecord($record)]);
            return;
        }

        $ok     = $sync->pullSync($record);
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$ok) {
            $this->jsonError(
                $sync->getLastError() ?: 'Unable to sync this review from Scholar9',
                502,
                $sync->getRetryAfterSeconds()
            );
            return;
        }
        echo \json_encode(['success' => $ok, 'record' => $this->publicRecord($record)]);
    }

    private function opSendMessage(
        \PKP\core\PKPRequest $request,
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record || empty($record['s9_paper_id'])) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }
        if ($this->isConversationClosed($record)) {
            $this->jsonError('The Scholar9 review is complete and this conversation is closed', 409);
            return;
        }

        $rawText = (string) ($request->getUserVar('text') ?? '');
        if (\mb_strlen($rawText) > Scholar9ApiClient::MAX_MESSAGE_LENGTH) {
            $this->jsonError(
                'Message must not exceed ' . Scholar9ApiClient::MAX_MESSAGE_LENGTH . ' characters',
                422
            );
            return;
        }
        $text = $this->sanitizeMessageText($rawText);
        if ($text === '') {
            $this->jsonError('Message text required', 422);
            return;
        }

        $user       = $request->getUser();
        $reviewerId = \trim((string) ($request->getUserVar('reviewerId') ?? ''));
        if ($reviewerId === '') {
            $reviewerId = (string) ($record['reviewer_id'] ?? '');
        }
        $allowedReviewerIds = \array_values(\array_filter(\array_map(
            fn(array $reviewer): string => (string) ($reviewer['id'] ?? ''),
            \is_array($record['reviewers_cache'] ?? null) ? $record['reviewers_cache'] : []
        )));
        if (
            $reviewerId === ''
            || ($allowedReviewerIds && !\in_array($reviewerId, $allowedReviewerIds, true))
        ) {
            $this->jsonError('Select a valid reviewer before sending a message', 422);
            return;
        }
        if ($this->isReviewerConversationClosed($record, $reviewerId)) {
            $this->jsonError('This reviewer has completed the review and the conversation is closed', 409);
            return;
        }
        $result     = $client->sendMessage(
            (string) $record['s9_paper_id'],
            $reviewerId,
            $text,
            ['sender_name' => $user->getFullName(), 'sender_email' => $user->getEmail()]
        );

        if (!$result || empty($result['success'])) {
            $this->jsonError(
                $client->getLastError()
                    ? 'Scholar9: ' . $client->getLastError()
                    : 'Scholar9 did not accept the message',
                502,
                $client->getRetryAfterSeconds()
            );
            return;
        }
        $this->appendOutgoingMessage(
            $dao,
            $dao->getBySubmission($context->getId(), $submissionId, $roundId) ?? $record,
            $reviewerId,
            $this->reviewerNameForId($record, $reviewerId),
            $user->getFullName(),
            $text,
            [],
            (string) ($result['data']['id'] ?? '')
        );
        $updated = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        echo \json_encode([
            'success' => true,
            'data' => $result,
            'sanitized_text' => $text,
            'record' => $this->publicRecord($updated),
        ]);
    }

    private function opRateReviewer(
        \PKP\core\PKPRequest $request,
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record || empty($record['s9_paper_id'])) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }

        $reviewerId = \trim((string) ($request->getUserVar('reviewerId') ?? ''));
        $reviewer = null;
        foreach ((array) ($record['reviewers_cache'] ?? []) as $candidate) {
            if (\is_array($candidate) && (string) ($candidate['id'] ?? '') === $reviewerId) {
                $reviewer = $candidate;
                break;
            }
        }
        if (!$reviewer || !\in_array(\strtolower((string) ($reviewer['status'] ?? '')), [
            'reviewer_completed', 'review_completed', 'completed', 'decision_returned',
        ], true)) {
            $this->jsonError('A rating can be submitted only after this reviewer completes the review', 409);
            return;
        }

        foreach ((array) ($record['messages_cache'] ?? []) as $message) {
            if (!\is_array($message)
                || (string) ($message['reviewer_id'] ?? '') !== $reviewerId
                || (string) ($message['from'] ?? '') !== 'editor') {
                continue;
            }
            if ($this->messageContainsRating($message)) {
                $this->jsonError('This reviewer has already been rated by the publisher', 409);
                return;
            }
        }

        $rawRatings = $request->getUserVar('ratings');
        if (\is_string($rawRatings)) {
            $rawRatings = \array_filter(\array_map('trim', \explode(',', $rawRatings)), 'strlen');
        }
        $ratings = $this->normalizeReviewerRatings($rawRatings);
        if ($ratings === null) {
            $this->jsonError('Rate all five reviewer performance criteria', 422);
            return;
        }

        $rawComment = (string) ($request->getUserVar('comment') ?? '');
        if (\mb_strlen($rawComment) > 2000) {
            $this->jsonError('Rating feedback must not exceed 2000 characters', 422);
            return;
        }
        $comment = $this->sanitizeMessageText($rawComment);
        $result = $client->submitReviewerRating(
            (string) $record['s9_paper_id'],
            $reviewerId,
            $ratings,
            $comment
        );
        if (!$result || empty($result['success'])) {
            $this->jsonError(
                $client->getLastError()
                    ? 'Scholar9: ' . $client->getLastError()
                    : 'Scholar9 did not accept the reviewer rating',
                502,
                $client->getRetryAfterSeconds()
            );
            return;
        }

        $user = $request->getUser();
        $displayText = $comment !== '' ? $comment : 'Publisher reviewer rating';
        $this->appendOutgoingMessage(
            $dao,
            $record,
            $reviewerId,
            $this->reviewerNameForId($record, $reviewerId),
            $user->getFullName(),
            $displayText,
            [],
            (string) ($result['data']['id'] ?? ''),
            ['rating_data' => $ratings, 'status' => 'publisher_rating']
        );
        $updated = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        echo \json_encode([
            'success' => true,
            'data' => $result,
            'record' => $this->publicRecord($updated),
        ]);
    }

    /** Return exactly five integer ratings, rejecting decimals and coercion-prone input. */
    private function normalizeReviewerRatings(mixed $rawRatings): ?array
    {
        if (!\is_array($rawRatings) || \count($rawRatings) !== 5) {
            return null;
        }
        $ratings = [];
        foreach ($rawRatings as $rating) {
            $value = \is_int($rating) ? (string) $rating : \trim((string) $rating);
            if (!\preg_match('/^[1-5]$/', $value)) {
                return null;
            }
            $ratings[] = (int) $value;
        }
        return $ratings;
    }

    /** Detect current and legacy publisher ratings for server-side idempotency. */
    private function messageContainsRating(array $message): bool
    {
        if (\is_array($message['rating_data'] ?? null) && !empty($message['rating_data'])) {
            return true;
        }
        $text = (string) ($message['text'] ?? $message['message'] ?? $message['body'] ?? '');
        return (bool) \preg_match('/(?:^|\R)\s*Rating\s*:\s*[1-5](?:\s*,\s*[1-5]){4}\s*$/i', $text);
    }

    private function opToggleOpen(
        \PKP\core\PKPRequest $request,
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }

        $reviewerId = \trim((string) ($request->getUserVar('reviewerId') ?? ''));
        if ($reviewerId === '' || !$this->recordHasReviewer($record, $reviewerId)) {
            $this->jsonError('Select a valid Scholar9 reviewer before changing open-review visibility', 422);
            return;
        }
        $reviewers = (array) ($record['reviewers_cache'] ?? []);
        $currentValue = false;
        foreach ($reviewers as $reviewer) {
            if (\is_array($reviewer) && (string) ($reviewer['id'] ?? $reviewer['_id'] ?? '') === $reviewerId) {
                $currentValue = (bool) ($reviewer['open_review'] ?? false);
                break;
            }
        }
        // Preserve the pre-1.3.4 single-reviewer flag during migration.
        if (\count($reviewers) <= 1 && !$currentValue) {
            $currentValue = (bool) ($record['open_review'] ?? false);
        }
        $newVal = !$currentValue;
        $response = $client->setOpenReviewStatus((string) $record['s9_paper_id'], $reviewerId, $newVal);
        if (!$response || ($response['success'] ?? true) === false) {
            $this->jsonError(
                $client->getLastError() ?: 'Scholar9 could not update open-review visibility',
                502,
                $client->getRetryAfterSeconds()
            );
            return;
        }
        foreach ($reviewers as &$reviewer) {
            if (\is_array($reviewer) && (string) ($reviewer['id'] ?? $reviewer['_id'] ?? '') === $reviewerId) {
                $reviewer['open_review'] = $newVal;
                break;
            }
        }
        unset($reviewer);
        $dao->update((int) $record['id'], [
            'open_review' => (int) $newVal,
            'reviewers_cache' => $reviewers,
        ]);
        echo \json_encode(['success' => true, 'open_review' => $newVal, 'record' => $this->publicRecord($dao->getBySubmission($context->getId(), $submissionId, $roundId))]);
    }

    private function opToggleFullReview(
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record || empty($record['s9_paper_id'])) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }
        $newVal = !(bool) ($record['full_review'] ?? false);
        $response = $client->setFullReviewStatus((string) $record['s9_paper_id'], $newVal);
        if (!$response || ($response['success'] ?? true) === false) {
            $this->jsonError(
                $client->getLastError() ?: 'Scholar9 could not update full-review visibility',
                502,
                $client->getRetryAfterSeconds()
            );
            return;
        }
        $dao->update((int) $record['id'], ['full_review' => (int) $newVal]);
        echo \json_encode(['success' => true, 'full_review' => $newVal, 'record' => $this->publicRecord($dao->getBySubmission($context->getId(), $submissionId, $roundId))]);
    }

    private function recordHasReviewer(array $record, string $reviewerId): bool
    {
        foreach ((array) ($record['reviewers_cache'] ?? []) as $reviewer) {
            if (\is_array($reviewer) && (string) ($reviewer['id'] ?? $reviewer['_id'] ?? '') === $reviewerId) {
                return true;
            }
        }
        return (string) ($record['reviewer_id'] ?? '') === $reviewerId;
    }

    private function opChangeReviewer(
        \PKP\core\PKPRequest $request,
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        Scholar9SyncService $sync,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record || empty($record['s9_paper_id'])) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }
        if ($this->isConversationClosed($record)) {
            $this->jsonError('The Scholar9 review is complete; the reviewer can no longer be changed', 409);
            return;
        }
        $reviewerId = \trim((string) ($request->getUserVar('reviewerId') ?? ''));
        if ($reviewerId === '') {
            $this->jsonError('Select a reviewer', 422);
            return;
        }

        $result = $client->assignReviewer((string) $record['s9_paper_id'], $reviewerId);
        if (!$result || empty($result['success'])) {
            $this->jsonError(
                $client->getLastError()
                    ? 'Scholar9: ' . $client->getLastError()
                    : 'Scholar9 did not accept the reviewer assignment',
                502,
                $client->getRetryAfterSeconds()
            );
            return;
        }

        $warning = null;
        if (!$sync->pullSync($record)) {
            // The assignment already succeeded remotely. Keep OJS consistent
            // immediately and let a later manual/automatic sync enrich it.
            $reviewerName = $this->sanitizeMessageText(
                (string) ($request->getUserVar('reviewerName') ?? 'Reviewer')
            );
            if ($reviewerName === '') {
                $reviewerName = 'Reviewer';
            }
            $reviewers = array_values(array_filter(
                is_array($record['reviewers_cache'] ?? null) ? $record['reviewers_cache'] : [],
                fn(array $reviewer): bool => (string) ($reviewer['id'] ?? '') !== $reviewerId
            ));
            array_unshift($reviewers, [
                'id' => $reviewerId,
                'name' => $reviewerName,
                'email' => '',
                'status' => 'awaiting_reviewer_response',
                'assignment_id' => '',
            ]);
            $dao->update((int) $record['id'], [
                'status' => 'awaiting_reviewer_response',
                'reviewer_id' => $reviewerId,
                'reviewer_name' => $reviewerName,
                'reviewer_email' => '',
                'reviewers_cache' => $reviewers,
            ]);
            $warning = $sync->getLastError() ?: 'Reviewer assigned; live details will update on the next sync.';
        }
        $updated = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        echo \json_encode([
            'success' => true,
            'record' => $this->publicRecord($updated),
            'warning' => $warning,
        ]);
    }

    private function opSendFile(
        \PKP\core\PKPRequest $request,
        Scholar9ApiClient $client,
        Scholar9ReviewDAO $dao,
        mixed $context,
        int $submissionId,
        int $roundId
    ): void {
        $record = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        if (!$record || empty($record['s9_paper_id'])) {
            $this->jsonError('No Scholar9 review found', 404);
            return;
        }
        if ($this->isConversationClosed($record)) {
            $this->jsonError('The Scholar9 review is complete and this conversation is closed', 409);
            return;
        }

        $fileId = (int) ($request->getUserVar('fileId') ?? 0);
        $selectedFile = null;
        foreach ($this->submissionFiles($submissionId) as $file) {
            if ((int) $file['id'] === $fileId) {
                $selectedFile = $file;
                break;
            }
        }
        if (!$selectedFile) {
            $this->jsonError('Select a valid submission file', 422);
            return;
        }

        $reviewerId = \trim((string) ($request->getUserVar('reviewerId') ?? ''));
        if ($reviewerId === '') {
            $reviewerId = (string) ($record['reviewer_id'] ?? '');
        }
        $allowedReviewerIds = \array_values(\array_filter(\array_map(
            fn(array $reviewer): string => (string) ($reviewer['id'] ?? ''),
            \is_array($record['reviewers_cache'] ?? null) ? $record['reviewers_cache'] : []
        )));
        if (
            $reviewerId === ''
            || ($allowedReviewerIds && !\in_array($reviewerId, $allowedReviewerIds, true))
        ) {
            $this->jsonError('Select a valid reviewer before sending a file', 422);
            return;
        }
        if ($this->isReviewerConversationClosed($record, $reviewerId)) {
            $this->jsonError('This reviewer has completed the review and the conversation is closed', 409);
            return;
        }

        $fileUrl = $this->signedFileUrl($request, $context, $submissionId, $fileId);
        $safeFileName = \basename(\str_replace('\\', '/', (string) $selectedFile['name']));
        $safeFileName = $this->sanitizeMessageText($safeFileName);
        $safeFileName = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $safeFileName));
        $safeFileName = \mb_substr($safeFileName, 0, 255);
        if ($safeFileName === '') {
            $safeFileName = 'Submission file';
        }
        $fileMessage = $this->buildFileShareMessage($safeFileName, $fileUrl);
        if ($fileMessage === null) {
            $this->jsonError('Unable to create a safe Scholar9 file link', 500);
            return;
        }
        $user = $request->getUser();
        $result = $client->sendMessage(
            (string) $record['s9_paper_id'],
            $reviewerId,
            $fileMessage,
            [
                'sender_name' => $user->getFullName(),
                'sender_email' => $user->getEmail(),
            ]
        );

        if (!$result || empty($result['success'])) {
            $this->jsonError(
                $client->getLastError()
                    ? 'Scholar9: ' . $client->getLastError()
                    : 'Scholar9 did not accept the file',
                502,
                $client->getRetryAfterSeconds()
            );
            return;
        }
        $messageText = 'File shared: ' . $safeFileName;
        $this->appendOutgoingMessage(
            $dao,
            $dao->getBySubmission($context->getId(), $submissionId, $roundId) ?? $record,
            $reviewerId,
            $this->reviewerNameForId($record, $reviewerId),
            $user->getFullName(),
            $messageText,
            [
                'attachment_name' => $safeFileName,
                'attachment_url' => $fileUrl,
            ],
            (string) ($result['data']['id'] ?? '')
        );
        $updated = $dao->getBySubmission($context->getId(), $submissionId, $roundId);
        echo \json_encode([
            'success' => true,
            'data' => $result,
            'attachment_name' => $safeFileName,
            'attachment_url' => $fileUrl,
            'record' => $this->publicRecord($updated),
        ]);
    }

    private function opSearchReviewers(\PKP\core\PKPRequest $request, Scholar9ApiClient $client): void
    {
        $query  = \trim((string) ($request->getUserVar('q') ?? ''));
        $pool   = (string) ($request->getUserVar('pool') ?? 'all');
        if (\mb_strlen($query) < 2) {
            $this->jsonError('Enter at least two characters to search reviewers', 422);
            return;
        }
        if (\mb_strlen($query) > 100) {
            $this->jsonError('Reviewer search text must not exceed 100 characters', 422);
            return;
        }
        // The current integration exposes the verified all-reviewers pool.
        // Do not let arbitrary pool values multiply remote/cache keys.
        $pool = 'all';
        $result = $client->searchReviewers($query, $pool);
        if (!$result || empty($result['success'])) {
            $this->jsonError('Reviewer search failed', 502);
            return;
        }
        $reviewers = \is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($reviewers as &$reviewer) {
            if (\is_array($reviewer)) {
                unset($reviewer['email'], $reviewer['reviewer_email']);
            }
        }
        unset($reviewer);
        echo \json_encode([
            'reviewers' => $reviewers,
            'meta' => $result['meta'] ?? null,
        ]);
    }

    private function opReviewParameters(Scholar9ApiClient $client): void
    {
        $result = $client->getReviewParameters();
        if (!$result || empty($result['success'])) {
            $this->jsonError('Could not load review parameters', 502);
            return;
        }
        echo \json_encode(['parameters' => $result['data'] ?? []]);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function jsonError(string $message, int $code = 400, ?int $retryAfter = null): void
    {
        \http_response_code($code);
        $payload = ['error' => $message];
        if ($retryAfter !== null && $retryAfter > 0) {
            $payload['retry_after'] = $retryAfter;
        }
        echo \json_encode($payload);
    }

    private function isEditor(mixed $user, mixed $context): bool
    {
        if (!$user || !$context) {
            return false;
        }

        return $user->hasRole(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT],
            (int) $context->getId()
        ) || $user->hasRole(Role::ROLE_ID_SITE_ADMIN, Application::SITE_CONTEXT_ID);
    }

    private function sanitizeMessageText(string $value): string
    {
        $value = \html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) \preg_replace(
            '#<(script|style|iframe|object|embed|svg|math)\b[^>]*>.*?</\1>#is',
            '',
            $value
        );
        $value = \strip_tags($value);
        $value = (string) \preg_replace('/\b(?:javascript|vbscript|data)\s*:/i', '', $value);
        $value = (string) \preg_replace('/\bon[a-z]+\s*=\s*(?:["\'][^"\']*["\']|[^\s]+)/i', '', $value);
        $value = (string) \preg_replace(
            '/\b(?:alert|eval|prompt|confirm|Function|setTimeout|setInterval)\s*\([^)]*\)\s*;?/i',
            '',
            $value
        );
        $value = (string) \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return \trim($value);
    }

    /**
     * Build the fixed, allowlisted markup Scholar9's native reviewer UI uses
     * to identify and style a shared-file message. No editor-supplied HTML is
     * accepted here; both dynamic values are validated and escaped.
     */
    private function buildFileShareMessage(string $fileName, string $fileUrl): ?string
    {
        $fileName = $this->sanitizeMessageText($fileName);
        $fileName = \basename(\str_replace('\\', '/', $fileName));
        $fileName = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $fileName));
        $fileName = \mb_substr($fileName, 0, 255);

        $fileUrl = \trim($fileUrl);
        $scheme = \strtolower((string) \parse_url($fileUrl, PHP_URL_SCHEME));
        if (
            $fileName === ''
            || !\filter_var($fileUrl, FILTER_VALIDATE_URL)
            || !\in_array($scheme, ['http', 'https'], true)
        ) {
            return null;
        }

        $escapedName = \htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $escapedUrl = \htmlspecialchars($fileUrl, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $message = '<p><span><em>File shared: <u><a href="' . $escapedUrl
            . '" target="_blank" rel="noopener noreferrer">' . $escapedName
            . '</a></u></em></span></p>';

        return \mb_strlen($message) <= Scholar9ApiClient::MAX_MESSAGE_LENGTH ? $message : null;
    }

    /**
     * Persist an editor-originated message after Scholar9 accepts it.
     *
     * Webhooks and pull-sync will later replace/enrich this cache with the
     * canonical remote thread. Persisting immediately guarantees that a page
     * reload does not hide a message or attachment that was already accepted.
     */
    private function appendOutgoingMessage(
        Scholar9ReviewDAO $dao,
        array $record,
        string $reviewerId,
        string $reviewerName,
        string $senderName,
        string $text,
        array $attachment = [],
        string $messageId = '',
        array $metadata = []
    ): void {
        $messages = \is_array($record['messages_cache'] ?? null)
            ? $record['messages_cache']
            : [];

        $messageId = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/u', '', $messageId));
        $messageId = \mb_substr($messageId, 0, 255);

        $message = [
            'id' => $messageId !== '' ? $messageId : 'ojs-' . \bin2hex(\random_bytes(12)),
            'from' => 'editor',
            'sender_name' => $this->sanitizeMessageText($senderName) ?: 'Publisher',
            'sender_role' => 'Publisher',
            'reviewer_id' => $reviewerId,
            'reviewer_name' => $this->sanitizeMessageText($reviewerName) ?: 'Reviewer',
            'text' => $this->sanitizeMessageText($text),
            'created_at' => \gmdate('c'),
        ];
        if (\is_array($metadata['rating_data'] ?? null)) {
            $message['rating_data'] = \array_values(\array_map('intval', $metadata['rating_data']));
        }
        if (!empty($metadata['status'])) {
            $message['status'] = $this->sanitizeMessageText((string) $metadata['status']);
        }
        if (!empty($attachment['attachment_name'])) {
            $message['attachment_name'] = $this->sanitizeMessageText(
                (string) $attachment['attachment_name']
            );
        }
        if (!empty($attachment['attachment_url'])) {
            $attachmentUrl = (string) $attachment['attachment_url'];
            $attachmentScheme = \strtolower((string) \parse_url($attachmentUrl, PHP_URL_SCHEME));
            if (
                \filter_var($attachmentUrl, FILTER_VALIDATE_URL)
                && \in_array($attachmentScheme, ['http', 'https'], true)
            ) {
                $message['attachment_url'] = $attachmentUrl;
            }
        }

        $merged = false;
        foreach ($messages as $index => $existing) {
            if (
                $messageId !== ''
                && \is_array($existing)
                && (string) ($existing['id'] ?? '') === $messageId
            ) {
                $messages[$index] = \array_merge($existing, $message);
                $merged = true;
                break;
            }
        }
        if (!$merged) {
            $messages[] = $message;
        }
        // Bound local cache growth while retaining a substantial discussion
        // history. The canonical Scholar9 thread remains the source of truth.
        $messages = \array_slice($messages, -1000);
        $dao->update((int) $record['id'], ['messages_cache' => $messages]);
    }

    private function reviewerNameForId(array $record, string $reviewerId): string
    {
        $reviewers = \is_array($record['reviewers_cache'] ?? null)
            ? $record['reviewers_cache']
            : [];
        foreach ($reviewers as $reviewer) {
            if ((string) ($reviewer['id'] ?? '') === $reviewerId) {
                $name = \trim((string) ($reviewer['name'] ?? ''));
                return $name !== '' ? $name : 'Reviewer';
            }
        }
        if ((string) ($record['reviewer_id'] ?? '') === $reviewerId) {
            return (string) ($record['reviewer_name'] ?? 'Reviewer');
        }
        return 'Reviewer';
    }

    private function submissionFiles(int $submissionId): array
    {
        $files = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
            ->getMany();

        $result = [];
        foreach ($files as $file) {
            $storedFile = app()->get('file')->get((int) $file->getData('fileId'));
            $fileSize = 0;
            if ($storedFile && app()->get('file')->fs->has($storedFile->path)) {
                try {
                    $fileSize = (int) app()->get('file')->fs->fileSize($storedFile->path);
                } catch (\Throwable) {
                    $fileSize = 0;
                }
            }
            $result[] = [
                'id' => (int) $file->getId(),
                'name' => (string) (
                    $file->getLocalizedData('name')
                    ?: $file->getData('name')
                    ?: 'Submission file ' . $file->getId()
                ),
                'mimeType' => (string) ($storedFile->mimetype ?? $file->getData('mimetype') ?? ''),
                'size' => $fileSize,
            ];
        }
        return $result;
    }

    private function localizedValue(mixed $value, string $locale): string
    {
        if (!\is_array($value)) {
            return \trim((string) ($value ?? ''));
        }
        if (isset($value[$locale]) && \is_scalar($value[$locale])) {
            return \trim((string) $value[$locale]);
        }
        foreach ($value as $localizedValue) {
            if (\is_scalar($localizedValue) && \trim((string) $localizedValue) !== '') {
                return \trim((string) $localizedValue);
            }
        }
        return '';
    }

    private function signedFileUrl(
        \PKP\core\PKPRequest $request,
        mixed $context,
        int $submissionId,
        int $submissionFileId
    ): string {
        $secret = (string) ($this->plugin()->getSetting($context->getId(), 'webhookSecret') ?? '');
        $deadlineDays = (int) ($this->plugin()->getSetting($context->getId(), 'reviewDeadline') ?? 30);
        $expires = \time() + (\max(7, $deadlineDays + 7) * 86400);
        $token = \hash_hmac(
            'sha256',
            "{$context->getId()}:{$submissionId}:{$submissionFileId}:{$expires}",
            $secret
        );

        return $request->getRouter()->url(
            $request,
            null,
            'scholar9review',
            'download',
            null,
            [
                'submissionId' => $submissionId,
                'fileId' => $submissionFileId,
                'expires' => $expires,
                'token' => $token,
            ]
        );
    }

    private function publicRecord(?array $record): ?array
    {
        if ($record === null) {
            return null;
        }
        unset($record['webhook_secret'], $record['s9_webhook_id'], $record['reviewer_email']);
        if (\is_array($record['reviewers_cache'] ?? null)) {
            foreach ($record['reviewers_cache'] as &$reviewer) {
                if (\is_array($reviewer)) {
                    unset($reviewer['email'], $reviewer['reviewer_email']);
                }
            }
            unset($reviewer);
        }
        return $record;
    }

    private function isConversationClosed(array $record): bool
    {
        return \in_array(\strtolower((string) ($record['status'] ?? '')), [
            'reviewer_completed',
            'decision_returned',
            'reviewer_declined',
            'cancelled',
        ], true);
    }

    private function isReviewerConversationClosed(array $record, string $reviewerId): bool
    {
        foreach ((array) ($record['reviewers_cache'] ?? []) as $reviewer) {
            if (!\is_array($reviewer) || (string) ($reviewer['id'] ?? '') !== $reviewerId) {
                continue;
            }
            return \in_array(\strtolower((string) ($reviewer['status'] ?? '')), [
                'reviewer_completed',
                'decision_returned',
                'reviewer_declined',
                'cancelled',
            ], true);
        }
        return $this->isConversationClosed($record);
    }
}
