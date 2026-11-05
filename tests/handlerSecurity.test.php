<?php

declare(strict_types=1);

if (!defined('PKP_STRICT_MODE')) {
    define('PKP_STRICT_MODE', false);
}

require_once dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/autoload.php';
require_once dirname(__DIR__) . '/classes/Scholar9ApiClient.php';
require_once dirname(__DIR__) . '/classes/Scholar9ReviewDAO.php';
require_once dirname(__DIR__) . '/classes/Scholar9SyncService.php';
require_once dirname(__DIR__) . '/pages/Scholar9ReviewHandler.php';

use APP\plugins\generic\scholar9Review\Scholar9ReviewDAO;
use APP\plugins\generic\scholar9Review\Scholar9ReviewHandler;
use APP\plugins\generic\scholar9Review\Scholar9SyncService;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
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

$reflection = new ReflectionClass(Scholar9ReviewHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();

$sanitize = $reflection->getMethod('sanitizeMessageText');
$sanitize->setAccessible(true);
$buildFileShareMessage = $reflection->getMethod('buildFileShareMessage');
$buildFileShareMessage->setAccessible(true);
$isConversationClosed = $reflection->getMethod('isConversationClosed');
$isConversationClosed->setAccessible(true);
$isReviewerConversationClosed = $reflection->getMethod('isReviewerConversationClosed');
$isReviewerConversationClosed->setAccessible(true);
$normalizeReviewerRatings = $reflection->getMethod('normalizeReviewerRatings');
$normalizeReviewerRatings->setAccessible(true);
$messageContainsRating = $reflection->getMethod('messageContainsRating');
$messageContainsRating->setAccessible(true);

assertSameValue(
    [1, 2, 3, 4, 5],
    $normalizeReviewerRatings->invoke($handler, ['1', 2, '3', 4, '5']),
    'A complete five-criterion integer rating must be accepted.'
);
assertSameValue(
    null,
    $normalizeReviewerRatings->invoke($handler, ['1', '2.5', '3', '4', '5']),
    'Decimal ratings must not be silently coerced to integers.'
);
assertSameValue(
    null,
    $normalizeReviewerRatings->invoke($handler, ['1', '2', '3', '4']),
    'Incomplete publisher ratings must be rejected.'
);
assertSameValue(
    true,
    $messageContainsRating->invoke($handler, ['text' => "Feedback\nRating: 5, 4, 3, 2, 1"]),
    'Legacy publisher ratings must participate in duplicate prevention.'
);
assertSameValue(
    false,
    $messageContainsRating->invoke($handler, ['text' => 'No rating has been submitted.']),
    'Ordinary publisher messages must not be mistaken for ratings.'
);

assertSameValue(
    'A useful editorial message.',
    $sanitize->invoke(
        $handler,
        '<script>alert("x")</script>A useful <strong>editorial</strong> message.'
    ),
    'Script blocks and markup must be removed while preserving plain text.'
);
assertSameValue(
    'Safe text',
    $sanitize->invoke(
        $handler,
        'Safe text <img src=x onerror=alert(1)> javascript:alert("again");'
    ),
    'Event handlers, executable URI schemes, and executable calls must be removed.'
);
assertSameValue(
    'Encoded content',
    $sanitize->invoke(
        $handler,
        '&lt;script&gt;confirm("x")&lt;/script&gt;Encoded content'
    ),
    'Encoded script markup must be decoded and removed before persistence.'
);

$nativeFileMessage = $buildFileShareMessage->invoke(
    $handler,
    'paper & notes.pdf',
    'https://ojs.example.test/download?fileId=4&token=safe-token'
);
assertSameValue(
    '<p><span><em>File shared: <u><a href="https://ojs.example.test/download?fileId=4&amp;token=safe-token" target="_blank" rel="noopener noreferrer">paper &amp; notes.pdf</a></u></em></span></p>',
    $nativeFileMessage,
    'File messages must use Scholar9 native attachment markup with escaped dynamic values.'
);
assertSameValue(
    '<p><span><em>File shared: <u><a href="https://ojs.example.test/download" target="_blank" rel="noopener noreferrer">x.pdf</a></u></em></span></p>',
    $buildFileShareMessage->invoke(
        $handler,
        'x</a><script>alert(1)</script>.pdf',
        'https://ojs.example.test/download'
    ),
    'A hostile filename must not break out of the fixed attachment markup.'
);
assertSameValue(
    null,
    $buildFileShareMessage->invoke($handler, 'paper.pdf', 'javascript:alert(1)'),
    'Executable attachment URLs must be rejected.'
);
assertSameValue(
    null,
    $buildFileShareMessage->invoke($handler, 'paper.pdf', 'data:text/html,unsafe'),
    'Data URLs must be rejected.'
);

$publicRecord = $reflection->getMethod('publicRecord');
$publicRecord->setAccessible(true);
$record = $publicRecord->invoke($handler, [
    'submission_id' => 2,
    'status' => 'under_review',
    'reviewer_email' => 'private-reviewer@example.test',
    'reviewers_cache' => [[
        'id' => 'reviewer-1',
        'name' => 'Reviewer One',
        'email' => 'private-reviewer@example.test',
    ]],
    'webhook_secret' => 'private-secret',
    's9_webhook_id' => 'private-webhook-id',
]);
assertSameValue(2, $record['submission_id'], 'The scoped submission ID must be retained.');
assertSameValue(false, array_key_exists('webhook_secret', $record), 'Webhook secrets must not reach the browser.');
assertSameValue(false, array_key_exists('s9_webhook_id', $record), 'Remote webhook IDs must not reach the browser.');
assertSameValue(false, array_key_exists('reviewer_email', $record), 'Reviewer email must not reach the OJS browser response.');
assertSameValue(
    false,
    array_key_exists('email', $record['reviewers_cache'][0]),
    'Cached reviewer email must not reach the OJS browser response.'
);
$reviewerNameForId = $reflection->getMethod('reviewerNameForId');
$reviewerNameForId->setAccessible(true);
assertSameValue(
    'Reviewer',
    $reviewerNameForId->invoke($handler, [
        'reviewers_cache' => [[
            'id' => 'reviewer-private',
            'email' => 'private-reviewer@example.test',
        ]],
    ], 'reviewer-private'),
    'A missing reviewer name must use a generic label, never the reviewer email.'
);
assertSameValue(
    true,
    $isConversationClosed->invoke($handler, ['status' => 'reviewer_completed']),
    'Completed reviews must be immutable through message, file, and reviewer actions.'
);
assertSameValue(
    false,
    $isConversationClosed->invoke($handler, ['status' => 'under_review']),
    'Active reviews must retain their collaboration actions.'
);
assertSameValue(
    true,
    $isReviewerConversationClosed->invoke($handler, [
        'status' => 'under_review',
        'reviewers_cache' => [
            ['id' => 'reviewer-done', 'status' => 'reviewer_completed'],
            ['id' => 'reviewer-active', 'status' => 'under_review'],
        ],
    ], 'reviewer-done'),
    'A completed reviewer thread must be read-only while another reviewer remains active.'
);
assertSameValue(
    false,
    $isReviewerConversationClosed->invoke($handler, [
        'status' => 'under_review',
        'reviewers_cache' => [
            ['id' => 'reviewer-done', 'status' => 'reviewer_completed'],
            ['id' => 'reviewer-active', 'status' => 'under_review'],
        ],
    ], 'reviewer-active'),
    'An active reviewer thread must remain writable.'
);

// ── opSync rate-limit protection ────────────────────────────────────────────

$opSync = $reflection->getMethod('opSync');
$opSync->setAccessible(true);

$fakeContext = new class {
    public function getId(): int
    {
        return 1;
    }
};

function fakeSyncRecord(string $lastSyncedAt): array
{
    return [
        'id' => 10,
        'submission_id' => 55,
        'review_round_id' => 0,
        's9_paper_id' => 'paper-1',
        'last_synced_at' => $lastSyncedAt,
    ];
}

$recentlySyncedDao = new class(fakeSyncRecord(date('Y-m-d H:i:s'))) extends Scholar9ReviewDAO {
    public function __construct(private array $record)
    {
    }
    public function getBySubmission(int $journalId, int $submissionId, int $reviewRoundId = 0): ?array
    {
        return $this->record;
    }
};

$shouldNotBeCalledSync = new class extends Scholar9SyncService {
    public bool $pullSyncCalled = false;
    public function __construct()
    {
    }
    public function pullSync(array $record, bool $force = true): bool
    {
        $this->pullSyncCalled = true;
        return true;
    }
};

ob_start();
$opSync->invoke($handler, $recentlySyncedDao, $shouldNotBeCalledSync, $fakeContext, 55, 0);
$recentSyncOutput = json_decode((string) ob_get_clean(), true);

assertSameValue(
    false,
    $shouldNotBeCalledSync->pullSyncCalled,
    'A Sync click moments after the last one must reuse the cached snapshot instead of calling Scholar9 again.'
);
assertSameValue(
    true,
    $recentSyncOutput['success'] ?? null,
    'Skipping a too-soon forced sync must still report success using the existing record.'
);

$staleRecord = fakeSyncRecord(date('Y-m-d H:i:s', time() - 3600));

$staleDao = new class($staleRecord) extends Scholar9ReviewDAO {
    public function __construct(private array $record)
    {
    }
    public function getBySubmission(int $journalId, int $submissionId, int $reviewRoundId = 0): ?array
    {
        return $this->record;
    }
};

$shouldBeCalledSync = new class extends Scholar9SyncService {
    public bool $pullSyncCalled = false;
    public function __construct()
    {
    }
    public function pullSync(array $record, bool $force = true): bool
    {
        $this->pullSyncCalled = true;
        return true;
    }
};

ob_start();
$opSync->invoke($handler, $staleDao, $shouldBeCalledSync, $fakeContext, 55, 0);
ob_get_clean();

assertSameValue(
    true,
    $shouldBeCalledSync->pullSyncCalled,
    'A Sync click well after the last one must still reach the live Scholar9 API.'
);

$rateLimitedSync = new class extends Scholar9SyncService {
    public function __construct()
    {
    }
    public function pullSync(array $record, bool $force = true): bool
    {
        return false;
    }
    public function getLastError(): ?string
    {
        return 'Scholar9 rate limit reached. Try again in 12 seconds.';
    }
    public function getRetryAfterSeconds(): ?int
    {
        return 12;
    }
};

ob_start();
$opSync->invoke($handler, $staleDao, $rateLimitedSync, $fakeContext, 55, 0);
$rateLimitedOutput = json_decode((string) ob_get_clean(), true);

assertSameValue(
    'Scholar9 rate limit reached. Try again in 12 seconds.',
    $rateLimitedOutput['error'] ?? null,
    'A rate-limited sync failure must surface Scholar9\'s own message to the editor.'
);
assertSameValue(
    12,
    $rateLimitedOutput['retry_after'] ?? null,
    'A rate-limited sync failure must tell the browser exactly how long to back off before retrying.'
);

fwrite(STDOUT, "Scholar9 handler security tests passed.\n");
