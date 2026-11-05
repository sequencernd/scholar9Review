<?php

declare(strict_types=1);

/**
 * Live visibility smoke test.
 *
 * Enables and then restores both Scholar9 visibility settings. The final
 * disable calls run in a finally block so this test does not leave review
 * visibility enabled when an assertion or remote request fails.
 */

$ojsRoot = dirname(__DIR__, 4);
defined('INDEX_FILE_LOCATION') || define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
chdir($ojsRoot);
require $ojsRoot . '/lib/pkp/includes/bootstrap.php';

$contextId = (int) ($argv[1] ?? 0);
$paperId = trim((string) ($argv[2] ?? ''));
$reviewerId = trim((string) ($argv[3] ?? ''));
if ($contextId < 1 || $paperId === '' || $reviewerId === '') {
    fwrite(STDERR, "Usage: php tests/visibility.integration.php <context-id> <paper-id> <reviewer-id>\n");
    exit(2);
}

$plugin = require dirname(__DIR__) . '/index.php';
$client = new \APP\plugins\generic\scholar9Review\Scholar9ApiClient($plugin, $contextId);
if (!$client->isConfigured()) {
    fwrite(STDERR, "The selected OJS context does not have a complete Scholar9 credential set.\n");
    exit(3);
}

$assertSuccess = static function ($response, string $operation) use ($client): void {
    if (!$response || empty($response['success'])) {
        throw new RuntimeException($operation . ': ' . ($client->getLastError() ?: 'unknown Scholar9 error'));
    }
};

try {
    $assertSuccess($client->setFullReviewStatus($paperId, true), 'Enable full review');
    $assertSuccess($client->setOpenReviewStatus($paperId, $reviewerId, true), 'Enable reviewer open review');
} finally {
    $fullRestore = $client->setFullReviewStatus($paperId, false);
    $reviewerRestore = $client->setOpenReviewStatus($paperId, $reviewerId, false);
}

$assertSuccess($fullRestore, 'Restore full review');
$assertSuccess($reviewerRestore, 'Restore reviewer open review');
echo "Scholar9 full-review and reviewer open-review toggles passed; both states restored to OFF.\n";
