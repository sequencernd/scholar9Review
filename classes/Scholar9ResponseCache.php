<?php

namespace APP\plugins\generic\scholar9Review;

/**
 * Small, context-scoped cache for read-only Scholar9 API responses.
 *
 * Cache keys and namespaces are hashed before they reach the filesystem. The
 * cache never stores OAuth tokens or client secrets and can safely be shared
 * by PHP workers serving the same OJS installation.
 */
class Scholar9ResponseCache
{
    private const MAX_CACHE_BYTES = 4194304;
    private const MAX_STALE_SECONDS = 900;

    private string $namespace;
    private string $directory;
    /** @var callable */
    private $clock;

    public function __construct(string $namespace, ?string $directory = null, ?callable $clock = null)
    {
        $this->namespace = \hash('sha256', $namespace);
        $this->directory = $directory ?: \sys_get_temp_dir();
        $this->clock = $clock ?: static fn(): int => \time();
    }

    public function getFresh(string $key): ?array
    {
        $entry = $this->read($key);
        if ($entry === null || (int) $entry['expires_at'] <= $this->now()) {
            return null;
        }
        return $entry['value'];
    }

    public function getStale(string $key): ?array
    {
        $entry = $this->read($key);
        if ($entry === null) {
            return null;
        }
        if ((int) $entry['stale_until'] <= $this->now()) {
            $this->forget($key);
            return null;
        }
        return $entry['value'];
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $ttlSeconds = \max(1, $ttlSeconds);
        try {
            $expiresAt = $this->now() + $ttlSeconds;
            $encoded = \json_encode([
                'expires_at' => $expiresAt,
                'stale_until' => $expiresAt + self::MAX_STALE_SECONDS,
                'value' => $value,
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (\strlen($encoded) > self::MAX_CACHE_BYTES) {
            return;
        }

        $written = @\file_put_contents($this->file($key), $encoded, LOCK_EX);
        if ($written !== false) {
            @\chmod($this->file($key), 0600);
        }
    }

    public function forget(string $key): void
    {
        $file = $this->file($key);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    private function read(string $key): ?array
    {
        $file = $this->file($key);
        if (!\is_file($file)) {
            return null;
        }
        $size = @\filesize($file);
        if ($size === false || $size < 2 || $size > self::MAX_CACHE_BYTES) {
            return null;
        }
        $decoded = \json_decode((string) @\file_get_contents($file), true);
        if (!\is_array($decoded)
            || !\is_int($decoded['expires_at'] ?? null)
            || !\is_int($decoded['stale_until'] ?? null)
            || !\is_array($decoded['value'] ?? null)) {
            return null;
        }
        return $decoded;
    }

    private function file(string $key): string
    {
        return \rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR
            . 'scholar9_response_' . \hash('sha256', $this->namespace . '|' . $key) . '.json';
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
