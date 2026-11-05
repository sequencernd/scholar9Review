<?php

declare(strict_types=1);

/** Read-only live diagnostic for Scholar9/OJS review completion state. */

$ojsRoot = dirname(__DIR__, 4);
defined('INDEX_FILE_LOCATION') || define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
chdir($ojsRoot);
require $ojsRoot . '/lib/pkp/includes/bootstrap.php';

// Keep this read-only diagnostic isolated from OJS's filesystem cache. This
// avoids CLI permission noise and prevents cached integration settings from
// being included in filesystem exception traces.
config([
    'cache.stores.scholar9_test' => ['driver' => 'array'],
    'cache.default' => 'scholar9_test',
]);

$plugin = require dirname(__DIR__) . '/index.php';

$contextId = (int) ($argv[1] ?? 2);
$submissionId = (int) ($argv[2] ?? 3);
$roundId = (int) ($argv[3] ?? 0);
$dao = new \APP\plugins\generic\scholar9Review\Scholar9ReviewDAO();
$record = $dao->getBySubmission($contextId, $submissionId, $roundId);
if (!$record) {
    fwrite(STDERR, "Review record not found.\n");
    exit(2);
}
$client = new \APP\plugins\generic\scholar9Review\Scholar9ApiClient($plugin, $contextId);
$shouldSync = in_array('--sync', $argv, true);
$shouldRefreshSnapshot = in_array('--snapshot', $argv, true);
if ($shouldSync || $shouldRefreshSnapshot) {
    $sync = new \APP\plugins\generic\scholar9Review\Scholar9SyncService($plugin, $client, $dao);
    $synced = $shouldSync
        ? $sync->pullSync($record)
        : $sync->refreshReviewSnapshot($record);
    if (!$synced && $shouldSync) {
        fwrite(STDERR, ($sync->getLastError() ?: 'Sync failed.') . "\n");
        exit(3);
    }
    $record = $dao->getBySubmission($contextId, $submissionId, $roundId);
    fwrite(
        STDOUT,
        $shouldSync
            ? "Targeted OJS cache sync completed.\n"
            : "Targeted OJS workspace snapshot refresh completed.\n"
    );
}
$status = $client->getReviewStatus((string) $record['s9_paper_id']);
$ratings = $client->getRatings((string) $record['s9_paper_id']);
$assignments = is_array($status['data'] ?? null) ? $status['data'] : [];
if (isset($assignments['status']) || isset($assignments['reviewer'])) {
    $assignments = [$assignments];
}

printf(
    "OJS status=%s recommendation=%s score=%s assignments=%d\n",
    (string) ($record['status'] ?? ''),
    (string) ($record['recommendation'] ?? '-'),
    (string) ($record['score'] ?? '-'),
    count($assignments)
);
$cachedReviewers = is_array($record['reviewers_cache'] ?? null) ? $record['reviewers_cache'] : [];
$cachedMessages = is_array($record['messages_cache'] ?? null) ? $record['messages_cache'] : [];
$firstCachedReviewer = is_array($cachedReviewers[0] ?? null) ? $cachedReviewers[0] : [];
printf(
    "OJS cache: reviewers=%d first-photo=%s messages=%d\n",
    count($cachedReviewers),
    trim((string) ($firstCachedReviewer['photo_url'] ?? '')) !== '' ? 'present' : 'missing',
    count($cachedMessages)
);
foreach ($cachedMessages as $cachedMessage) {
    if (!is_array($cachedMessage)) {
        continue;
    }
    printf(
        "Cached message: status=%s text=%s attachment=%s url=%s\n",
        (string) ($cachedMessage['status'] ?? '-'),
        trim((string) ($cachedMessage['text'] ?? '')) !== ''
            ? (string) $cachedMessage['text']
            : '[blank]',
        trim((string) ($cachedMessage['attachment_name'] ?? '')) !== '' ? 'present' : 'missing',
        trim((string) ($cachedMessage['attachment_url'] ?? '')) !== '' ? 'present' : 'missing'
    );
}
foreach ($assignments as $index => $assignment) {
    $messages = is_array($assignment['messages'] ?? null) ? $assignment['messages'] : [];
    $last = $messages ? $messages[array_key_last($messages)] : [];
    $messageStatuses = [];
    foreach ($messages as $message) {
        $messageStatus = trim((string) ($message['comment_status'] ?? ''));
        if ($messageStatus !== '') {
            $messageStatuses[] = $messageStatus;
        }
    }
    printf(
        "Assignment %d: status=%s messages=%d message-statuses=%s latest-comment-status=%s latest-rating=%s\n",
        $index + 1,
        (string) ($assignment['status'] ?? ''),
        count($messages),
        $messageStatuses ? implode(',', $messageStatuses) : '-',
        (string) ($last['comment_status'] ?? '-'),
        !empty($last['rating_data']) ? 'present' : 'missing'
    );
    if (in_array('--debug', $argv, true) && $last) {
        fwrite(STDOUT, 'Latest raw message: ' . json_encode($last, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
$ratingRows = is_array($ratings['data'] ?? null) ? $ratings['data'] : [];
printf("Ratings endpoint: success=%s rows=%d\n", !empty($ratings['success']) ? 'yes' : 'no', count($ratingRows));
