<?php

namespace APP\plugins\generic\scholar9Review;

require_once __DIR__ . '/Scholar9ResponseCache.php';

use PKP\plugins\GenericPlugin;

class Scholar9ApiClient
{
    public const DEFAULT_BASE_URL = 'https://scholar9.com';
    public const MAX_MESSAGE_LENGTH = 5000;
    private const CACHE_STATUS_TTL = 45;
    private const CACHE_MESSAGES_TTL = 30;
    private const CACHE_RATINGS_TTL = 120;
    private const CACHE_REVIEWERS_TTL = 300;
    private const CACHE_PARAMETERS_TTL = 3600;
    private const CACHE_PUBLIC_PROFILE_TTL = 600;

    private GenericPlugin $plugin;
    private int $contextId;
    private ?string $journalSlug = null;
    private bool $retriedAfterUnauthorized = false;
    private ?string $lastError = null;
    private int $lastHttpStatus = 0;
    private int $lastCurlErrno = 0;
    private ?int $retryAfterSeconds = null;
    private ?Scholar9ResponseCache $responseCache = null;

    public function __construct(GenericPlugin $plugin, int $contextId)
    {
        $this->plugin    = $plugin;
        $this->contextId = $contextId;
    }

    private function cache(): Scholar9ResponseCache
    {
        if ($this->responseCache === null) {
            $this->responseCache = new Scholar9ResponseCache(\implode('|', [
                (string) $this->contextId,
                $this->origin(),
                (string) ($this->journalSlug ?? $this->plugin->getSetting($this->contextId, 'journalSlug') ?? ''),
                (string) ($this->plugin->getSetting($this->contextId, 'clientId') ?? ''),
            ]));
        }
        return $this->responseCache;
    }

    // ── Journal scoping ─────────────────────────────────────────────────────

    public function forJournal(string $slug): static
    {
        $clone = clone $this;
        $clone->journalSlug = $slug;
        $clone->responseCache = null;
        return $clone;
    }

    // ── Configuration ───────────────────────────────────────────────────────

    public function isConfigured(): bool
    {
        return $this->hasBaseUrl()
            && !empty($this->plugin->getSetting($this->contextId, 'clientId'))
            && !empty($this->plugin->getSetting($this->contextId, 'clientSecret'))
            && !empty($this->plugin->getSetting($this->contextId, 'journalSlug'));
    }

    public function hasBaseUrl(): bool
    {
        return $this->effectiveBaseUrl() !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /** Seconds Scholar9 asked the caller to wait, from the last 429 response's Retry-After header. */
    public function getRetryAfterSeconds(): ?int
    {
        return $this->lastHttpStatus === 429 ? $this->retryAfterSeconds : null;
    }

    // ── URL helpers ─────────────────────────────────────────────────────────

    private function effectiveBaseUrl(): string
    {
        $configured = \trim((string) ($this->plugin->getSetting($this->contextId, 'baseUrl') ?? ''));
        return \rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    private function origin(): string
    {
        $url    = $this->effectiveBaseUrl();
        $parsed = \parse_url($url);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'scholar9.com');
        if (!empty($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin;
    }

    /** Public Scholar9 origin, without API paths or credentials. */
    public function publicOrigin(): string
    {
        return $this->origin();
    }

    public function openReviewUrl(string $paperId, string $reviewerSlug): ?string
    {
        $paperId = \trim($paperId);
        $reviewerSlug = \trim($reviewerSlug);
        return $paperId !== '' && $reviewerSlug !== ''
            ? $this->origin() . '/open-review/' . \rawurlencode($paperId) . '/' . \rawurlencode($reviewerSlug)
            : null;
    }

    public function fullReviewUrl(string $paperId): ?string
    {
        $paperId = \trim($paperId);
        return $paperId !== '' ? $this->origin() . '/open-paper-link/' . \rawurlencode($paperId) : null;
    }

    private function baseUrl(string $path = ''): string
    {
        $slug = $this->journalSlug ?? (string) ($this->plugin->getSetting($this->contextId, 'journalSlug') ?? '');
        $base = $slug
            ? $this->origin() . '/api/' . $slug . '/v1'
            : $this->effectiveBaseUrl();

        return $base . ($path ? '/' . \ltrim($path, '/') : '');
    }

    private function oauthUrl(): string
    {
        return $this->origin() . '/api/ext/oauth/token';
    }

    // ── Token management ────────────────────────────────────────────────────

    private function token(): ?string
    {
        $cacheFile = $this->tokenCacheFile();

        if (\file_exists($cacheFile)) {
            $cached = \json_decode((string) @\file_get_contents($cacheFile), true);
            if (!empty($cached['token']) && !empty($cached['expires']) && \time() < (int) $cached['expires']) {
                return $cached['token'];
            }
        }

        $tokenData = $this->fetchToken();
        if ($tokenData) {
            $expiresIn = \max(60, (int) ($tokenData['expires_in'] ?? 3600));
            $clockSkew = \min(60, \max(5, (int) \floor($expiresIn / 10)));
            $written = @\file_put_contents(
                $cacheFile,
                \json_encode([
                    'token' => $tokenData['token'],
                    'expires' => \time() + $expiresIn - $clockSkew,
                ], JSON_THROW_ON_ERROR),
                LOCK_EX
            );
            if ($written !== false) {
                // On shared Unix hosts, keep bearer tokens readable only by
                // the web-server account. chmod is harmlessly ignored on
                // Windows, where the ACL is inherited from the temp folder.
                @\chmod($cacheFile, 0600);
            }
            return $tokenData['token'];
        }
        return null;
    }

    /**
     * @return array{token: string, expires_in: int}|null
     */
    private function fetchToken(): ?array
    {
        $response = $this->curl('POST', $this->oauthUrl(), [
            'grant_type'    => 'client_credentials',
            'client_id'     => (string) ($this->plugin->getSetting($this->contextId, 'clientId') ?? ''),
            'client_secret' => (string) ($this->plugin->getSetting($this->contextId, 'clientSecret') ?? ''),
        ], false);

        if ($response) {
            $tokenData = $this->extractTokenData($response);
            if ($tokenData !== null) {
                return $tokenData;
            }

            $remoteError = $response['error_description']
                ?? $response['message']
                ?? (\is_string($response['error'] ?? null) ? $response['error'] : null)
                ?? ($response['error']['message'] ?? null);
            $this->lastError = \is_string($remoteError) && \trim($remoteError) !== ''
                ? \trim($remoteError)
                : 'Scholar9 OAuth response did not contain an access token';
        } elseif ($this->lastError === null) {
            $this->lastError = 'Scholar9 OAuth token request failed';
        }

        // Never log the OAuth response because a non-standard response may
        // contain an access token even when it cannot be parsed.
        \error_log('[Scholar9] Failed to obtain OAuth token: ' . $this->lastError);
        return null;
    }

    /**
     * Accept both the OAuth 2.0 standard response and Scholar9's optional
     * success/data wrapper without weakening explicit failure handling.
     *
     * @return array{token: string, expires_in: int}|null
     */
    private function extractTokenData(array $response): ?array
    {
        if (\array_key_exists('success', $response) && $response['success'] === false) {
            return null;
        }

        $payload = \is_array($response['data'] ?? null) ? $response['data'] : $response;
        $accessToken = \trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            return null;
        }

        return [
            'token' => $accessToken,
            'expires_in' => \max(60, (int) ($payload['expires_in'] ?? 3600)),
        ];
    }

    private function tokenCacheFile(): string
    {
        $slug = $this->journalSlug
            ?? (string) ($this->plugin->getSetting($this->contextId, 'journalSlug') ?? '');
        $clientId = (string) ($this->plugin->getSetting($this->contextId, 'clientId') ?? '');
        $clientSecret = (string) ($this->plugin->getSetting($this->contextId, 'clientSecret') ?? '');
        $identity = \implode('|', [
            (string) $this->contextId,
            $slug,
            $this->origin(),
            $clientId,
            \hash('sha256', $clientSecret),
        ]);

        return \rtrim(\sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'scholar9_token_'
            . \hash('sha256', $identity)
            . '.json';
    }

    private function forgetToken(): void
    {
        $cacheFile = $this->tokenCacheFile();
        if (\file_exists($cacheFile)) {
            @\unlink($cacheFile);
        }
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    private function curl(
        string $method,
        string $url,
        array $data = [],
        bool $auth = true,
        bool $acceptDuplicateRegistration = false
    ): ?array
    {
        $this->lastError = null;
        $this->lastHttpStatus = 0;
        $this->lastCurlErrno = 0;
        $this->retryAfterSeconds = null;
        $token = $auth ? $this->token() : null;
        if ($auth && !$token) {
            $this->lastError ??= 'Unable to authenticate with Scholar9';
            return null;
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = \curl_init();
        $scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));
        $allowedProtocol = $scheme === 'http' ? \CURLPROTO_HTTP : \CURLPROTO_HTTPS;

        \curl_setopt_array($ch, [
            \CURLOPT_URL            => $url,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 60,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_HTTPHEADER     => $headers,
            \CURLOPT_SSL_VERIFYPEER => (bool) ($this->plugin->getSetting($this->contextId, 'verifySsl') ?? true),
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS      => $allowedProtocol,
            \CURLOPT_HEADERFUNCTION => function ($curl, string $header): int {
                if (\stripos($header, 'Retry-After:') === 0) {
                    $value = \trim(\substr($header, \strlen('Retry-After:')));
                    if (\ctype_digit($value)) {
                        $this->retryAfterSeconds = \max(1, (int) $value);
                    } else {
                        $timestamp = \strtotime($value);
                        if ($timestamp !== false) {
                            $this->retryAfterSeconds = \max(1, $timestamp - \time());
                        }
                    }
                }
                return \strlen($header);
            },
        ]);

        $method = \strtoupper($method);
        if ($method === 'GET') {
            \curl_setopt($ch, \CURLOPT_URL, $data ? $url . '?' . \http_build_query($data) : $url);
        } elseif ($method === 'POST') {
            \curl_setopt($ch, \CURLOPT_POST, true);
            \curl_setopt($ch, \CURLOPT_POSTFIELDS, \json_encode($data));
        } elseif ($method === 'PUT') {
            \curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'PUT');
            \curl_setopt($ch, \CURLOPT_POSTFIELDS, \json_encode($data));
        } elseif ($method === 'DELETE') {
            \curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $body   = \curl_exec($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error  = \curl_error($ch);
        $errno  = \curl_errno($ch);
        $this->lastHttpStatus = $status;
        $this->lastCurlErrno = $errno;
        \curl_close($ch);

        if ($status === 401 && $auth && !$this->retriedAfterUnauthorized) {
            $this->retriedAfterUnauthorized = true;
            $this->forgetToken();
            $response = $this->curl($method, $url, $data, true, $acceptDuplicateRegistration);
            $this->retriedAfterUnauthorized = false;
            return $response;
        }

        if ($body === false || $status < 200 || $status >= 300) {
            $decodedError = \json_decode((string) $body, true);
            if (
                $acceptDuplicateRegistration
                && $status === 409
                && \is_array($decodedError)
                && (string) ($decodedError['error']['code'] ?? '') === 'DUPLICATE'
            ) {
                // Scholar9's existing registration API intentionally rejects a
                // second access request. Treat that idempotency response as a
                // successful no-op so an OJS manager can safely retry Sync.
                return [
                    'success' => true,
                    'data' => [
                        'duplicate' => true,
                        'api_slug' => (string) ($data['api_slug'] ?? ''),
                        'message' => (string) ($decodedError['error']['message'] ?? 'An API access request already exists.'),
                    ],
                ];
            }
            $remoteError = $status === 429
                ? $this->rateLimitMessage()
                : ($decodedError['error']['message']
                ?? $decodedError['message']
                ?? (\is_string($decodedError['error'] ?? null) ? $decodedError['error'] : null)
                ?? $this->connectionErrorMessage($errno, $error)
                ?: 'Remote request failed');
            $this->lastError = (string) $remoteError;
            \error_log('[Scholar9] HTTP request failed (' . $status . '): ' . $this->lastError);
            return null;
        }

        $decoded = \json_decode((string) $body, true);
        if (!\is_array($decoded)) {
            $this->lastError = 'Scholar9 returned invalid JSON';
            return null;
        }
        return $decoded;
    }

    /**
     * Convert low-level cURL failures into safe, actionable messages. Raw
     * transport errors vary by host and previously surfaced as browser alerts.
     */
    private function connectionErrorMessage(int $errno, string $error): string
    {
        if ($errno === \CURLE_OPERATION_TIMEDOUT) {
            return 'The OJS server could not reach Scholar9 before the connection timeout.';
        }

        if ($errno === \CURLE_COULDNT_RESOLVE_HOST) {
            return 'The OJS server could not resolve the configured Scholar9 host name.';
        }

        if ($errno === \CURLE_COULDNT_CONNECT) {
            return 'The OJS server could not establish a connection to the configured Scholar9 endpoint.';
        }

        $message = \trim($error);
        return $message !== '' ? $message : 'Remote request failed';
    }

    private function rateLimitMessage(): string
    {
        return $this->retryAfterSeconds !== null
            ? 'Scholar9 rate limit reached. Try again in ' . $this->retryAfterSeconds . ' seconds.'
            : 'Scholar9 rate limit reached. Please wait before trying again.';
    }

    private function request(string $method, string $path, array $data = []): ?array
    {
        return $this->curl($method, $this->baseUrl($path), $data);
    }

    private function cachedRequest(
        string $method,
        string $path,
        array $data,
        int $ttlSeconds,
        bool $force = false
    ): ?array {
        return $this->cachedCurl(
            $method,
            $this->baseUrl($path),
            $data,
            $ttlSeconds,
            $force,
            true
        );
    }

    private function cachedCurl(
        string $method,
        string $url,
        array $data,
        int $ttlSeconds,
        bool $force,
        bool $auth
    ): ?array {
        $key = $this->responseCacheKey($method, $url, $data);
        if (!$force) {
            $fresh = $this->cache()->getFresh($key);
            if ($fresh !== null) {
                $this->lastError = null;
                return $fresh;
            }
        }

        $response = $this->curl($method, $url, $data, $auth);
        if ($response !== null && ($response['success'] ?? true) !== false) {
            $this->cache()->put($key, $response, $ttlSeconds);
            return $response;
        }

        if (!$force && $this->isTransientFailure()) {
            $stale = $this->cache()->getStale($key);
            if ($stale !== null) {
                $this->lastError = null;
                return $stale;
            }
        }
        return $response;
    }

    private function responseCacheKey(string $method, string $url, array $data = []): string
    {
        $this->sortCacheData($data);
        return \strtoupper($method) . '|' . $url . '|' . \hash('sha256', (string) \json_encode($data));
    }

    private function sortCacheData(array &$data): void
    {
        \ksort($data);
        foreach ($data as &$value) {
            if (\is_array($value)) {
                $this->sortCacheData($value);
            }
        }
        unset($value);
    }

    private function isTransientFailure(): bool
    {
        return $this->lastCurlErrno !== 0
            || $this->lastHttpStatus === 429
            || $this->lastHttpStatus >= 500;
    }

    /** Submit an idempotent API-access request without OAuth credentials. */
    public function requestApiAccess(array $journalData): ?array
    {
        return $this->curl(
            'POST',
            $this->origin() . '/api/ext/register',
            $this->registrationPayload($journalData),
            false,
            true
        );
    }

    /** Update the already-provisioned journal linked to this API client. */
    public function syncJournal(array $journalData): ?array
    {
        return $this->request('PUT', 'journals/sync', $this->registrationPayload($journalData));
    }

    /** Perform a read-only credential and journal-scope check. */
    public function verifyJournalConnection(array $journalData): ?array
    {
        $response = $this->request('GET', 'journals');
        if (!$response || ($response['success'] ?? true) === false) {
            return null;
        }

        $journals = $response['data'] ?? [];
        if (\is_array($journals) && isset($journals['journals']) && \is_array($journals['journals'])) {
            $journals = $journals['journals'];
        }
        if (!\is_array($journals)) {
            $this->lastError = 'Scholar9 returned an invalid journal list.';
            return null;
        }

        $connected = $this->findConnectedJournal($journals, $journalData);
        if (!$connected) {
            $this->lastError = 'The configured Scholar9 credentials are not linked to this OJS journal.';
            return null;
        }

        return ['success' => true, 'data' => [
            'verified' => true,
            'journal_count' => \count($journals),
            'journal' => $connected,
        ]];
    }

    /** @return array<string, mixed> */
    private function registrationPayload(array $journalData): array
    {
        $payload = \array_intersect_key($journalData, \array_flip([
            'owner_name',
            'owner_email',
            'org_name',
            'journal_id',
            'use_case',
            'plan_id',
            'api_slug',
            'source_system',
            'source_url',
            'source_context_id',
            'ojs_version',
            'journal',
        ]));

        if (($payload['plan_id'] ?? '') === '') {
            unset($payload['plan_id']);
        }
        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function findConnectedJournal(array $journals, array $journalData): ?array
    {
        $profile = \is_array($journalData['journal'] ?? null) ? $journalData['journal'] : [];
        $needles = \array_values(\array_filter(\array_map(
            static fn(mixed $value): string => \mb_strtolower(\trim((string) $value)),
            [
                $profile['name'] ?? '',
                $profile['short_name'] ?? '',
                $profile['online_issn'] ?? '',
                $profile['print_issn'] ?? '',
            ]
        )));

        foreach ($journals as $journal) {
            if (!\is_array($journal)) {
                continue;
            }
            $haystack = \array_map(
                static fn(mixed $value): string => \mb_strtolower(\trim((string) $value)),
                [
                    $journal['name'] ?? '',
                    $journal['short_name'] ?? '',
                    $journal['e_issn'] ?? '',
                    $journal['p_issn'] ?? '',
                ]
            );
            if ($needles && \array_intersect($needles, $haystack)) {
                return $journal;
            }
        }

        return null;
    }

    // ── Reviewer API ────────────────────────────────────────────────────────

    public function searchReviewers(string $query, string $pool = 'all', bool $force = false): ?array
    {
        $response = $this->cachedRequest(
            'GET',
            'reviewers/search',
            ['q' => $query, 'pool' => $pool],
            self::CACHE_REVIEWERS_TTL,
            $force
        );
        if ($response && \is_array($response['data'] ?? null)) {
            $response['data'] = \array_map(
                fn(mixed $reviewer): mixed => \is_array($reviewer) ? $this->normalizeReviewerPhoto($reviewer) : $reviewer,
                $response['data']
            );
            // Enrich from Scholar9's existing author directory. Unlike the
            // global header search, this endpoint does not depend on the
            // publication text index and exposes the canonical profile photo.
            $response['data'] = $this->mergePublicReviewerPhotos(
                $response['data'],
                $this->profileDirectoryResearchers($query, $force)
            );
            // Retain compatibility with older Scholar9 installations where
            // only header search exposes profile images, but do not call that
            // heavier endpoint after every reviewer already has a photo.
            if ($this->hasMissingReviewerPhoto($response['data'])) {
                $response['data'] = $this->mergePublicReviewerPhotos(
                    $response['data'],
                    $this->publicResearchers($query, $force)
                );
            }
        }
        return $response;
    }

    public function getReviewer(string $reviewerId, bool $force = false): ?array
    {
        $response = $this->cachedRequest(
            'GET',
            'reviewers/' . \rawurlencode($reviewerId),
            [],
            self::CACHE_REVIEWERS_TTL,
            $force
        );
        if (!$response || !\is_array($response['data'] ?? null)) {
            return $response;
        }

        $reviewer = $this->normalizeReviewerPhoto($response['data']);
        $name = \trim((string) ($reviewer['name'] ?? ''));
        if ($name !== '') {
            $merged = $this->mergePublicReviewerPhotos([$reviewer], $this->profileDirectoryResearchers($name, $force));
            if ($this->hasMissingReviewerPhoto($merged)) {
                $merged = $this->mergePublicReviewerPhotos($merged, $this->publicResearchers($name, $force));
            }
            $reviewer = $merged[0] ?? $reviewer;
        }
        $response['data'] = $reviewer;
        return $response;
    }

    private function findPublicReviewerPhoto(string $reviewerId, string $name, bool $force = false): ?string
    {
        $name = \trim($name);
        if ($name === '') {
            return null;
        }

        // Scholar9's existing public header search exposes the current profile
        // photo and stable user id. Match by id, never by display name alone.
        $researchers = $this->publicResearchers($name, $force);
        foreach ($researchers as $researcher) {
            if (!\is_array($researcher) || (string) ($researcher['_id'] ?? $researcher['id'] ?? '') !== $reviewerId) {
                continue;
            }
            return $this->reviewerPhotoValue($researcher);
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function publicResearchers(string $query, bool $force = false): array
    {
        $response = $this->cachedCurl(
            'POST',
            $this->origin() . '/api/headerSearchbar',
            ['search' => $query],
            self::CACHE_PUBLIC_PROFILE_TTL,
            $force,
            false
        );
        return \is_array($response['data']['researchers'] ?? null)
            ? $response['data']['researchers']
            : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function profileDirectoryResearchers(string $query, bool $force = false): array
    {
        $response = $this->cachedCurl(
            'POST',
            $this->origin() . '/api/search_author',
            ['author_name' => $query],
            self::CACHE_PUBLIC_PROFILE_TTL,
            $force,
            false
        );

        $rows = \is_array($response['data'] ?? null)
            ? $response['data']
            : (\is_array($response) ? $response : []);
        return \array_values(\array_map(
            static function (array $row): array {
                if (!isset($row['id']) && isset($row['value'])) {
                    $row['id'] = $row['value'];
                }
                if (!isset($row['name']) && isset($row['label'])) {
                    $row['name'] = $row['label'];
                }
                if (!isset($row['photo_url']) && isset($row['url'])) {
                    $row['photo_url'] = $row['url'];
                }
                return $row;
            },
            \array_values(\array_filter($rows, 'is_array'))
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function mergePublicReviewerPhotos(array $reviewers, array $researchers): array
    {
        $profilesById = [];
        foreach ($researchers as $researcher) {
            if (!\is_array($researcher)) {
                continue;
            }
            $id = (string) ($researcher['_id'] ?? $researcher['id'] ?? '');
            $photo = $this->reviewerPhotoValue($researcher);
            if ($id !== '') {
                $profilesById[$id] = [
                    'photo_url' => $photo,
                    'slug' => \trim((string) ($researcher['slug'] ?? $researcher['profile_slug'] ?? $researcher['username'] ?? '')),
                ];
            }
        }

        foreach ($reviewers as &$reviewer) {
            if (!\is_array($reviewer)) {
                continue;
            }
            $id = (string) ($reviewer['id'] ?? $reviewer['_id'] ?? '');
            if (!isset($profilesById[$id])) {
                continue;
            }
            if (\trim((string) ($reviewer['photo_url'] ?? '')) === '' && $profilesById[$id]['photo_url'] !== null) {
                $reviewer['photo_url'] = $profilesById[$id]['photo_url'];
            }
            if (\trim((string) ($reviewer['slug'] ?? '')) === '' && $profilesById[$id]['slug'] !== '') {
                $reviewer['slug'] = $profilesById[$id]['slug'];
            }
        }
        unset($reviewer);
        return $reviewers;
    }

    private function hasMissingReviewerPhoto(array $reviewers): bool
    {
        foreach ($reviewers as $reviewer) {
            if (\is_array($reviewer) && $this->reviewerPhotoValue($reviewer) === null) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function normalizeReviewerPhoto(array $reviewer): array
    {
        $reviewer['photo_url'] = $this->reviewerPhotoValue($reviewer);
        return $reviewer;
    }

    private function reviewerPhotoValue(array $reviewer): ?string
    {
        foreach (['photo_url', 'profile_photo_url', 'profile_photo', 'profile_image', 'avatar_url', 'avatar', 'photo', 'image'] as $key) {
            $url = $this->absoluteHttpUrl((string) ($reviewer[$key] ?? ''));
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private function absoluteHttpUrl(string $value): ?string
    {
        $value = \trim($value);
        if ($value === '') {
            return null;
        }
        if (!\preg_match('#^https?://#i', $value)) {
            $value = $this->origin() . '/' . \ltrim($value, '/');
        }
        $scheme = \strtolower((string) \parse_url($value, PHP_URL_SCHEME));
        return \filter_var($value, FILTER_VALIDATE_URL) && \in_array($scheme, ['http', 'https'], true)
            ? $value
            : null;
    }

    public function getReviewParameters(bool $force = false): ?array
    {
        return $this->cachedRequest('GET', 'review/parameters', [], self::CACHE_PARAMETERS_TTL, $force);
    }

    // ── Review lifecycle ────────────────────────────────────────────────────

    public function createReview(array $data): ?array
    {
        $response = $this->request('POST', 'review/assign', $data);
        if ($response && ($response['success'] ?? true) !== false) {
            $paperId = (string) ($response['data']['paper_id'] ?? $data['paper_id'] ?? '');
            if ($paperId !== '') {
                $this->forgetReviewCaches($paperId);
            }
        }
        return $response;
    }

    public function assignReviewer(string $paperId, string $reviewerId): ?array
    {
        $response = $this->request('POST', 'review/assign', [
            'paper_id' => $paperId,
            'reviewer_id' => $reviewerId,
        ]);
        if ($response && ($response['success'] ?? true) !== false) {
            $this->forgetReviewCaches($paperId);
        }
        return $response;
    }

    /** Update visibility of one reviewer's public review page. */
    public function setOpenReviewStatus(string $paperId, string $reviewerId, bool $enabled): ?array
    {
        $response = $this->request('PUT', 'review/' . \rawurlencode($paperId) . '/visibility', [
            'reviewer_id' => $reviewerId,
            'open_review' => $enabled,
        ]);
        if ($response && ($response['success'] ?? true) !== false) {
            $this->forgetReviewCaches($paperId);
        }
        return $response;
    }

    /** Update visibility of the paper-wide public review page. */
    public function setFullReviewStatus(string $paperId, bool $enabled): ?array
    {
        $response = $this->request('PUT', 'review/' . \rawurlencode($paperId) . '/visibility', [
            'full_review' => $enabled,
        ]);
        if ($response && ($response['success'] ?? true) !== false) {
            $this->forgetReviewCaches($paperId);
        }
        return $response;
    }

    public function getReviewStatus(string $paperId, bool $force = false): ?array
    {
        return $this->cachedRequest(
            'GET',
            'review/' . \rawurlencode($paperId),
            [],
            self::CACHE_STATUS_TTL,
            $force
        );
    }

    // ── Messages ────────────────────────────────────────────────────────────

    public function getMessages(string $paperId, bool $force = false): ?array
    {
        return $this->cachedRequest(
            'GET',
            'messages/' . \rawurlencode($paperId),
            [],
            self::CACHE_MESSAGES_TTL,
            $force
        );
    }

    public function sendMessage(string $paperId, string $reviewerId, string $text, array $extra = []): ?array
    {
        $response = $this->request('POST', 'messages', $this->messagePayload($paperId, $reviewerId, $text, $extra));
        if ($response && ($response['success'] ?? true) !== false) {
            $this->forgetReviewCaches($paperId);
        }
        return $response;
    }

    private function messagePayload(string $paperId, string $reviewerId, string $text, array $extra = []): array
    {
        $allowedExtra = \array_intersect_key($extra, \array_flip([
            'sender_name',
            'sender_email',
            'comment_status',
            'review_decision',
            'rating_data',
        ]));

        // Core routing and authorship fields must never be overridden by a
        // caller-provided extras array.
        $payload = [
            'paper_id'    => $paperId,
            'reviewer_id' => $reviewerId,
            'text'        => $text,
            'source'      => 'publisher',
        ] + $allowedExtra;
        return $payload;
    }

    // ── Ratings ─────────────────────────────────────────────────────────────

    public function getRatings(string $paperId, bool $force = false): ?array
    {
        return $this->cachedRequest(
            'GET',
            'ratings/' . \rawurlencode($paperId),
            [],
            self::CACHE_RATINGS_TTL,
            $force
        );
    }

    /**
     * Submit the publisher's one-time assessment of a completed reviewer.
     *
     * This uses Scholar9's dedicated external ratings endpoint. Keeping this
     * separate from chat messages preserves the remote review lifecycle and
     * avoids overloading comment status codes used by other integrations.
     */
    public function submitReviewerRating(
        string $paperId,
        string $reviewerId,
        array $ratings,
        string $comment = ''
    ): ?array {
        $ratings = \array_values(\array_map('intval', $ratings));
        $response = $this->request('POST', 'ratings', [
            'paper_id' => $paperId,
            'reviewer_id' => $reviewerId,
            'ratings' => $ratings,
            'comment' => $comment,
        ]);
        if ($response && ($response['success'] ?? true) !== false) {
            $this->forgetReviewCaches($paperId);
        }
        return $response;
    }

    /** Invalidate only read caches related to one review after a write/webhook. */
    public function forgetReviewCaches(string $paperId): void
    {
        $paperId = \trim($paperId);
        if ($paperId === '') {
            return;
        }
        foreach ([
            'review/' . \rawurlencode($paperId),
            'messages/' . \rawurlencode($paperId),
            'ratings/' . \rawurlencode($paperId),
        ] as $path) {
            $this->cache()->forget($this->responseCacheKey('GET', $this->baseUrl($path), []));
        }
    }

    // ── Webhooks ────────────────────────────────────────────────────────────

    public function registerWebhook(string $url, array $events, string $secret): ?array
    {
        return $this->request('POST', 'webhooks', ['url' => $url, 'events' => $events, 'secret' => $secret]);
    }

    public function deleteWebhook(string $webhookId): ?array
    {
        return $this->request('DELETE', 'webhooks/' . \rawurlencode($webhookId));
    }

    // ── Security ────────────────────────────────────────────────────────────

    public function verifyWebhookSignature(string $payload, string $signature, ?string $secret = null): bool
    {
        $secret ??= (string) ($this->plugin->getSetting($this->contextId, 'webhookSecret') ?? '');
        if ($secret === '') {
            return false;
        }
        $signature = \trim($signature);
        $expected = \hash_hmac('sha256', $payload, $secret);
        if (\str_starts_with($signature, 'sha256=')) {
            $signature = \substr($signature, 7);
        }
        return \strlen($signature) === 64 && \hash_equals($expected, \strtolower($signature));
    }
}
