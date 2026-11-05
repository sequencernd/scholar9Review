<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/Scholar9ResponseCache.php';

use APP\plugins\generic\scholar9Review\Scholar9ResponseCache;

function assertCacheValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$now = 1000;
$namespace = 'test-' . bin2hex(random_bytes(8));
$cache = new Scholar9ResponseCache($namespace, sys_get_temp_dir(), static function () use (&$now): int {
    return $now;
});
$other = new Scholar9ResponseCache($namespace . '-other', sys_get_temp_dir(), static function () use (&$now): int {
    return $now;
});
$key = 'GET|https://scholar9.example.test/api/journal/v1/review/paper-1';
$value = ['success' => true, 'data' => [['status' => 'under_review']]];

$cache->put($key, $value, 30);
assertCacheValue($value, $cache->getFresh($key), 'A newly cached response must be available to passive reads.');
assertCacheValue(null, $other->getFresh($key), 'Cache entries must remain isolated by OJS context and credential scope.');

$now = 1031;
assertCacheValue(null, $cache->getFresh($key), 'Expired responses must not satisfy a normal cached read.');
assertCacheValue($value, $cache->getStale($key), 'Expired responses must remain available for transient rate-limit fallback.');

$now = 1931;
assertCacheValue(null, $cache->getStale($key), 'A stale response must never be reused beyond the bounded fallback window.');

$cache->forget($key);
assertCacheValue(null, $cache->getStale($key), 'Invalidation after writes and webhooks must remove the response snapshot.');

fwrite(STDOUT, "Scholar9 response cache tests passed.\n");
