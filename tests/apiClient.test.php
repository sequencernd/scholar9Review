<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/autoload.php';
require_once dirname(__DIR__) . '/stubs/pkp-stubs.php';
require_once dirname(__DIR__) . '/classes/Scholar9ApiClient.php';

use APP\plugins\generic\scholar9Review\Scholar9ApiClient;
use PKP\plugins\GenericPlugin;

function assertApiValue(mixed $expected, mixed $actual, string $message): void
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

final class ApiClientSettingsPlugin extends GenericPlugin
{
    public function __construct(private array $settings)
    {
    }

    public function getName(): string
    {
        return 'scholar9ApiClientTest';
    }

    public function getDisplayName(): string
    {
        return 'Scholar9 API Client Test';
    }

    public function getDescription(): string
    {
        return 'Test double for Scholar9 API client settings.';
    }

    public function getSetting(int $contextId, string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }
}

$defaultUrlClient = new Scholar9ApiClient(new ApiClientSettingsPlugin([
    'baseUrl' => '',
    'clientId' => 'client-id',
    'clientSecret' => 'client-secret',
    'journalSlug' => 'journal-slug',
]), 1);
assertApiValue(
    true,
    $defaultUrlClient->hasBaseUrl(),
    'The default Scholar9 URL must satisfy the sync-dialog Base URL requirement when no value is stored.'
);
assertApiValue(
    Scholar9ApiClient::DEFAULT_BASE_URL,
    $defaultUrlClient->publicOrigin(),
    'An empty stored Base URL must resolve to the shared Scholar9 default.'
);
assertApiValue(
    true,
    $defaultUrlClient->isConfigured(),
    'Valid credentials and a journal slug must remain configured when the shared default Base URL is used.'
);

$customUrlClient = new Scholar9ApiClient(new ApiClientSettingsPlugin([
    'baseUrl' => 'https://scholar9.example.test/',
]), 1);
assertApiValue(
    'https://scholar9.example.test',
    $customUrlClient->publicOrigin(),
    'A saved custom Scholar9 Base URL must override the default.'
);

$reflection = new ReflectionClass(Scholar9ApiClient::class);
$client = $reflection->newInstanceWithoutConstructor();
$extractTokenData = $reflection->getMethod('extractTokenData');
$extractTokenData->setAccessible(true);
$messagePayload = $reflection->getMethod('messagePayload');
$messagePayload->setAccessible(true);
$connectionErrorMessage = $reflection->getMethod('connectionErrorMessage');
$connectionErrorMessage->setAccessible(true);
$rateLimitMessage = $reflection->getMethod('rateLimitMessage');
$rateLimitMessage->setAccessible(true);
$responseCacheKey = $reflection->getMethod('responseCacheKey');
$responseCacheKey->setAccessible(true);
$registrationPayload = $reflection->getMethod('registrationPayload');
$registrationPayload->setAccessible(true);
$findConnectedJournal = $reflection->getMethod('findConnectedJournal');
$findConnectedJournal->setAccessible(true);
$mergePublicReviewerPhotos = $reflection->getMethod('mergePublicReviewerPhotos');
$mergePublicReviewerPhotos->setAccessible(true);
$normalizeReviewerPhoto = $reflection->getMethod('normalizeReviewerPhoto');
$normalizeReviewerPhoto->setAccessible(true);
assertApiValue(
    ['token' => 'standard-token', 'expires_in' => 7200],
    $extractTokenData->invoke($client, [
        'access_token' => 'standard-token',
        'token_type' => 'Bearer',
        'expires_in' => 7200,
    ]),
    'A standard OAuth 2.0 token response must be accepted without a success field.'
);

$mergedProfile = $mergePublicReviewerPhotos->invoke($client, [
    ['id' => 'reviewer-visibility', 'name' => 'Visible Reviewer'],
], [
    ['_id' => 'reviewer-visibility', 'slug' => 'visible-reviewer-S9-123', 'profile_photo_url' => 'https://scholar9.test/images/reviewer.jpg'],
]);
assertApiValue(
    'visible-reviewer-S9-123',
    $mergedProfile[0]['slug'] ?? null,
    'Public reviewer profile slugs must be retained for reviewer-specific open-review links.'
);

assertApiValue(
    ['token' => 'wrapped-token', 'expires_in' => 86400],
    $extractTokenData->invoke($client, [
        'success' => true,
        'data' => [
            'access_token' => 'wrapped-token',
            'expires_in' => 86400,
        ],
    ]),
    'A Scholar9 data-wrapped token response must be accepted.'
);

assertApiValue(
    null,
    $extractTokenData->invoke($client, [
        'success' => false,
        'access_token' => 'must-not-be-used',
    ]),
    'An explicit OAuth failure must never be accepted.'
);

assertApiValue(
    null,
    $extractTokenData->invoke($client, ['success' => true]),
    'A response without an access token must be rejected.'
);

assertApiValue(
    [
        'paper_id' => 'paper-2',
        'reviewer_id' => 'reviewer-9',
        'text' => 'Safe message',
        'source' => 'publisher',
        'sender_name' => 'Editor',
    ],
    $messagePayload->invoke($client, 'paper-2', 'reviewer-9', 'Safe message', [
        'paper_id' => 'attacker-paper',
        'reviewer_id' => 'attacker-reviewer',
        'text' => '<script>alert(1)</script>',
        'source' => 'reviewer',
        'sender_name' => 'Editor',
        'attachment_url' => 'javascript:alert(1)',
    ]),
    'Message extras must not override routing, authorship, or text fields.'
);

assertApiValue(
    'The OJS server could not reach Scholar9 before the connection timeout.',
    $connectionErrorMessage->invoke($client, CURLE_OPERATION_TIMEDOUT, 'Connection timed out after 10000 milliseconds'),
    'Connection timeouts must be converted to stable, actionable diagnostics.'
);

assertApiValue(
    'The OJS server could not resolve the configured Scholar9 host name.',
    $connectionErrorMessage->invoke($client, CURLE_COULDNT_RESOLVE_HOST, 'Could not resolve host'),
    'DNS failures must identify the configured Scholar9 host as the problem.'
);

assertApiValue(
    'The OJS server could not establish a connection to the configured Scholar9 endpoint.',
    $connectionErrorMessage->invoke($client, CURLE_COULDNT_CONNECT, 'Failed to connect'),
    'Connection failures must not expose unstable low-level cURL wording.'
);

$retryAfterProperty = $reflection->getProperty('retryAfterSeconds');
$retryAfterProperty->setAccessible(true);
$retryAfterProperty->setValue($client, 75);
assertApiValue(
    'Scholar9 rate limit reached. Try again in 75 seconds.',
    $rateLimitMessage->invoke($client),
    'Rate-limit responses must expose the safe Retry-After delay when Scholar9 supplies one.'
);

$lastHttpStatusProperty = $reflection->getProperty('lastHttpStatus');
$lastHttpStatusProperty->setAccessible(true);
$lastHttpStatusProperty->setValue($client, 429);
assertApiValue(
    75,
    $client->getRetryAfterSeconds(),
    'The public Retry-After getter must surface the seconds captured from a 429 response.'
);
$lastHttpStatusProperty->setValue($client, 502);
assertApiValue(
    null,
    $client->getRetryAfterSeconds(),
    'A Retry-After value left over from an earlier 429 must not be reused for an unrelated failure.'
);
assertApiValue(
    $responseCacheKey->invoke($client, 'GET', 'https://scholar9.example.test/reviewers/search', ['pool' => 'all', 'q' => 'Darshan']),
    $responseCacheKey->invoke($client, 'GET', 'https://scholar9.example.test/reviewers/search', ['q' => 'Darshan', 'pool' => 'all']),
    'Equivalent reviewer queries must share one cache entry regardless of parameter order.'
);

assertApiValue(
    [
        'owner_name' => 'Journal Manager',
        'owner_email' => 'manager@example.test',
        'org_name' => 'Example University',
        'journal_id' => 'ojs_stable_id',
        'use_case' => 'Transparent peer review',
        'api_slug' => 'example_journal',
        'journal' => ['name' => 'Example Journal'],
        'source_system' => 'ojs',
        'source_url' => 'https://ojs.example.test/index.php/example',
        'source_context_id' => '17',
        'ojs_version' => '3.5.0',
    ],
    $registrationPayload->invoke($client, [
        'owner_name' => 'Journal Manager',
        'owner_email' => 'manager@example.test',
        'org_name' => 'Example University',
        'journal_id' => 'ojs_stable_id',
        'use_case' => 'Transparent peer review',
        'api_slug' => 'example_journal',
        'plan_id' => '',
        'journal' => ['name' => 'Example Journal'],
        'source_system' => 'ojs',
        'source_url' => 'https://ojs.example.test/index.php/example',
        'source_context_id' => '17',
        'ojs_version' => '3.5.0',
        'attacker_field' => 'must not be sent',
    ]),
    'API-access registration must include the reviewed journal profile and only allowlisted integration fields.'
);

assertApiValue(
    'journal-2',
    ($findConnectedJournal->invoke($client, [
        ['id' => 'journal-1', 'name' => 'Another Journal', 'e_issn' => '1111-1111'],
        ['id' => 'journal-2', 'name' => 'Example Journal', 'e_issn' => '2222-2222'],
    ], [
        'journal' => ['name' => 'Example Journal', 'online_issn' => '2222-2222'],
    ])['id'] ?? null),
    'Authenticated connection verification must identify the current OJS journal without creating a duplicate.'
);

assertApiValue(
    'https://scholar9.example.test/uploads/darshan.webp',
    ($mergePublicReviewerPhotos->invoke($client, [
        ['id' => 'reviewer-9', 'name' => 'Darshan Patel', 'photo_url' => null],
    ], [
        ['_id' => 'someone-else', 'photo' => 'https://scholar9.example.test/uploads/wrong.webp'],
        ['_id' => 'reviewer-9', 'photo' => 'https://scholar9.example.test/uploads/darshan.webp'],
    ])[0]['photo_url'] ?? null),
    'Public Scholar9 profile photos must be merged only by the stable reviewer id.'
);
assertApiValue(
    'https://scholar9.example.test/uploads/current.webp',
    ($normalizeReviewerPhoto->invoke($client, [
        'id' => 'reviewer-9',
        'profile_photo_url' => 'https://scholar9.example.test/uploads/current.webp',
    ])['photo_url'] ?? null),
    'Compatible Scholar9 profile-photo aliases must be normalized without changing the remote API.'
);
assertApiValue(
    'https://scholar9.example.test/uploads/id-alias.webp',
    ($mergePublicReviewerPhotos->invoke($client, [
        ['id' => 'reviewer-10', 'name' => 'Reviewer Ten'],
    ], [
        ['id' => 'reviewer-10', 'avatar_url' => 'https://scholar9.example.test/uploads/id-alias.webp'],
    ])[0]['photo_url'] ?? null),
    'Public researcher id and avatar aliases must be accepted only after a stable-id match.'
);

fwrite(STDOUT, "Scholar9 API client tests passed.\n");
