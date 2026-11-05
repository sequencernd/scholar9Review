<?php

declare(strict_types=1);

if (!defined('PKP_STRICT_MODE')) {
    define('PKP_STRICT_MODE', false);
}

require_once dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/autoload.php';
require_once dirname(__DIR__) . '/classes/Scholar9ApiClient.php';
require_once dirname(__DIR__) . '/classes/Scholar9ReviewDAO.php';
require_once dirname(__DIR__) . '/classes/Scholar9SyncService.php';

use APP\plugins\generic\scholar9Review\Scholar9ApiClient;
use APP\plugins\generic\scholar9Review\Scholar9ReviewDAO;
use APP\plugins\generic\scholar9Review\Scholar9SyncService;

function assertSyncValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true) . PHP_EOL
        );
        exit(1);
    }
}

$reflection = new ReflectionClass(Scholar9SyncService::class);
$service = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeWebhookMessage');
$normalize->setAccessible(true);
$webhookStatus = $reflection->getMethod('webhookStatus');
$webhookStatus->setAccessible(true);
$safeImageUrl = $reflection->getMethod('safeImageUrl');
$safeImageUrl->setAccessible(true);
$safeScore = $reflection->getMethod('safeScore');
$safeScore->setAccessible(true);
$safeEmail = $reflection->getMethod('safeEmail');
$safeEmail->setAccessible(true);
$aggregateStatus = $reflection->getMethod('aggregateStatus');
$aggregateStatus->setAccessible(true);
$effectiveAssignmentStatus = $reflection->getMethod('effectiveAssignmentStatus');
$effectiveAssignmentStatus->setAccessible(true);
$reviewOutcomeFromMessages = $reflection->getMethod('reviewOutcomeFromMessages');
$reviewOutcomeFromMessages->setAccessible(true);
$remoteMessageText = $reflection->getMethod('remoteMessageText');
$remoteMessageText->setAccessible(true);
$mapStatus = $reflection->getMethod('mapStatus');
$mapStatus->setAccessible(true);
$applyStatusWebhook = $reflection->getMethod('applyStatusWebhook');
$applyStatusWebhook->setAccessible(true);
$extractAttachment = $reflection->getMethod('extractAttachment');
$extractAttachment->setAccessible(true);

$message = $normalize->invoke($service, [
    'id' => 'message-17',
    'comment_text' => '<script>alert("x")</script>Reviewer feedback',
    'comment_status' => 'major_revision',
    'is_from_s9' => true,
    'reviewer_id' => 'reviewer-4',
    'reviewer_name' => 'Dr. Reviewer',
    'sender_name' => 'Dr. Reviewer',
    'sender_role' => 'Reviewer',
    'timestamp' => '2026-08-03 12:00:00',
]);

assertSyncValue('message-17', $message['id'] ?? null, 'Scholar9 message IDs must be retained for webhook deduplication.');
assertSyncValue('Reviewer feedback', $message['text'] ?? null, 'message.new comment_text must be sanitized and retained.');
assertSyncValue('reviewer', $message['from'] ?? null, 'Scholar9-authored messages must be attributed to the reviewer.');
assertSyncValue('Dr. Reviewer', $message['sender_name'] ?? null, 'Scholar9 sender names must survive webhook normalization.');
assertSyncValue('Reviewer', $message['sender_role'] ?? null, 'Scholar9 sender designations must survive webhook normalization.');
assertSyncValue('major_revision', $message['status'] ?? null, 'Revision metadata must be retained.');
assertSyncValue('major_revision', $message['comment_status'] ?? null, 'The exact Scholar9 comment status must be retained separately.');

$revisionMessage = $normalize->invoke($service, [
    'id' => 'revision-2',
    'comment_text' => "<p>test 3</p>\nRevision Status: Clarification",
    'comment_status' => '2',
    'is_from_s9' => true,
    'reviewer_details_id' => 'reviewer-details-4',
]);
assertSyncValue('2', $revisionMessage['comment_status'] ?? null, 'Scholar9 revision status 2 must survive webhook normalization exactly.');
assertSyncValue('reviewer-details-4', $revisionMessage['reviewer_id'] ?? null, 'Scholar9 reviewer_details_id must scope the revision to the correct reviewer.');
assertSyncValue(
    'Review Request Accepted',
    $remoteMessageText->invoke($service, ['comment_status' => '1', 'comment_text' => '']),
    'Scholar9 acceptance audit rows must render their default event text when comment_text is empty.'
);
assertSyncValue(
    'Revision Requested',
    $remoteMessageText->invoke($service, ['comment_status' => '2', 'comment_text' => '']),
    'Scholar9 comment status 2 must render as a revision request when comment_text is empty.'
);
assertSyncValue('pending_assignment', $mapStatus->invoke($service, '0'), 'Scholar9 status 0 must mean review requested.');
assertSyncValue('under_review', $mapStatus->invoke($service, '1'), 'Scholar9 status 1 must mean accepted/under review.');
assertSyncValue('cancelled', $mapStatus->invoke($service, '2'), 'Scholar9 status 2 must mean request cancelled.');
assertSyncValue('reviewer_completed', $mapStatus->invoke($service, '3'), 'Scholar9 status 3 must mean review completed.');
assertSyncValue('reviewer_declined', $mapStatus->invoke($service, '4'), 'Scholar9 status 4 must mean review rejected.');
assertSyncValue(
    'Review Request Declined',
    $remoteMessageText->invoke($service, ['comment_status' => 'reviewer_declined']),
    'Named Scholar9 review events must render a safe human-readable label.'
);

$acceptanceDao = new class extends Scholar9ReviewDAO {
    public array $updates = [];

    public function update(int $id, array $data): void
    {
        $this->updates[] = ['id' => $id, 'data' => $data];
    }
};
$daoProperty = $reflection->getProperty('dao');
$daoProperty->setAccessible(true);
$daoProperty->setValue($service, $acceptanceDao);
$applyStatusWebhook->invoke($service, [
    'id' => 9,
    'status' => 'awaiting_reviewer_response',
    'messages_cache' => [],
    'reviewers_cache' => [[
        'id' => 'reviewer-9',
        'name' => 'Darshan Patel',
        'status' => 'awaiting_reviewer_response',
    ]],
], [
    'id' => 'status-event-9',
    'status' => '1',
    'reviewer_id' => 'reviewer-9',
    'created_at' => '2026-08-12 15:40:45',
]);
$acceptanceMessageUpdate = $acceptanceDao->updates[1]['data']['messages_cache'][0] ?? [];
assertSyncValue(
    'Review Request Accepted',
    $acceptanceMessageUpdate['text'] ?? null,
    'A compact acceptance webhook must add its default event message to the OJS conversation.'
);
assertSyncValue(
    'Darshan Patel',
    $acceptanceMessageUpdate['reviewer_name'] ?? null,
    'A compact acceptance webhook must retain the cached reviewer identity.'
);

$publisherMessage = $normalize->invoke($service, [
    'id' => 'message-publisher-17',
    'comment_text' => 'Publisher response',
    'is_from_s9' => false,
    'sender_name' => 'OJSCloud',
    'sender_role' => 'publisher',
]);
assertSyncValue('OJSCloud', $publisherMessage['sender_name'] ?? null, 'Publisher names must survive webhook normalization.');
assertSyncValue('Publisher', $publisherMessage['sender_role'] ?? null, 'Publisher designations must be canonicalized.');

$nativeFile = $normalize->invoke($service, [
    'id' => 'message-file-4',
    'comment_text' => '<p><span><em>File shared: <u><a href="https://ojs.example.test/download?fileId=4&amp;token=safe" target="_blank" rel="noopener noreferrer">paper &amp; notes.pdf</a></u></em></span></p>',
    'is_from_s9' => false,
    'reviewer_id' => 'reviewer-4',
]);
assertSyncValue('File shared: paper & notes.pdf', $nativeFile['text'] ?? null, 'Native attachment HTML must normalize to plain text in OJS.');
assertSyncValue('paper & notes.pdf', $nativeFile['attachment_name'] ?? null, 'Native attachment filenames must survive synchronization.');
assertSyncValue(
    'https://ojs.example.test/download?fileId=4&token=safe',
    $nativeFile['attachment_url'] ?? null,
    'Native attachment links must survive synchronization.'
);

$unsafeFile = $normalize->invoke($service, [
    'comment_text' => '<p>File shared: <a href="javascript:alert(1)" onmouseover="alert(2)">unsafe.pdf</a></p>',
    'is_from_s9' => true,
]);
assertSyncValue('unsafe.pdf', $unsafeFile['attachment_name'] ?? null, 'Safe attachment names should be retained from hostile markup.');
assertSyncValue(null, $unsafeFile['attachment_url'] ?? null, 'Executable attachment links must never reach OJS UI state.');

$unsafeStructuredFile = $normalize->invoke($service, [
    'attachment_name' => 'x</a><script>alert(1)</script>.pdf',
    'attachment_url' => 'data:text/html,unsafe',
    'is_from_s9' => true,
]);
assertSyncValue('x.pdf', $unsafeStructuredFile['attachment_name'] ?? null, 'Structured attachment names must be reduced to a safe basename.');
assertSyncValue(null, $unsafeStructuredFile['attachment_url'] ?? null, 'Structured executable attachment URLs must be rejected.');

$scholar9FileWithSpaces = $extractAttachment->invoke($service, [
    'comment_text' => '<p><em>File shared: </em><a href="http://192.168.29.112:81/uploads/rrf/Thank you - Please Check this.docx" target="_blank">Thank you - Please Check this.docx</a></p>',
], 'File shared: Thank you - Please Check this.docx');
assertSyncValue(
    'http://192.168.29.112:81/uploads/rrf/Thank%20you%20-%20Please%20Check%20this.docx',
    $scholar9FileWithSpaces['url'] ?? null,
    'Scholar9 attachment paths containing spaces must be safely encoded and remain downloadable.'
);
$unsafeRemoteAttachment = $extractAttachment->invoke($service, [
    'attachment_name' => 'unsafe.docx',
    'attachment_url' => 'javascript:alert(1)',
], 'File shared: unsafe.docx');
assertSyncValue(
    null,
    $unsafeRemoteAttachment['url'] ?? null,
    'Attachment normalization must continue rejecting executable URL schemes.'
);
assertSyncValue(
    'revision_required',
    $webhookStatus->invoke($service, ['comment_status' => 'major_revision'], 'under_review'),
    'Scholar9 revision status events must map to the local review status.'
);
assertSyncValue(
    'under_review',
    $webhookStatus->invoke($service, ['review_decision' => 'unknown_future_value'], 'under_review'),
    'Unknown Scholar9 statuses must not regress a valid local status.'
);
assertSyncValue(
    'https://scholar9.example.test/uploads/reviewers/profile.jpg',
    $safeImageUrl->invoke($service, 'https://scholar9.example.test/uploads/reviewers/profile.jpg'),
    'HTTPS Scholar9 reviewer profile photos must survive synchronization.'
);
assertSyncValue(
    null,
    $safeImageUrl->invoke($service, 'javascript:alert(1)'),
    'Executable reviewer profile-photo URLs must not reach OJS UI state.'
);
assertSyncValue(4.67, $safeScore->invoke($service, '4.67'), 'Valid Scholar9 scores must be normalized.');
assertSyncValue(null, $safeScore->invoke($service, '99'), 'Out-of-range Scholar9 scores must be rejected.');
assertSyncValue('reviewer@example.test', $safeEmail->invoke($service, ' reviewer@example.test '), 'Valid webhook emails must be normalized.');
assertSyncValue('', $safeEmail->invoke($service, '<script>@example.test'), 'Invalid webhook emails must be discarded.');
assertSyncValue(
    'under_review',
    $aggregateStatus->invoke($service, [
        ['status' => 'completed'],
        ['status' => '1'],
    ]),
    'One completed reviewer must not close another active reviewer conversation.'
);
assertSyncValue(
    'reviewer_completed',
    $aggregateStatus->invoke($service, [
        ['status' => 'completed'],
        ['status' => 'completed'],
    ]),
    'The review must close after all reviewers complete.'
);
assertSyncValue(
    'reviewer_completed',
    $effectiveAssignmentStatus->invoke($service, [
        'status' => '1',
        'messages' => [[
            'comment_status' => '4',
            'comment_text' => "Approved review\nRating: 5, 4, 3, 2, 1",
        ]],
    ]),
    'An approved Scholar9 conversation must complete the reviewer even when its assignment status remains accepted.'
);
assertSyncValue(
    'under_review',
    $effectiveAssignmentStatus->invoke($service, [
        'status' => '1',
        'messages' => [['comment_status' => '1']],
    ]),
    'A review-acceptance message alone must not complete the reviewer.'
);
assertSyncValue(
    ['recommendation' => 'approved', 'score' => 3.0],
    $reviewOutcomeFromMessages->invoke($service, [[
        'comment_status' => '4',
        'comment_text' => "Final feedback\nRating: 5, 4, 3, 2, 1",
    ]]),
    'Approved conversation metadata must provide the recommendation and average score when auxiliary ratings are empty.'
);
assertSyncValue(
    'under_review',
    $aggregateStatus->invoke($service, [
        [
            'status' => '1',
            'messages' => [['comment_status' => '4', 'comment_text' => 'Rating: 5, 5, 5, 5, 5']],
        ],
        ['status' => '1', 'messages' => []],
    ]),
    'One approved conversation must not close a second active reviewer thread.'
);

$photoClient = new class extends Scholar9ApiClient {
    public function __construct()
    {
    }

    public function getReviewStatus(string $paperId, bool $force = false): ?array
    {
        return [
            'success' => true,
            'data' => [[
                'id' => 'assignment-4',
                'status' => 'completed',
                'recommendation' => 'accepted',
                'score' => 5.0,
                'reviewer' => [
                    'id' => 'reviewer-4',
                    'name' => 'Dr. Reviewer',
                    'email' => 'reviewer@example.test',
                    'photo_url' => 'https://scholar9.example.test/uploads/reviewers/profile.jpg',
                ],
            ]],
        ];
    }
};
$photoDao = new class extends Scholar9ReviewDAO {
    public array $updated = [];

    public function update(int $id, array $data): void
    {
        $this->updated = ['id' => $id, 'data' => $data];
    }
};
$clientProperty = $reflection->getProperty('client');
$clientProperty->setAccessible(true);
$clientProperty->setValue($service, $photoClient);
$daoProperty = $reflection->getProperty('dao');
$daoProperty->setAccessible(true);
$daoProperty->setValue($service, $photoDao);

$hydrated = $service->hydrateReviewerPhotos([
    'id' => 17,
    's9_paper_id' => 'paper-17',
    'reviewers_cache' => [[
        'id' => 'reviewer-4',
        'name' => 'Dr. Reviewer',
        'status' => 'under_review',
        'photo_url' => 'https://scholar9.example.test/uploads/reviewers/old-profile.jpg',
    ]],
]);
assertSyncValue(true, $hydrated, 'Changed Scholar9 profile photos must replace stale local URLs.');
assertSyncValue(
    'https://scholar9.example.test/uploads/reviewers/profile.jpg',
    $photoDao->updated['data']['reviewers_cache'][0]['photo_url'] ?? null,
    'Photo hydration must update only the reviewer cache with the validated profile URL.'
);
assertSyncValue(
    'reviewer_completed',
    $photoDao->updated['data']['status'] ?? null,
    'Workspace snapshot refresh must adopt Scholar9 final-review state.'
);
assertSyncValue(
    'accepted',
    $photoDao->updated['data']['recommendation'] ?? null,
    'Workspace snapshot refresh must retain the final recommendation.'
);
assertSyncValue(
    false,
    array_key_exists('messages_cache', $photoDao->updated['data'] ?? [])
        || array_key_exists('ratings_cache', $photoDao->updated['data'] ?? []),
    'Lightweight workspace refresh must not overwrite messages or ratings.'
);

$photoDao->updated = [];
$initialHydration = $service->hydrateReviewerPhotos([
    'id' => 18,
    's9_paper_id' => 'paper-18',
    'reviewers_cache' => [],
]);
assertSyncValue(true, $initialHydration, 'A new review must hydrate its first reviewer without requiring a pre-existing cache entry.');
assertSyncValue(
    'reviewer-4',
    $photoDao->updated['data']['reviewers_cache'][0]['id'] ?? null,
    'Initial workspace hydration must expose the newly assigned reviewer.'
);

$staleCompletedClient = new class extends Scholar9ApiClient {
    public array $forceFlags = [];

    public function __construct()
    {
    }

    public function getReviewStatus(string $paperId, bool $force = false): ?array
    {
        $this->forceFlags['status'][] = $force;
        return [
            'success' => true,
            'data' => [[
                'id' => 'assignment-stale-completed',
                'status' => '1',
                'reviewer' => [
                    'id' => 'reviewer-stale-completed',
                    'name' => 'Completed Reviewer',
                    'photo_url' => 'https://scholar9.example.test/uploads/reviewers/completed.jpg',
                ],
                'messages' => [[
                    'id' => 'decision-approved',
                    'comment_status' => '4',
                    'comment_text' => "Approved review\nRating: 5, 4, 3, 2, 1",
                ]],
            ]],
        ];
    }

    public function getMessages(string $paperId, bool $force = false): ?array
    {
        $this->forceFlags['messages'][] = $force;
        return ['success' => true, 'data' => []];
    }

    public function getRatings(string $paperId, bool $force = false): ?array
    {
        $this->forceFlags['ratings'][] = $force;
        return ['success' => true, 'data' => []];
    }
};
$staleCompletedDao = new class extends Scholar9ReviewDAO {
    public array $updated = [];

    public function update(int $id, array $data): void
    {
        $this->updated = ['id' => $id, 'data' => $data];
    }
};
$clientProperty->setValue($service, $staleCompletedClient);
$daoProperty->setValue($service, $staleCompletedDao);
assertSyncValue(
    true,
    $service->pullSync([
        'id' => 181,
        's9_paper_id' => 'paper-stale-completed',
        'status' => 'under_review',
        'reviewers_cache' => [[
            'id' => 'reviewer-stale-completed',
            'name' => 'Completed Reviewer',
            'status' => 'under_review',
        ]],
    ]),
    'A full sync must reconcile a terminal conversation with its stale assignment status.'
);
assertSyncValue(
    'reviewer_completed',
    $staleCompletedDao->updated['data']['status'] ?? null,
    'A Scholar9 approved review must close the OJS review workspace.'
);
assertSyncValue(
    'reviewer_completed',
    $staleCompletedDao->updated['data']['reviewers_cache'][0]['status'] ?? null,
    'A Scholar9 approved review must close its reviewer conversation.'
);
assertSyncValue(
    'approved',
    $staleCompletedDao->updated['data']['recommendation'] ?? null,
    'The approved decision must be reflected in the OJS recommendation.'
);
assertSyncValue(
    3.0,
    $staleCompletedDao->updated['data']['score'] ?? null,
    'The embedded Scholar9 criteria ratings must be averaged for the OJS score.'
);
assertSyncValue(
    ['status' => [true], 'messages' => [true], 'ratings' => [true]],
    $staleCompletedClient->forceFlags,
    'Manual synchronization must bypass every short-lived Scholar9 response cache.'
);

$staleCompletedDao->updated = [];
assertSyncValue(
    true,
    $service->refreshReviewSnapshot([
        'id' => 182,
        's9_paper_id' => 'paper-stale-completed',
        'status' => 'under_review',
        'reviewers_cache' => [[
            'id' => 'reviewer-stale-completed',
            'name' => 'Completed Reviewer',
            'status' => 'under_review',
        ]],
    ]),
    'Opening the workspace must detect a submitted review without requiring manual sync.'
);
assertSyncValue(
    'reviewer_completed',
    $staleCompletedDao->updated['data']['status'] ?? null,
    'The lightweight workspace refresh must reflect an approved review.'
);
assertSyncValue(
    [true, false],
    $staleCompletedClient->forceFlags['status'] ?? [],
    'Passive workspace refreshes must use the rate-limit-aware status cache.'
);

$partialClient = new class extends Scholar9ApiClient {
    public function __construct()
    {
    }

    public function getReviewStatus(string $paperId, bool $force = false): ?array
    {
        return ['success' => true, 'data' => [[
            'id' => 'assignment-5',
            'status' => '1',
            'reviewer' => ['id' => 'reviewer-5', 'name' => 'Reviewer Five'],
        ]]];
    }

    public function getMessages(string $paperId, bool $force = false): ?array
    {
        return null;
    }

    public function getRatings(string $paperId, bool $force = false): ?array
    {
        return null;
    }
};
$partialDao = new class extends Scholar9ReviewDAO {
    public array $updated = [];

    public function update(int $id, array $data): void
    {
        $this->updated = ['id' => $id, 'data' => $data];
    }
};
$clientProperty->setValue($service, $partialClient);
$daoProperty->setValue($service, $partialDao);
$partialSync = $service->pullSync([
    'id' => 19,
    's9_paper_id' => 'paper-19',
    'status' => 'under_review',
    'messages_cache' => [['id' => 'existing-message', 'text' => 'Keep me']],
    'ratings_cache' => [['score' => 4]],
]);
assertSyncValue(true, $partialSync, 'A valid status response must still refresh the review when an auxiliary endpoint is unavailable.');
assertSyncValue(
    false,
    array_key_exists('messages_cache', $partialDao->updated['data'] ?? []),
    'A failed auxiliary message request must not erase the existing OJS conversation cache.'
);
assertSyncValue(
    [['score' => 4]],
    $partialDao->updated['data']['ratings_cache'] ?? null,
    'A failed ratings request must preserve the existing ratings cache.'
);

$emptyStatusClient = new class extends Scholar9ApiClient {
    public function __construct()
    {
    }

    public function getReviewStatus(string $paperId, bool $force = false): ?array
    {
        return ['success' => true, 'data' => []];
    }

    public function getMessages(string $paperId, bool $force = false): ?array
    {
        return ['success' => true, 'data' => []];
    }

    public function getRatings(string $paperId, bool $force = false): ?array
    {
        return ['success' => true, 'data' => []];
    }
};
$emptyStatusDao = new class extends Scholar9ReviewDAO {
    public array $updates = [];

    public function update(int $id, array $data): void
    {
        $this->updates[] = ['id' => $id, 'data' => $data];
    }
};
$clientProperty->setValue($service, $emptyStatusClient);
$daoProperty->setValue($service, $emptyStatusDao);
assertSyncValue(
    false,
    $service->pullSync([
        'id' => 20,
        's9_paper_id' => 'paper-empty',
        'status' => 'reviewer_completed',
        'messages_cache' => [['id' => 'existing-message', 'text' => 'Keep me']],
        'ratings_cache' => [['score' => 5]],
    ]),
    'An empty Scholar9 assignment list must be treated as an invalid sync response.'
);
assertSyncValue([], $emptyStatusDao->updates, 'An empty sync response must not overwrite a valid local review snapshot.');
assertSyncValue(
    'Scholar9 returned no reviewer assignments for this paper',
    $service->getLastError(),
    'An empty sync response must expose an actionable error.'
);

$testPlugin = new class extends \PKP\plugins\GenericPlugin {
    public function getDisplayName()
    {
        return 'Scholar9 test plugin';
    }

    public function getDescription()
    {
        return 'Scholar9 sync regression test';
    }

    public function getSetting($contextId, $name)
    {
        return match ($name) {
            'defaultReviewType' => 'double_anonymous',
            'reviewDeadline' => 30,
            default => null,
        };
    }
};
$partialAssignmentClient = new class extends Scholar9ApiClient {
    public int $calls = 0;

    public function __construct()
    {
    }

    public function createReview(array $data): ?array
    {
        $this->calls++;
        if ($this->calls === 2) {
            return ['success' => false, 'error' => 'Reviewer unavailable'];
        }
        return [
            'success' => true,
            'data' => ['paper_id' => 'paper-multi', 'review_url' => 'https://scholar9.example.test/review/paper-multi'],
        ];
    }
};
$partialAssignmentDao = new class extends Scholar9ReviewDAO {
    public array $inserted = [];

    public function insert(array $data): int
    {
        $this->inserted = $data;
        return 77;
    }
};
$partialAssignmentService = new Scholar9SyncService($testPlugin, $partialAssignmentClient, $partialAssignmentDao);
assertSyncValue(
    77,
    $partialAssignmentService->createReview(
        9,
        90,
        0,
        ['title' => 'Multi-reviewer test', 'author' => 'Test Author'],
        'https://ojs.example.test/manuscript/90',
        'journal-r0-90',
        null,
        [],
        ['reviewer-one', 'reviewer-two', 'reviewer-three'],
        []
    ),
    'A failed additional assignment must not discard the successfully created Scholar9 review.'
);
assertSyncValue(3, $partialAssignmentClient->calls, 'Every selected reviewer must receive one assignment attempt.');
assertSyncValue(
    '1 additional reviewer assignment was not accepted by Scholar9. The review remains active for the successfully assigned reviewers.',
    $partialAssignmentService->getLastWarning(),
    'Partial reviewer assignment must be reported to the editor instead of being silently ignored.'
);

$webhookClient = new class extends Scholar9ApiClient {
    public array $forgottenPapers = [];

    public function __construct()
    {
    }

    public function verifyWebhookSignature(string $payload, string $signature, ?string $secret = null): bool
    {
        return $signature === 'valid-signature' && $secret === 'journal-secret';
    }

    public function forgetReviewCaches(string $paperId): void
    {
        $this->forgottenPapers[] = $paperId;
    }
};
$webhookDao = new class extends Scholar9ReviewDAO {
    public ?int $journalId = null;
    public array $updated = [];

    public function getByS9PaperId(string $s9PaperId, ?int $journalId = null): ?array
    {
        $this->journalId = $journalId;
        return [
            'id' => 20,
            'journal_id' => 37,
            's9_paper_id' => $s9PaperId,
            'webhook_secret' => 'journal-secret',
        ];
    }

    public function update(int $id, array $data): void
    {
        $this->updated = ['id' => $id, 'data' => $data];
    }
};
$clientProperty->setValue($service, $webhookClient);
$daoProperty->setValue($service, $webhookDao);
$webhookPayload = [
    'event' => 'review.completed',
    'paper_id' => 'paper-20',
    'reviewer_comment' => '<script>alert(1)</script>Useful final feedback.',
    'recommendation' => '<b>accepted</b>',
    'score' => 99,
];
$webhookHandled = $service->handleWebhook(
    $webhookPayload,
    json_encode($webhookPayload, JSON_THROW_ON_ERROR),
    'valid-signature',
    37
);
assertSyncValue(true, $webhookHandled, 'A valid journal-scoped completion webhook must be processed.');
assertSyncValue(37, $webhookDao->journalId, 'Webhook lookup must be constrained to the journal route that received it.');
assertSyncValue('Useful final feedback.', $webhookDao->updated['data']['reviewer_comment'] ?? null, 'Webhook comments must be sanitized before persistence.');
assertSyncValue('accepted', $webhookDao->updated['data']['recommendation'] ?? null, 'Webhook recommendations must be stored as plain text.');
assertSyncValue(true, array_key_exists('score', $webhookDao->updated['data'] ?? []), 'Completion webhooks must explicitly normalize the score field.');
assertSyncValue(null, $webhookDao->updated['data']['score'], 'Out-of-range webhook scores must be rejected.');

$multiReviewerWebhookDao = new class extends Scholar9ReviewDAO {
    public array $updated = [];

    public function getByS9PaperId(string $s9PaperId, ?int $journalId = null): ?array
    {
        return [
            'id' => 21,
            'journal_id' => 37,
            's9_paper_id' => $s9PaperId,
            'webhook_secret' => 'journal-secret',
            'status' => 'under_review',
            'reviewers_cache' => [
                ['id' => 'reviewer-done', 'name' => 'Done Reviewer', 'status' => 'under_review'],
                ['id' => 'reviewer-active', 'name' => 'Active Reviewer', 'status' => 'under_review'],
            ],
        ];
    }

    public function update(int $id, array $data): void
    {
        $this->updated = ['id' => $id, 'data' => $data];
    }
};
$daoProperty->setValue($service, $multiReviewerWebhookDao);
$multiReviewerPayload = [
    'event' => 'review.completed',
    'paper_id' => 'paper-21',
    'reviewer_id' => 'reviewer-done',
    'reviewer_comment' => 'Finished.',
];
assertSyncValue(
    true,
    $service->handleWebhook(
        $multiReviewerPayload,
        json_encode($multiReviewerPayload, JSON_THROW_ON_ERROR),
        'valid-signature',
        37
    ),
    'A reviewer-scoped completion webhook must be processed.'
);
assertSyncValue(
    'under_review',
    $multiReviewerWebhookDao->updated['data']['status'] ?? null,
    'One reviewer completion webhook must not close another active reviewer conversation.'
);
assertSyncValue(
    'reviewer_completed',
    $multiReviewerWebhookDao->updated['data']['reviewers_cache'][0]['status'] ?? null,
    'The completed reviewer thread must be marked read-only immediately.'
);

$staleStatusWebhookDao = new class extends Scholar9ReviewDAO {
    public array $updates = [];

    public function getByS9PaperId(string $s9PaperId, ?int $journalId = null): ?array
    {
        return [
            'id' => 22,
            'journal_id' => 37,
            's9_paper_id' => $s9PaperId,
            'webhook_secret' => 'journal-secret',
            'status' => 'decision_returned',
        ];
    }

    public function update(int $id, array $data): void
    {
        $this->updates[] = ['id' => $id, 'data' => $data];
    }
};
$daoProperty->setValue($service, $staleStatusWebhookDao);
$staleStatusPayload = [
    'event' => 'review.status_updated',
    'paper_id' => 'paper-22',
    'status' => 'under_review',
];
assertSyncValue(
    true,
    $service->handleWebhook(
        $staleStatusPayload,
        json_encode($staleStatusPayload, JSON_THROW_ON_ERROR),
        'valid-signature',
        37
    ),
    'A correctly signed delayed status webhook is acknowledged.'
);
assertSyncValue(
    [],
    $staleStatusWebhookDao->updates,
    'A delayed active-status webhook must not reopen a terminal review.'
);

$staleAssignmentPayload = [
    'event' => 'review.assigned',
    'paper_id' => 'paper-22',
    'reviewer_id' => 'late-reviewer',
    'reviewer_name' => 'Late Reviewer',
    'reviewer_email' => 'late@example.test',
];
assertSyncValue(
    true,
    $service->handleWebhook(
        $staleAssignmentPayload,
        json_encode($staleAssignmentPayload, JSON_THROW_ON_ERROR),
        'valid-signature',
        37
    ),
    'A correctly signed delayed assignment webhook is acknowledged.'
);
assertSyncValue(
    [],
    $staleStatusWebhookDao->updates,
    'A delayed assignment webhook must not reopen or replace a terminal review.'
);

fwrite(STDOUT, "Scholar9 sync service tests passed.\n");
