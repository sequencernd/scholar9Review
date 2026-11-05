<?php

declare(strict_types=1);

/**
 * Optional live OJS metadata smoke test.
 *
 * This test reads the configured OJS database but does not write to it or call
 * Scholar9. Pass a journal path as the first argument; `demo` is the default.
 * Pass `--all` to audit every journal and verify that context identities are
 * unique (the production multi-journal sync invariant).
 */

$ojsRoot = dirname(__DIR__, 4);
defined('INDEX_FILE_LOCATION') || define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
chdir($ojsRoot);
require $ojsRoot . '/lib/pkp/includes/bootstrap.php';
require_once dirname(__DIR__) . '/classes/OjsJournalMetadataProvider.php';
config([
    'cache.stores.scholar9_test' => ['driver' => 'array'],
    'cache.default' => 'scholar9_test',
]);

use APP\core\Application;
use APP\core\Request;
use APP\plugins\generic\scholar9Review\OjsJournalMetadataProvider;
use PKP\core\Registry;

$configuredBaseUrl = rtrim((string) \PKP\config\Config::getVar('general', 'base_url'), '/');
$request = new class($configuredBaseUrl) extends Request {
    private $journal = null;

    public function __construct(private string $baseUrl)
    {
    }

    public function useContext($journal): void
    {
        $this->journal = $journal;
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
Registry::set('request', $request);

$provider = new class extends OjsJournalMetadataProvider {
    protected function getOjsVersion(): string
    {
        return 'live-database-smoke-test';
    }
};
$selector = isset($argv[1]) ? trim((string) $argv[1]) : 'demo';
$requireComplete = in_array('--require-complete', $argv, true);
$contexts = [];
if ($selector === '--all') {
    $factory = Application::getContextDAO()->getAll();
    while ($context = $factory->next()) {
        $contexts[] = $context;
    }
} else {
    $context = Application::getContextDAO()->getByPath($selector);
    if ($context) {
        $contexts[] = $context;
    }
}
if (!$contexts) {
    fwrite(STDERR, "No OJS journals matched: {$selector}\n");
    exit(2);
}

$identities = [];
$incompleteCount = 0;
foreach ($contexts as $context) {
    $request->useContext($context);
    $payload = $provider->build($context, $request);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $journal = $payload['journal'] ?? [];
    $missingRequired = $provider->missingRequiredFields($context, $payload);

    $invalidJournal = !is_array($journal);
    $missingName = trim((string) ($journal['name'] ?? '')) === '';
    $identity = trim((string) ($payload['journal_id'] ?? ''));
    $missingId = $identity === '';
    $unsafeMarkup = (bool) preg_match('/<(?:script|iframe|object|embed|svg|math)\b/i', (string) $encoded);
    $duplicateId = !$missingId && isset($identities[$identity]);
    if ($invalidJournal || $missingName || $missingId || $unsafeMarkup || $duplicateId) {
        fwrite(STDERR, sprintf(
            "Metadata audit failed for context %d (journal=%d, name=%d, id=%d, markup=%d, duplicateId=%d).\n",
            (int) $context->getId(),
            !$invalidJournal,
            !$missingName,
            !$missingId,
            $unsafeMarkup,
            $duplicateId
        ));
        exit(3);
    }
    if ($missingRequired) {
        $incompleteCount++;
    }
    $identities[$identity] = (int) $context->getId();

    printf(
        "[%s] context=%d path=%s fields=%d eISSN=%s pISSN=%s languages=%d board=%d required=%s identity=%s\n",
        $missingRequired ? 'INCOMPLETE' : 'PASS',
        (int) $context->getId(),
        (string) $context->getPath(),
        count($journal),
        trim((string) ($journal['online_issn'] ?? '')) !== '' ? 'present' : 'missing-in-OJS',
        trim((string) ($journal['print_issn'] ?? '')) !== '' ? 'present' : 'missing-in-OJS',
        count((array) ($journal['languages'] ?? [])),
        count((array) ($journal['editorial_board'] ?? [])),
        $missingRequired ? implode('; ', $missingRequired) : 'complete',
        substr($identity, -12)
    );
}

printf(
    "Live OJS metadata audit passed structurally for %d journal(s); identities are unique; incomplete=%d.\n",
    count($contexts),
    $incompleteCount
);
if ($requireComplete && $incompleteCount > 0) {
    exit(4);
}
