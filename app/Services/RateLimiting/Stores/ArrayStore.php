<?php

namespace App\Services\RateLimiting\Stores;

/**
 * In-process counter store backed by a PHP array.
 *
 * IMPORTANT: state lives and dies with this object. Under PHP-FPM or `artisan serve` the
 * service container is rebuilt for every request, so a container-bound instance of this class
 * starts empty on every request and enforces nothing. That is the exact bug this store is kept
 * around to document.
 *
 * Legitimate uses:
 *   - unit and feature tests, where a single process handles the whole scenario and determinism
 *     matters more than cross-request persistence
 *   - a persistent-worker runtime (Laravel Octane, RoadRunner, FrankenPHP) where the container
 *     genuinely does outlive the request
 *
 * For a normal request-per-process deployment, use SharedMemoryStore.
 */
final class ArrayStore implements CounterStore
{
    /**
     * @var array<string, array{count: int, expires_at: int}>
     */
    private array $entries = [];

    public function increment(string $key, int $windowStart, int $ttlSeconds, int $now): int
    {
        $this->pruneExpired($now);

        $slot = $key.'@'.$windowStart;
        $entry = $this->entries[$slot] ?? [
            'count' => 0,
            'expires_at' => $windowStart + $ttlSeconds,
        ];

        $entry['count']++;
        $this->entries[$slot] = $entry;

        return $entry['count'];
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    private function pruneExpired(int $now): void
    {
        foreach ($this->entries as $slot => $entry) {
            if ($entry['expires_at'] <= $now) {
                unset($this->entries[$slot]);
            }
        }
    }
}
