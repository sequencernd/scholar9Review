<?php

namespace APP\plugins\generic\scholar9Review;

use APP\core\Application;
use APP\facades\Repo;
use PKP\plugins\GenericPlugin;

class Scholar9SyncService
{
    private GenericPlugin $plugin;
    private Scholar9ApiClient $client;
    private Scholar9ReviewDAO $dao;
    private ?string $lastError = null;
    private ?string $lastWarning = null;

    public function __construct(GenericPlugin $plugin, Scholar9ApiClient $client, Scholar9ReviewDAO $dao)
    {
        $this->plugin = $plugin;
        $this->client = $client;
        $this->dao    = $dao;
    }

    // ── Initiate review ─────────────────────────────────────────────────────

    public function createReview(
        int     $journalId,
        int     $submissionId,
        int     $reviewRoundId,
        array   $meta,
        string  $manuscriptUrl,
        string  $submissionIdStr,
        ?string $plagiarismUrl = null,
        array   $supplementaryUrls = [],
        array   $reviewerIds = [],
        array   $parameterIds = [],
    ): ?int {
        $this->lastError = null;
        $this->lastWarning = null;
        $payload = [
            'paper_id'            => $submissionIdStr,
            'paper_title'         => $meta['title']    ?? '',
            'author_name'         => $meta['author']   ?? '',
            'abstract'            => $meta['abstract'] ?? '',
            'keywords'            => $meta['keywords'] ?? '',
            'language'            => $meta['language'] ?? 'en',
        ];

        if ($manuscriptUrl !== '') {
            $payload['manuscript_file_url'] = $manuscriptUrl;
        }

        $reviewType = (string) ($this->plugin->getSetting($journalId, 'defaultReviewType') ?? '');
        if ($reviewType !== '') {
            $payload['review_type'] = $reviewType;
        }

        if ($plagiarismUrl !== null) {
            $payload['plagiarism_file_url'] = $plagiarismUrl;
        }
        if ($supplementaryUrls) {
            $payload['supplementary_file_urls'] = \array_values($supplementaryUrls);
        }
        $defaultParams = $parameterIds ?: $this->plugin->getSetting($journalId, 'defaultReviewParams');
        if ($defaultParams) {
            $payload['review_parameters'] = \is_string($defaultParams)
                ? \explode(',', $defaultParams)
                : \array_values((array) $defaultParams);
        }

        $deadline = (int) ($this->plugin->getSetting($journalId, 'reviewDeadline') ?? 0);
        if ($deadline > 0) {
            $payload['deadline'] = \date('Y-m-d', \strtotime("+{$deadline} days"));
        }

        if (!$reviewerIds) {
            $this->lastError = 'Select at least one reviewer';
            \error_log('[Scholar9] createReview requires at least one reviewer.');
            return null;
        }

        // Scholar9's assign endpoint accepts one reviewer_id per request.
        // Create the paper/review with the first selection, then attach the
        // remaining reviewers to the same paper identifier.
        $result = $this->client->createReview(\array_merge($payload, [
            'reviewer_id' => (string) $reviewerIds[0],
        ]));

        if (!$result || empty($result['success'])) {
            $this->lastError = $this->client->getLastError() ?: 'Scholar9 did not create the review';
            \error_log('[Scholar9] createReview failed: ' . \json_encode($result));
            return null;
        }

        $failedAssignments = 0;
        foreach (\array_slice($reviewerIds, 1) as $reviewerId) {
            $additionalResult = $this->client->createReview(\array_merge($payload, [
                'reviewer_id' => (string) $reviewerId,
            ]));
            if (!$additionalResult || empty($additionalResult['success'])) {
                $failedAssignments++;
                \error_log('[Scholar9] Additional reviewer assignment failed for reviewer ' . $reviewerId);
            }
        }
        if ($failedAssignments > 0) {
            $this->lastWarning = $failedAssignments . ' additional reviewer assignment'
                . ($failedAssignments === 1 ? ' was' : 's were')
                . ' not accepted by Scholar9. The review remains active for the successfully assigned reviewers.';
        }

        $webhookSecret = (string) ($this->plugin->getSetting($journalId, 'webhookSecret') ?? '');
        $s9PaperId     = $result['data']['paper_id']   ?? $submissionIdStr;
        $s9ReviewUrl   = $result['data']['review_url'] ?? null;

        $wh = $webhookSecret !== '' ? $this->client->registerWebhook(
            $this->buildWebhookUrl(),
            ['review.assigned', 'review.status_updated', 'review.completed', 'message.new'],
            $webhookSecret
        ) : null;

        return $this->dao->insert([
            'journal_id'      => $journalId,
            'submission_id'   => $submissionId,
            'review_round_id' => $reviewRoundId,
            's9_paper_id'     => $s9PaperId,
            's9_review_url'   => $s9ReviewUrl,
            'status'          => 'pending_assignment',
            'webhook_secret'  => $webhookSecret,
            's9_webhook_id'   => $wh['data']['id'] ?? null,
        ]);
    }

    // ── Pull sync ───────────────────────────────────────────────────────────

    public function pullSync(array $record, bool $force = true): bool
    {
        $this->lastError = null;
        if (empty($record['s9_paper_id'])) {
            $this->lastError = 'The Scholar9 paper identifier is missing';
            return false;
        }

        // An editor-requested sync must bypass short-lived read caches. Normal
        // workspace loads use the cached snapshot to protect the remote rate
        // limit, while this explicit action always asks Scholar9 for new data.
        $status = $this->client->getReviewStatus((string) $record['s9_paper_id'], $force);
        if (!$status || empty($status['success'])) {
            // Stop here instead of also firing the messages/ratings requests:
            // once Scholar9 has rejected (or rate-limited) one call in this
            // sync, sending two more only makes the same account's limit
            // recover slower.
            $this->lastError = $this->client->getLastError() ?: 'Scholar9 status request failed';
            \error_log('[Scholar9] pullSync failed for paper ' . $record['s9_paper_id']);
            return false;
        }
        $messages = $this->client->getMessages((string) $record['s9_paper_id'], $force);
        $ratings  = $this->client->getRatings((string) $record['s9_paper_id'], $force);

        $assignments = $status['data'] ?? [];
        if (!\is_array($assignments)) {
            $assignments = [];
        }
        if (isset($assignments['status']) || isset($assignments['reviewer'])) {
            $assignments = [$assignments];
        }
        $assignments = \array_values(\array_filter($assignments, 'is_array'));
        if (!$assignments) {
            $this->lastError = 'Scholar9 returned no reviewer assignments for this paper';
            \error_log('[Scholar9] Empty status response for paper ' . $record['s9_paper_id']);
            return false;
        }
        $s9 = $assignments[0] ?? [];
        $existingReviewersById = [];
        foreach ((array) ($record['reviewers_cache'] ?? []) as $existingReviewer) {
            if (!\is_array($existingReviewer) || empty($existingReviewer['id'])) {
                continue;
            }
            $existingReviewersById[(string) $existingReviewer['id']] = $existingReviewer;
        }
        $reviewers = [];
        foreach ($assignments as $assignment) {
            $reviewer = $assignment['reviewer'] ?? [];
            if (!\is_array($reviewer) || empty($reviewer['id'])) {
                continue;
            }
            $reviewerId = (string) $reviewer['id'];
            $existingReviewer = $existingReviewersById[$reviewerId] ?? [];
            $reviewer = $this->currentReviewerProfile($reviewer, $existingReviewer, $force);
            $photoUrl = $this->safeImageUrl((string) ($reviewer['photo_url'] ?? ''));
            $effectiveStatus = $this->effectiveAssignmentStatus(
                $assignment,
                (string) ($existingReviewer['status'] ?? '')
            );
            $reviewers[] = [
                'id' => $reviewerId,
                'name' => (string) ($reviewer['name'] ?? $existingReviewer['name'] ?? ''),
                'email' => (string) ($reviewer['email'] ?? $existingReviewer['email'] ?? ''),
                'photo_url' => $photoUrl,
                'slug' => (string) ($reviewer['slug'] ?? $reviewer['profile_slug'] ?? $existingReviewer['slug'] ?? ''),
                'open_review' => (bool) ($existingReviewer['open_review'] ?? false),
                'designation' => (string) ($reviewer['designation'] ?? $existingReviewer['designation'] ?? ''),
                'status' => $effectiveStatus,
                'assignment_id' => (string) ($assignment['id'] ?? ''),
            ];
        }

        $normalizedMessages = [];
        $hasCanonicalMessageSource = false;
        $seenMessages = [];
        foreach ($assignments as $assignment) {
            if (\array_key_exists('messages', $assignment) && \is_array($assignment['messages'])) {
                $hasCanonicalMessageSource = true;
            }
            $assignmentReviewer = \is_array($assignment['reviewer'] ?? null)
                ? $assignment['reviewer']
                : [];
            $assignmentReviewerId = (string) (
                $assignmentReviewer['id']
                ?? $assignmentReviewer['_id']
                ?? $assignmentReviewer['reviewer_id']
                ?? $assignmentReviewer['reviewer_details_id']
                ?? $assignmentReviewer['reviewerDetailsId']
                ?? ''
            );
            foreach ((array) ($assignment['messages'] ?? []) as $message) {
                if (!\is_array($message)) {
                    continue;
                }
                $text = $this->remoteMessageText($message);
                $attachment = $this->extractAttachment($message, $text);
                if ($text === '' && empty($message['comment_status']) && $attachment === null) {
                    continue;
                }
                $messageId = (string) ($message['id'] ?? '');
                $dedupeKey = $messageId !== ''
                    ? $messageId
                    : \sha1($assignmentReviewerId . '|' . $text . '|'
                        . (string) ($message['comment_date'] ?? '') . '|'
                        . (string) ($attachment['name'] ?? '') . '|'
                        . (string) ($attachment['url'] ?? ''));
                if (isset($seenMessages[$dedupeKey])) {
                    continue;
                }
                $seenMessages[$dedupeKey] = true;
                $normalizedMessages[] = [
                    'id' => $messageId,
                    'reviewer_id' => $assignmentReviewerId,
                    'reviewer_name' => (string) ($assignmentReviewer['name'] ?? ''),
                    'text' => $text,
                    'status' => $message['comment_status'] ?? null,
                    'comment_status' => $message['comment_status'] ?? null,
                    'from' => !empty($message['is_from_s9']) ? 'reviewer' : 'editor',
                    'sender_name' => $this->sanitizeMessageText((string) ($message['sender_name'] ?? '')),
                    'sender_role' => $this->normalizeSenderRole(
                        (string) ($message['sender_role'] ?? ''),
                        !empty($message['is_from_s9']) ? 'Reviewer' : 'Publisher'
                    ),
                    'created_at' => $message['comment_date'] ?? $message['created_at'] ?? null,
                    'rating_data' => $this->normalizeRatingData($message['rating_data'] ?? null),
                    'attachment_name' => $attachment['name'] ?? null,
                    'attachment_url' => $attachment['url'] ?? null,
                ];
            }
        }
        if (!$normalizedMessages && \is_array($messages) && \is_array($messages['data'] ?? null)) {
            $hasCanonicalMessageSource = true;
            foreach ((array) ($messages['data'] ?? []) as $message) {
                if (!\is_array($message)) {
                    continue;
                }
                $text = $this->remoteMessageText($message);
                $attachment = $this->extractAttachment($message, $text);
                if ($text === '' && empty($message['comment_status']) && $attachment === null) {
                    continue;
                }
                $messageReviewer = \is_array($message['reviewer'] ?? null) ? $message['reviewer'] : [];
                $messageReviewerId = (string) (
                    $message['reviewer_id']
                    ?? $message['reviewerId']
                    ?? $message['reviewer_details_id']
                    ?? $message['reviewerDetailsId']
                    ?? $messageReviewer['id']
                    ?? $messageReviewer['_id']
                    ?? $messageReviewer['reviewer_id']
                    ?? $messageReviewer['reviewer_details_id']
                    ?? ''
                );
                $normalizedMessages[] = [
                    'id' => (string) ($message['id'] ?? ''),
                    'reviewer_id' => $messageReviewerId,
                    'reviewer_name' => (string) ($message['reviewer_name'] ?? ''),
                    'text' => $text,
                    'status' => $message['comment_status'] ?? null,
                    'comment_status' => $message['comment_status'] ?? null,
                    'from' => !empty($message['is_from_s9']) ? 'reviewer' : 'editor',
                    'sender_name' => $this->sanitizeMessageText((string) ($message['sender_name'] ?? '')),
                    'sender_role' => $this->normalizeSenderRole(
                        (string) ($message['sender_role'] ?? ''),
                        !empty($message['is_from_s9']) ? 'Reviewer' : 'Publisher'
                    ),
                    'created_at' => $message['comment_date'] ?? $message['created_at'] ?? null,
                    'rating_data' => $this->normalizeRatingData($message['rating_data'] ?? null),
                    'attachment_name' => $attachment['name'] ?? null,
                    'attachment_url' => $attachment['url'] ?? null,
                ];
            }
        }

        $firstReviewer = $reviewers[0] ?? [];
        $aggregateStatus = $this->aggregateStatus($reviewers);
        if ($this->isTerminalStatus((string) ($record['status'] ?? ''))
            && !$this->isTerminalStatus($aggregateStatus)) {
            $aggregateStatus = $this->mapStatus((string) $record['status']);
        }
        $messageOutcome = $this->reviewOutcomeFromAssignments($assignments);
        $recommendation = $this->sanitizeMessageText((string) ($s9['recommendation'] ?? ''));
        $reviewerComment = $this->sanitizeMessageText((string) ($s9['reviewer_comment'] ?? ''));
        $aiSummary = $this->sanitizeMessageText((string) ($s9['ai_summary'] ?? ''));
        $score = $this->safeScore($s9['score'] ?? null);
        if ($recommendation === '' && $messageOutcome !== null) {
            $recommendation = $messageOutcome['recommendation'];
        }
        if ($score === null && $messageOutcome !== null) {
            $score = $messageOutcome['score'];
        }

        $updates = [
            'status'           => $aggregateStatus,
            'recommendation'   => $recommendation !== '' ? $recommendation : ($record['recommendation'] ?? null),
            'score'            => $score ?? ($record['score'] ?? null),
            'reviewer_id'      => $firstReviewer['id']     ?? ($record['reviewer_id'] ?? null),
            'reviewer_name'    => $firstReviewer['name']   ?? ($record['reviewer_name'] ?? null),
            'reviewer_email'   => $firstReviewer['email']  ?? ($record['reviewer_email'] ?? null),
            'reviewers_cache'  => $reviewers ?: ($record['reviewers_cache'] ?? []),
            'reviewer_comment' => $reviewerComment !== '' ? $reviewerComment : ($record['reviewer_comment'] ?? null),
            'ai_summary'       => $aiSummary !== '' ? $aiSummary : ($record['ai_summary'] ?? null),
            'ratings_cache'    => $ratings['data']        ?? ($record['ratings_cache']  ?? []),
            'last_synced_at'   => \date('Y-m-d H:i:s'),
        ];
        if ($hasCanonicalMessageSource) {
            $updates['messages_cache'] = $normalizedMessages;
        }
        $this->dao->update((int) $record['id'], $updates);

        return true;
    }

    /** Keep only valid, display-safe star values supplied by Scholar9. */
    private function normalizeRatingData(mixed $ratings): array
    {
        if (!\is_array($ratings)) {
            return [];
        }
        $normalized = [];
        foreach (\array_values($ratings) as $rating) {
            if (!\is_numeric($rating)) {
                continue;
            }
            $value = (int) $rating;
            if ($value >= 1 && $value <= 5) {
                $normalized[] = $value;
            }
        }
        return \array_slice($normalized, 0, 10);
    }

    /**
     * Refresh the lightweight assignment snapshot used to open the workspace.
     * This single status request keeps reviewer identity and lifecycle state
     * current without downloading the full conversation and ratings payloads.
     */
    public function refreshReviewSnapshot(array $record, bool $force = false): bool
    {
        $paperId = \trim((string) ($record['s9_paper_id'] ?? ''));
        $reviewers = \is_array($record['reviewers_cache'] ?? null)
            ? \array_values(\array_filter($record['reviewers_cache'], 'is_array'))
            : [];

        if (!$reviewers && !empty($record['reviewer_id'])) {
            $reviewers[] = [
                'id' => (string) $record['reviewer_id'],
                'name' => (string) ($record['reviewer_name'] ?? ''),
                'email' => (string) ($record['reviewer_email'] ?? ''),
                'status' => (string) ($record['status'] ?? 'pending'),
            ];
        }

        if ($paperId === '' || empty($record['id'])) {
            return false;
        }

        $response = $this->client->getReviewStatus($paperId);
        if (!$response || empty($response['success'])) {
            return false;
        }
        $assignments = $response['data'] ?? [];
        if (!\is_array($assignments)) {
            return false;
        }
        if (isset($assignments['reviewer'])) {
            $assignments = [$assignments];
        }

        $existingById = [];
        foreach ($reviewers as $reviewer) {
            $reviewerId = (string) ($reviewer['id'] ?? '');
            if ($reviewerId !== '') {
                $existingById[$reviewerId] = $reviewer;
            }
        }

        $remoteReviewers = [];
        foreach ($assignments as $assignment) {
            $remoteReviewer = \is_array($assignment['reviewer'] ?? null)
                ? $assignment['reviewer']
                : [];
            $reviewerId = (string) ($remoteReviewer['id'] ?? '');
            if ($reviewerId === '') {
                continue;
            }
            $existing = $existingById[$reviewerId] ?? [];
            $remoteReviewer = $this->currentReviewerProfile($remoteReviewer, $existing, $force);
            $photoUrl = $this->safeImageUrl((string) ($remoteReviewer['photo_url'] ?? ''));
            $effectiveStatus = $this->effectiveAssignmentStatus(
                $assignment,
                (string) ($existing['status'] ?? '')
            );
            $remoteReviewers[] = [
                'id' => $reviewerId,
                'name' => (string) ($remoteReviewer['name'] ?? $existing['name'] ?? ''),
                'email' => (string) ($remoteReviewer['email'] ?? $existing['email'] ?? ''),
                'photo_url' => $photoUrl,
                'slug' => (string) ($remoteReviewer['slug'] ?? $remoteReviewer['profile_slug'] ?? $existing['slug'] ?? ''),
                'open_review' => (bool) ($existing['open_review'] ?? false),
                'designation' => (string) ($remoteReviewer['designation'] ?? $existing['designation'] ?? ''),
                'status' => $effectiveStatus,
                'assignment_id' => (string) ($assignment['id'] ?? $existing['assignment_id'] ?? ''),
            ];
        }

        if (!$remoteReviewers) {
            return false;
        }

        $summary = $assignments[0] ?? [];
        foreach ($assignments as $assignment) {
            if ($this->effectiveAssignmentStatus($assignment) === 'reviewer_completed') {
                $summary = $assignment;
                break;
            }
        }

        $aggregateStatus = $this->aggregateStatus($remoteReviewers);
        if ($this->isTerminalStatus((string) ($record['status'] ?? ''))
            && !$this->isTerminalStatus($aggregateStatus)) {
            $aggregateStatus = $this->mapStatus((string) $record['status']);
        }
        $messageOutcome = $this->reviewOutcomeFromAssignments($assignments);
        $updates = [
            'status' => $aggregateStatus,
            'reviewer_id' => $remoteReviewers[0]['id'],
            'reviewer_name' => $remoteReviewers[0]['name'],
            'reviewer_email' => $remoteReviewers[0]['email'],
            'reviewers_cache' => $remoteReviewers,
        ];
        $summaryRecommendation = $this->sanitizeMessageText((string) ($summary['recommendation'] ?? ''));
        $summaryComment = $this->sanitizeMessageText((string) ($summary['reviewer_comment'] ?? ''));
        $summaryScore = $this->safeScore($summary['score'] ?? null);
        if ($summaryRecommendation === '' && $messageOutcome !== null) {
            $summaryRecommendation = $messageOutcome['recommendation'];
        }
        if ($summaryScore === null && $messageOutcome !== null) {
            $summaryScore = $messageOutcome['score'];
        }
        if ($summaryRecommendation !== '') {
            $updates['recommendation'] = $summaryRecommendation;
        }
        if ($summaryComment !== '') {
            $updates['reviewer_comment'] = $summaryComment;
        }
        if ($summaryScore !== null) {
            $updates['score'] = $summaryScore;
        }

        $changed = false;
        foreach ($updates as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return false;
        }
        $updates['last_synced_at'] = \date('Y-m-d H:i:s');
        $this->dao->update((int) $record['id'], $updates);
        return true;
    }

    /** @deprecated Use refreshReviewSnapshot(). */
    public function hydrateReviewerPhotos(array $record): bool
    {
        return $this->refreshReviewSnapshot($record, true);
    }

    /**
     * Merge the assignment snapshot with Scholar9's existing reviewer-detail
     * and public-profile APIs. The assignment endpoint intentionally returns a
     * small reviewer object, so profile photos are resolved by stable user id.
     *
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function currentReviewerProfile(array $remote, array $existing, bool $force = false): array
    {
        $reviewerId = \trim((string) ($remote['id'] ?? $existing['id'] ?? ''));
        $detail = [];
        // Some older Scholar9 assignments contain a legacy reviewer id that
        // is not accepted by the reviewer-detail endpoint. Resolve the current
        // public profile through the existing authenticated search endpoint.
        // Identity is matched by stable id/email first; an exact display-name
        // match is used only when it is unique, so a different reviewer's photo
        // can never be selected from a fuzzy result set.
        if ($this->reviewerPhotoUrl($remote) === null) {
            $expected = $remote;
            foreach (['id', '_id', 'name', 'email'] as $identityKey) {
                if (\trim((string) ($expected[$identityKey] ?? '')) === ''
                    && \trim((string) ($existing[$identityKey] ?? '')) !== '') {
                    $expected[$identityKey] = $existing[$identityKey];
                }
            }
            $searched = $this->searchedReviewerProfile($expected, $force);
            if ($searched !== []) {
                $detail = $searched;
            }
        }

        // Fall back to Scholar9's existing reviewer-detail endpoint only when
        // the assignment and exact search match did not expose a photo. This
        // avoids repeated 404s for historical assignment identifiers while
        // preserving compatibility for other Scholar9 deployments.
        if ($reviewerId !== ''
            && $this->reviewerPhotoUrl($detail) === null
            && $this->reviewerPhotoUrl($remote) === null) {
            try {
                $response = $this->client->getReviewer($reviewerId, $force);
                $detail = \is_array($response['data'] ?? null) ? $response['data'] + $detail : $detail;
            } catch (\Throwable) {
                // Optional profile enrichment must never block review sync.
            }
        }

        $merged = $detail + $remote + $existing;
        $photoUrl = $this->reviewerPhotoUrl($detail)
            ?? $this->reviewerPhotoUrl($remote)
            ?? $this->reviewerPhotoUrl($existing);
        $merged['photo_url'] = $photoUrl;
        return $merged;
    }

    /** @return array<string, mixed> */
    private function searchedReviewerProfile(array $expected, bool $force = false): array
    {
        $name = \trim((string) ($expected['name'] ?? ''));
        if (\mb_strlen($name) < 2) {
            return [];
        }

        try {
            $response = $this->client->searchReviewers($name, 'all', $force);
        } catch (\Throwable) {
            return [];
        }
        $candidates = \is_array($response['data'] ?? null)
            ? \array_values(\array_filter($response['data'], 'is_array'))
            : [];
        if (!$candidates) {
            return [];
        }

        $expectedId = \trim((string) ($expected['id'] ?? $expected['_id'] ?? ''));
        $expectedEmail = \strtolower(\trim((string) ($expected['email'] ?? '')));
        $expectedName = \mb_strtolower($name);
        foreach ($candidates as $candidate) {
            $candidateId = \trim((string) ($candidate['id'] ?? $candidate['_id'] ?? ''));
            if ($expectedId !== '' && $candidateId !== '' && \hash_equals($expectedId, $candidateId)) {
                return $candidate;
            }
        }
        if ($expectedEmail !== '') {
            foreach ($candidates as $candidate) {
                $candidateEmail = \strtolower(\trim((string) ($candidate['email'] ?? '')));
                if ($candidateEmail !== '' && \hash_equals($expectedEmail, $candidateEmail)) {
                    return $candidate;
                }
            }
        }

        $exactNames = \array_values(\array_filter(
            $candidates,
            static fn(array $candidate): bool => \mb_strtolower(\trim((string) ($candidate['name'] ?? '')))
                === $expectedName
        ));
        return \count($exactNames) === 1 ? $exactNames[0] : [];
    }

    private function reviewerPhotoUrl(array $reviewer): ?string
    {
        foreach (['photo_url', 'profile_photo_url', 'profile_photo', 'profile_image', 'avatar_url', 'avatar', 'photo', 'image'] as $key) {
            $url = $this->safeImageUrl((string) ($reviewer[$key] ?? ''));
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private function safeImageUrl(string $value): ?string
    {
        $value = \trim($value);
        $scheme = \strtolower((string) \parse_url($value, PHP_URL_SCHEME));
        if ($value === '' || !\filter_var($value, FILTER_VALIDATE_URL)
            || !\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $value;
    }

    private function safeScore(mixed $value): ?float
    {
        if (!\is_numeric($value)) {
            return null;
        }
        $score = (float) $value;
        return $score >= 0 && $score <= 5 ? \round($score, 2) : null;
    }

    private function safeEmail(string $value): string
    {
        $value = \mb_substr(\trim($value), 0, 255);
        return \filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** Seconds Scholar9 asked the caller to wait, if the last remote call was rate-limited. */
    public function getRetryAfterSeconds(): ?int
    {
        return $this->client->getRetryAfterSeconds();
    }

    public function getLastWarning(): ?string
    {
        return $this->lastWarning;
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

    private function remoteMessageText(array $message): string
    {
        foreach (['comment_text', 'text', 'message', 'body', 'content', 'status_text', 'status_title', 'title', 'label'] as $key) {
            $text = $this->sanitizeMessageText((string) ($message[$key] ?? ''));
            if ($text !== '') {
                return \mb_substr($text, 0, Scholar9ApiClient::MAX_MESSAGE_LENGTH);
            }
        }

        $status = $this->sanitizeMessageText((string) (
            $message['comment_status']
            ?? $message['event']
            ?? $message['action']
            ?? $message['status']
            ?? ''
        ));
        if ($status === '') {
            return '';
        }

        $normalized = \strtolower(\str_replace([' ', '-'], '_', $status));
        $eventText = match ($normalized) {
            '1', 'accepted', 'accept', 'review_accepted', 'request_accepted',
            'review_request_accepted' => 'Review Request Accepted',
            '2' => 'Revision Requested',
            'cancelled', 'canceled', 'request_cancelled',
            'request_canceled' => 'Review Request Cancelled',
            '3' => 'Review Completed',
            '4', 'rejected', 'request_rejected' => 'Review Request Rejected',
            'major_revision' => 'Major Revision Requested',
            'minor_revision' => 'Minor Revision Requested',
            'revision_required', 'revision_requested' => 'Revision Requested',
            'reviewer_completed', 'review_completed', 'completed' => 'Review Completed',
            'reviewer_declined', 'request_declined', 'declined' => 'Review Request Declined',
            default => \ctype_digit($normalized)
                ? 'Review Status Updated'
                : \ucwords(\str_replace('_', ' ', $normalized)),
        };
        return \mb_substr($eventText, 0, Scholar9ApiClient::MAX_MESSAGE_LENGTH);
    }

    private function extractAttachment(array $message, string $text): ?array
    {
        $nested = \is_array($message['attachment'] ?? null) ? $message['attachment'] : [];
        $name = \trim((string) ($message['attachment_name'] ?? $message['file_name'] ?? $nested['name'] ?? ''));
        $url = \trim((string) ($message['attachment_url'] ?? $message['file_url'] ?? $nested['url'] ?? ''));

        $rawText = (string) ($message['comment_text'] ?? $message['text'] ?? '');
        if (\stripos($rawText, '<a') !== false && \preg_match(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            $rawText,
            $htmlMatch
        )) {
            if ($url === '') {
                $url = \html_entity_decode((string) $htmlMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($name === '') {
                $name = $this->sanitizeMessageText((string) $htmlMatch[3]);
            }
        }
        if ($name === '' && \preg_match('/^(?:shared file|file shared):\s*([^\r\n]+)(?:\r?\n(https?:\/\/\S+))?/i', $text, $matches)) {
            $name = \trim($matches[1]);
            $url = \trim((string) ($matches[2] ?? $url));
        }
        if ($name === '') {
            return null;
        }
        $name = $this->sanitizeMessageText($name);
        $name = \basename(\str_replace('\\', '/', $name));
        $name = \mb_substr($name, 0, 255);
        if ($name === '') {
            return null;
        }
        $url = $this->normalizeAttachmentUrl($url) ?? '';
        return ['name' => $name, 'url' => $url !== '' ? $url : null];
    }

    /**
     * Accept a remote attachment URL without trusting its markup. Scholar9's
     * current file messages can contain literal spaces in the uploaded file
     * path. Encode each path segment before validation while continuing to
     * reject executable schemes, credentials, malformed hosts and controls.
     */
    private function normalizeAttachmentUrl(string $value): ?string
    {
        $value = \trim(\html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || \preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            return null;
        }

        $parts = \parse_url($value);
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = \strtolower((string) ($parts['scheme'] ?? ''));
        $host = \trim((string) ($parts['host'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $encodedPath = \implode('/', \array_map(
            static fn(string $segment): string => \rawurlencode(\rawurldecode($segment)),
            \explode('/', $path)
        ));
        $encodedHost = \str_contains($host, ':') ? '[' . $host . ']' : $host;
        $normalized = $scheme . '://' . $encodedHost;
        if (isset($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $encodedPath;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . $this->encodeUrlWhitespace((string) $parts['query']);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $normalized .= '#' . $this->encodeUrlWhitespace((string) $parts['fragment']);
        }

        return \filter_var($normalized, FILTER_VALIDATE_URL) ? $normalized : null;
    }

    private function encodeUrlWhitespace(string $value): string
    {
        return (string) \preg_replace_callback(
            '/[^\x21-\x7E]/u',
            static fn(array $match): string => \rawurlencode($match[0]),
            $value
        );
    }

    // ── Webhook processing ──────────────────────────────────────────────────

    public function handleWebhook(
        array $payload,
        string $rawBody,
        string $signature,
        ?int $journalId = null
    ): bool
    {
        $eventPayload = $payload;
        if (isset($payload['data']) && \is_array($payload['data'])) {
            $eventPayload = \array_merge($payload, $payload['data']);
        }

        $paper = \is_array($eventPayload['paper'] ?? null) ? $eventPayload['paper'] : [];
        $paperId = (string) (
            $eventPayload['paper_id']
            ?? $eventPayload['paperId']
            ?? $paper['id']
            ?? ''
        );
        if ($paperId === '') {
            return false;
        }

        $record = $this->dao->getByS9PaperId($paperId, $journalId);
        if (!$record) {
            return false;
        }

        if (!$this->client->verifyWebhookSignature($rawBody, $signature, (string) ($record['webhook_secret'] ?? ''))) {
            \error_log('[Scholar9] Webhook signature mismatch for paper ' . $paperId);
            return false;
        }

        $event = (string) ($payload['event'] ?? $payload['type'] ?? $eventPayload['event'] ?? '');
        $reviewer = \is_array($eventPayload['reviewer'] ?? null) ? $eventPayload['reviewer'] : [];

        match ($event) {
            'review.assigned' => $this->applyReviewAssignedWebhook($record, $eventPayload),
            'review.status_updated' => $this->applyStatusWebhook($record, $eventPayload),
            'review.completed' => $this->applyReviewCompletedWebhook($record, $eventPayload),
            'message.new', 'message.received' => $this->appendWebhookMessage($record, $eventPayload),
            'ai.analysis_ready' => $this->dao->update((int) $record['id'], [
                'ai_summary' => $this->sanitizeMessageText((string) ($eventPayload['ai_summary'] ?? '')),
                'status'     => $this->isTerminalStatus((string) ($record['status'] ?? ''))
                    ? (string) $record['status']
                    : 'ai_analysis',
            ]),
            default => false,
        };

        $handled = \in_array($event, [
            'review.assigned',
            'review.status_updated',
            'review.completed',
            'message.new',
            'message.received',
            'ai.analysis_ready',
        ], true);
        if ($handled) {
            // The webhook already supplied newer state. Remove any response
            // snapshot so the next passive read cannot restore older data.
            $this->client->forgetReviewCaches($paperId);
        }
        return $handled;
    }

    /**
     * Apply an assignment event without allowing a delayed delivery to reopen
     * a completed review. New rounds are stored in their own local record, so
     * an assignment for this record is always stale once it is terminal.
     */
    private function applyReviewAssignedWebhook(array $record, array $payload): void
    {
        if ($this->isTerminalStatus((string) ($record['status'] ?? ''))) {
            return;
        }

        $reviewer = \is_array($payload['reviewer'] ?? null) ? $payload['reviewer'] : [];
        $reviewerId = (string) (
            $payload['reviewer_id']
            ?? $payload['reviewerId']
            ?? $payload['reviewer_details_id']
            ?? $payload['reviewerDetailsId']
            ?? $reviewer['id']
            ?? $reviewer['_id']
            ?? $reviewer['reviewer_id']
            ?? $reviewer['reviewer_details_id']
            ?? ''
        );
        $this->dao->update((int) $record['id'], [
            'status'         => 'awaiting_reviewer_response',
            'reviewer_id'    => $reviewerId !== '' ? $reviewerId : $record['reviewer_id'],
            'reviewer_name'  => $this->sanitizeMessageText((string) (
                $payload['reviewer_name'] ?? $reviewer['name'] ?? $record['reviewer_name']
            )),
            'reviewer_email' => $this->safeEmail((string) (
                $payload['reviewer_email'] ?? $reviewer['email'] ?? $record['reviewer_email']
            )),
        ]);
    }

    private function appendWebhookMessage(array $record, array $payload): void
    {
        $message = $this->normalizeWebhookMessage($payload);
        if ($message === null) {
            return;
        }

        $messages = \array_values(\array_filter((array) ($record['messages_cache'] ?? []), 'is_array'));
        $messageId = (string) ($message['id'] ?? '');
        $fingerprint = $this->messageFingerprint($message);
        foreach ($messages as $existing) {
            $existingId = (string) ($existing['id'] ?? '');
            if (($messageId !== '' && $existingId === $messageId)
                || ($messageId === '' && $this->messageFingerprint($existing) === $fingerprint)) {
                return;
            }
        }

        $messages[] = $message;
        $this->dao->update((int) $record['id'], [
            'messages_cache' => \array_slice($messages, -1000),
        ]);
    }

    /**
     * Apply a status event without allowing delayed delivery to reopen a
     * completed review. Reviewer-scoped events update only that assignment and
     * then derive the paper status from every cached reviewer.
     */
    private function applyStatusWebhook(array $record, array $payload): void
    {
        $currentStatus = (string) ($record['status'] ?? 'pending');
        $incomingStatus = $this->webhookStatus($payload, $currentStatus);
        $reviewer = \is_array($payload['reviewer'] ?? null) ? $payload['reviewer'] : [];
        $reviewerId = \trim((string) ($payload['reviewer_id'] ?? $reviewer['id'] ?? ''));
        $reviewers = \array_values(\array_filter((array) ($record['reviewers_cache'] ?? []), 'is_array'));
        $reviewerChanged = false;

        if ($reviewerId !== '' && $reviewers) {
            foreach ($reviewers as &$cachedReviewer) {
                if ((string) ($cachedReviewer['id'] ?? '') !== $reviewerId) {
                    continue;
                }
                $cachedStatus = (string) ($cachedReviewer['status'] ?? 'pending');
                if (!$this->isTerminalStatus($cachedStatus) || $this->isTerminalStatus($incomingStatus)) {
                    $cachedReviewer['status'] = $incomingStatus;
                    $reviewerChanged = true;
                }
                break;
            }
            unset($cachedReviewer);
        }

        if ($reviewerChanged) {
            $this->dao->update((int) $record['id'], [
                'status' => $this->aggregateStatus($reviewers),
                'reviewers_cache' => $reviewers,
            ]);
            $this->appendStatusWebhookMessage($record, $payload, $reviewerId, $reviewers);
            return;
        }

        if ($this->isTerminalStatus($currentStatus) && !$this->isTerminalStatus($incomingStatus)) {
            return;
        }
        $this->dao->update((int) $record['id'], ['status' => $incomingStatus]);
        $this->appendStatusWebhookMessage($record, $payload, $reviewerId, $reviewers);
    }

    /**
     * Scholar9 status webhooks are deliberately compact and may not include a
     * message body. Preserve the status audit entry in OJS by deriving the same
     * safe label used by pull synchronization. Existing message IDs/fingerprints
     * keep delivery retries idempotent.
     *
     * @param array<int, array<string, mixed>> $reviewers
     */
    private function appendStatusWebhookMessage(
        array $record,
        array $payload,
        string $reviewerId,
        array $reviewers
    ): void {
        $eventStatus = \trim((string) ($payload['comment_status'] ?? $payload['status'] ?? ''));
        if ($eventStatus === '') {
            return;
        }

        $statusMessage = $payload;
        $statusMessage['comment_status'] = $eventStatus;
        $statusMessage['is_from_s9'] = true;
        if ($reviewerId !== '') {
            $statusMessage['reviewer_id'] = $reviewerId;
        }
        foreach ($reviewers as $cachedReviewer) {
            $matches = $reviewerId !== ''
                ? (string) ($cachedReviewer['id'] ?? '') === $reviewerId
                : \count($reviewers) === 1;
            if (!$matches) {
                continue;
            }
            $statusMessage['reviewer_name'] = $statusMessage['reviewer_name']
                ?? $cachedReviewer['name']
                ?? '';
            break;
        }
        $this->appendWebhookMessage($record, $statusMessage);
    }

    /**
     * Mark the reviewer identified by a completion event as finished. A paper
     * remains active while any other reviewer assignment is still active.
     */
    private function applyReviewCompletedWebhook(array $record, array $payload): void
    {
        $reviewer = \is_array($payload['reviewer'] ?? null) ? $payload['reviewer'] : [];
        $reviewerId = \trim((string) ($payload['reviewer_id'] ?? $reviewer['id'] ?? ''));
        $reviewers = \array_values(\array_filter((array) ($record['reviewers_cache'] ?? []), 'is_array'));
        $reviewerChanged = false;

        foreach ($reviewers as &$cachedReviewer) {
            $matches = $reviewerId !== ''
                ? (string) ($cachedReviewer['id'] ?? '') === $reviewerId
                : \count($reviewers) === 1;
            if (!$matches) {
                continue;
            }
            $cachedReviewer['status'] = 'reviewer_completed';
            $reviewerChanged = true;
            break;
        }
        unset($cachedReviewer);

        $updates = [
            'status' => $reviewerChanged ? $this->aggregateStatus($reviewers) : 'reviewer_completed',
            'reviewer_comment' => $this->sanitizeMessageText((string) (
                $payload['reviewer_comment'] ?? $payload['comment_text'] ?? ''
            )),
            'recommendation' => $this->sanitizeMessageText((string) (
                $payload['recommendation'] ?? $payload['review_decision'] ?? ''
            )),
            'score' => $this->safeScore($payload['score'] ?? null),
        ];
        if ($reviewerChanged) {
            $updates['reviewers_cache'] = $reviewers;
        }
        $this->dao->update((int) $record['id'], $updates);
    }

    private function isTerminalStatus(string $status): bool
    {
        return \in_array($this->mapStatus(\strtolower(\trim($status))), [
            'reviewer_completed',
            'decision_returned',
            'reviewer_declined',
            'cancelled',
        ], true);
    }

    private function normalizeWebhookMessage(array $payload): ?array
    {
        if (isset($payload['message']) && \is_array($payload['message'])) {
            $payload = \array_merge($payload, $payload['message']);
        }

        $reviewer = \is_array($payload['reviewer'] ?? null) ? $payload['reviewer'] : [];
        $reviewerId = (string) (
            $payload['reviewer_id']
            ?? $payload['reviewerId']
            ?? $payload['reviewer_details_id']
            ?? $payload['reviewerDetailsId']
            ?? $reviewer['id']
            ?? $reviewer['_id']
            ?? $reviewer['reviewer_id']
            ?? $reviewer['reviewer_details_id']
            ?? ''
        );
        $text = $this->remoteMessageText($payload);
        $status = $this->sanitizeMessageText((string) ($payload['comment_status'] ?? ''));
        $text = \mb_substr($text, 0, Scholar9ApiClient::MAX_MESSAGE_LENGTH);
        $attachment = $this->extractAttachment($payload, $text);
        if ($text === '' && $attachment === null) {
            return null;
        }

        $isFromScholar9 = isset($payload['is_from_s9'])
            ? \filter_var($payload['is_from_s9'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $source = \strtolower((string) ($payload['from'] ?? $payload['source'] ?? ''));
        $from = $isFromScholar9 !== null
            ? ($isFromScholar9 ? 'reviewer' : 'editor')
            : (\in_array($source, ['reviewer', 'scholar9'], true) ? 'reviewer' : 'editor');

        return [
            'id' => (string) ($payload['message_id'] ?? $payload['id'] ?? ''),
            'reviewer_id' => $reviewerId,
            'reviewer_name' => $this->sanitizeMessageText((string) (
                $payload['reviewer_name'] ?? $reviewer['name'] ?? ''
            )),
            'text' => $text,
            'status' => $status !== '' ? $status : null,
            'comment_status' => $status !== '' ? $status : null,
            'from' => $from,
            'sender_name' => $this->sanitizeMessageText((string) ($payload['sender_name'] ?? '')),
            'sender_role' => $this->normalizeSenderRole(
                (string) ($payload['sender_role'] ?? ''),
                $from === 'reviewer' ? 'Reviewer' : 'Publisher'
            ),
            'created_at' => $payload['timestamp'] ?? $payload['comment_date'] ?? $payload['created_at'] ?? \date('Y-m-d H:i:s'),
            'attachment_name' => $attachment['name'] ?? null,
            'attachment_url' => $attachment['url'] ?? null,
        ];
    }

    private function normalizeSenderRole(string $role, string $fallback): string
    {
        $role = \strtolower($this->sanitizeMessageText($role));
        $allowed = [
            'reviewer' => 'Reviewer',
            'publisher' => 'Publisher',
            'author' => 'Author',
            'journal manager' => 'Journal Manager',
            'journal_manager' => 'Journal Manager',
            'editor' => 'Editor',
        ];

        return $allowed[$role] ?? $fallback;
    }

    private function messageFingerprint(array $message): string
    {
        return \sha1(\implode('|', [
            (string) ($message['reviewer_id'] ?? ''),
            (string) ($message['from'] ?? ''),
            (string) ($message['text'] ?? ''),
            (string) ($message['created_at'] ?? ''),
            (string) ($message['attachment_name'] ?? ''),
            (string) ($message['attachment_url'] ?? ''),
        ]));
    }

    private function webhookStatus(array $payload, string $fallback): string
    {
        $rawStatus = \strtolower(\trim((string) (
            $payload['status']
            ?? $payload['comment_status']
            ?? $payload['review_decision']
            ?? ''
        )));
        if ($rawStatus === '') {
            return $fallback;
        }

        $mapped = $this->mapStatus($rawStatus);
        return $mapped === 'pending' && $rawStatus !== 'pending' ? $fallback : $mapped;
    }

    // ── OJS reviewer → Scholar9 push ────────────────────────────────────────

    public function pushOjsReviewer(array $record, mixed $reviewAssignment): void
    {
        $user = Repo::user()->get($reviewAssignment->getReviewerId());
        if (!$user) {
            return;
        }

        $this->client->createReview([
            'paper_id'    => $record['s9_paper_id'],
            'reviewer_id' => 'ojs_' . $user->getId(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function mapStatus(string $s9Status): string
    {
        $s9Status = \strtolower(\trim($s9Status));
        return match ($s9Status) {
            '0'                          => 'pending_assignment',
            '1'                          => 'under_review',
            '2'                          => 'cancelled',
            '3'                          => 'reviewer_completed',
            '4'                          => 'reviewer_declined',
            'pending_assignment'         => 'pending_assignment',
            'awaiting_reviewer_response' => 'awaiting_reviewer_response',
            'under_review'               => 'under_review',
            'accepted', 'accept',
            'completed', 'review_completed' => 'reviewer_completed',
            'major_revision', 'minor_revision' => 'revision_required',
            'revision_required'          => 'revision_required',
            'reviewer_completed'         => 'reviewer_completed',
            'ai_analysis'                => 'ai_analysis',
            'admin_deciding', 'recommendation_ready' => 'recommendation_ready',
            'admin_decided', 'decision_returned' => 'decision_returned',
            'reviewer_declined'          => 'reviewer_declined',
            'cancelled'                  => 'cancelled',
            default                      => 'pending',
        };
    }

    private function aggregateStatus(array $assignments): string
    {
        if (!$assignments) {
            return 'pending';
        }
        $statuses = \array_map(
            fn(array $assignment): string => $this->effectiveAssignmentStatus($assignment),
            $assignments
        );
        // Keep a multi-reviewer paper active until no viable reviewer remains
        // in an active state. One completed assignment must not close every
        // other reviewer conversation.
        foreach (['under_review', 'awaiting_reviewer_response', 'pending_assignment'] as $status) {
            if (\in_array($status, $statuses, true)) {
                return $status;
            }
        }
        if (\in_array('reviewer_completed', $statuses, true)) {
            return 'reviewer_completed';
        }
        return $statuses[0] ?? 'pending';
    }

    /**
     * Scholar9 keeps an accepted assignment at status=1 while its final review
     * decision is stored in the conversation. Its own UI treats comment status
     * 4 as Approved and 3 as Not Approved. Reconcile those two existing API
     * signals locally so OJS does not leave a submitted review open.
     */
    private function effectiveAssignmentStatus(array $assignment, string $existingStatus = ''): string
    {
        $mapped = $this->mapStatus((string) ($assignment['status'] ?? ''));
        if ($this->reviewOutcomeFromMessages((array) ($assignment['messages'] ?? [])) !== null) {
            return 'reviewer_completed';
        }

        // A delayed or abbreviated response must never reopen an assignment
        // that OJS has already observed in a terminal state.
        if ($existingStatus !== '' && $this->isTerminalStatus($existingStatus)) {
            return $this->mapStatus($existingStatus);
        }

        return $mapped;
    }

    /** @return array{recommendation: string, score: ?float}|null */
    private function reviewOutcomeFromAssignments(array $assignments): ?array
    {
        $outcome = null;
        foreach ($assignments as $assignment) {
            if (!\is_array($assignment)) {
                continue;
            }
            $candidate = $this->reviewOutcomeFromMessages((array) ($assignment['messages'] ?? []));
            if ($candidate !== null) {
                $outcome = $candidate;
            }
        }
        return $outcome;
    }

    /** @return array{recommendation: string, score: ?float}|null */
    private function reviewOutcomeFromMessages(array $messages): ?array
    {
        $outcome = null;
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $commentStatus = \trim((string) ($message['comment_status'] ?? ''));
            if (!\in_array($commentStatus, ['3', '4'], true)) {
                continue;
            }

            $score = null;
            $rawText = (string) ($message['comment_text'] ?? $message['text'] ?? '');
            if (\preg_match('/Rating:\s*([1-5](?:\s*,\s*[1-5])*)/i', $rawText, $matches)) {
                $values = \array_map('intval', \preg_split('/\s*,\s*/', $matches[1]) ?: []);
                if ($values) {
                    $score = $this->safeScore(\array_sum($values) / \count($values));
                }
            }

            $outcome = [
                'recommendation' => $commentStatus === '4' ? 'approved' : 'not_approved',
                'score' => $score,
            ];
        }
        return $outcome;
    }

    private function buildWebhookUrl(): string
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $path    = $context ? $context->getPath() : '';
        return $request->getBaseUrl() . '/index.php/' . $path . '/scholar9review/webhook';
    }
}
