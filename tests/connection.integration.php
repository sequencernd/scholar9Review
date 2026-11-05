<?php

declare(strict_types=1);

/** Read-only live check for configured OAuth and journal-list connectivity. */

$ojsRoot = dirname(__DIR__, 4);
defined('INDEX_FILE_LOCATION') || define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
chdir($ojsRoot);
require $ojsRoot . '/lib/pkp/includes/bootstrap.php';

config([
    'cache.stores.scholar9_test' => ['driver' => 'array'],
    'cache.default' => 'scholar9_test',
]);

$contextId = (int) ($argv[1] ?? 0);
if ($contextId < 1) {
    fwrite(STDERR, "Usage: php tests/connection.integration.php <context-id>\n");
    exit(2);
}

$plugin = require dirname(__DIR__) . '/index.php';
$client = new \APP\plugins\generic\scholar9Review\Scholar9ApiClient($plugin, $contextId);
if (!$client->isConfigured()) {
    fwrite(STDERR, "The selected OJS context does not have a complete Scholar9 credential set.\n");
    exit(3);
}

$context = \APP\core\Application::getContextDAO()->getById($contextId);
if (!$context) {
    fwrite(STDERR, "OJS journal context {$contextId} was not found.\n");
    exit(2);
}
$configuredBaseUrl = rtrim((string) \PKP\config\Config::getVar('general', 'base_url'), '/');
$request = new class($configuredBaseUrl, $context) extends \APP\core\Request {
    public function __construct(private string $baseUrl, private $journal)
    {
    }

    public function getBaseUrl(bool $allowProtocolRelative = false): string
    {
        return $this->baseUrl;
    }

    public function getContext(): ?\APP\journal\Journal
    {
        return $this->journal;
    }
};
\PKP\core\Registry::set('request', $request);
$metadataProvider = new class extends \APP\plugins\generic\scholar9Review\OjsJournalMetadataProvider {
    protected function getOjsVersion(): string
    {
        return 'connection-smoke-test';
    }
};
$metadata = $metadataProvider->build($context, $request);
$response = $client->verifyJournalConnection($metadata);
if (!$response || empty($response['success']) || empty($response['data']['verified'])) {
    fwrite(STDERR, ($client->getLastError() ?: 'Scholar9 connection verification failed.') . "\n");
    exit(4);
}

printf(
    "Scholar9 connection verified for OJS context %d; visible journals=%d.\n",
    $contextId,
    (int) ($response['data']['journal_count'] ?? 0)
);
