<?php

namespace App\Services\RateLimiting\Stores;

use RuntimeException;
use SysvSemaphore;
use SysvSharedMemory;

/**
 * Counter store backed by a System V shared memory segment, guarded by a System V semaphore.
 *
 * This is what makes the limiter actually work under PHP's share-nothing request model. The
 * segment is owned by the kernel, not by the PHP process or the Laravel container, so counters
 * survive from one request to the next and are shared across every PHP-FPM worker on the host.
 * No Redis, no database, no external cache — the assessment constraint holds.
 *
 * Why a semaphore: increment() is a read-modify-write over the whole map. Two FPM workers
 * hitting the same org at the same moment would otherwise both read count=N and both write
 * N+1, leaking one request per race. sem_acquire() serialises the critical section.
 *
 * Honest limitations (these are the trade-offs, not oversights):
 *   - The entire map is unserialised, mutated, and reserialised on every request. That is fine
 *     at PoC scale and is the main reason to move to Redis, where INCR touches one key.
 *   - The segment is a fixed size. When it fills, the store drops all counters and starts over
 *     rather than failing requests — bounded memory, at the cost of one forgiving window.
 *   - Shared memory is per host. Behind a load balancer with N hosts, an org's effective limit
 *     is N x the configured value. Only a shared store (Redis) fixes that.
 *   - The segment outlives the PHP process. Restarting the app no longer resets counters, which
 *     is a behaviour change from the array store — and the correct one for abuse prevention.
 */
final class SharedMemoryStore implements CounterStore
{
    /**
     * Index of the single variable held in the segment: the serialised counter map.
     */
    private const MAP_VARIABLE = 1;

    private ?SysvSharedMemory $memory = null;

    private ?SysvSemaphore $semaphore = null;

    public function __construct(
        private readonly int $systemVKey,
        private readonly int $segmentBytes,
    ) {}

    public function increment(string $key, int $windowStart, int $ttlSeconds, int $now): int
    {
        $semaphore = $this->semaphore();

        if (! sem_acquire($semaphore)) {
            throw new RuntimeException('Unable to acquire the rate limiter semaphore.');
        }

        try {
            $entries = $this->pruneExpired($this->read(), $now);

            $slot = $key.'@'.$windowStart;
            $entry = $entries[$slot] ?? [
                'count' => 0,
                'expires_at' => $windowStart + $ttlSeconds,
            ];

            $entry['count']++;
            $entries[$slot] = $entry;

            $this->write($entries, $slot, $entry);

            return $entry['count'];
        } finally {
            sem_release($semaphore);
        }
    }

    public function reset(): void
    {
        $memory = $this->memory();

        if (shm_has_var($memory, self::MAP_VARIABLE)) {
            shm_remove_var($memory, self::MAP_VARIABLE);
        }
    }

    public function entryCount(): int
    {
        return count($this->read());
    }

    /**
     * @return array<string, array{count: int, expires_at: int}>
     */
    private function read(): array
    {
        $memory = $this->memory();

        if (! shm_has_var($memory, self::MAP_VARIABLE)) {
            return [];
        }

        $entries = shm_get_var($memory, self::MAP_VARIABLE);

        return is_array($entries) ? $entries : [];
    }

    /**
     * Persist the map. If the segment is full, drop everything and keep only the entry being
     * written — memory stays bounded and the limiter keeps working, at the cost of forgiving
     * the counters that were evicted. Failing open silently is what allowed the original bug to
     * go unnoticed, so the recovery path is deliberately narrow and explicit.
     *
     * @param  array<string, array{count: int, expires_at: int}>  $entries
     * @param  array{count: int, expires_at: int}  $entry
     */
    private function write(array $entries, string $slot, array $entry): void
    {
        if (@shm_put_var($this->memory(), self::MAP_VARIABLE, $entries)) {
            return;
        }

        if (! @shm_put_var($this->memory(), self::MAP_VARIABLE, [$slot => $entry])) {
            throw new RuntimeException(
                'Rate limiter shared memory segment is too small to hold a single counter; '
                .'increase rate_limits.store.shared_memory.segment_bytes.'
            );
        }
    }

    /**
     * @param  array<string, array{count: int, expires_at: int}>  $entries
     * @return array<string, array{count: int, expires_at: int}>
     */
    private function pruneExpired(array $entries, int $now): array
    {
        foreach ($entries as $slot => $entry) {
            if ($entry['expires_at'] <= $now) {
                unset($entries[$slot]);
            }
        }

        return $entries;
    }

    private function memory(): SysvSharedMemory
    {
        if ($this->memory !== null) {
            return $this->memory;
        }

        $memory = @shm_attach($this->systemVKey, $this->segmentBytes, 0600);

        if ($memory === false) {
            throw new RuntimeException('Unable to attach the rate limiter shared memory segment.');
        }

        return $this->memory = $memory;
    }

    private function semaphore(): SysvSemaphore
    {
        if ($this->semaphore !== null) {
            return $this->semaphore;
        }

        $semaphore = @sem_get($this->systemVKey, 1, 0600, true);

        if ($semaphore === false) {
            throw new RuntimeException('Unable to obtain the rate limiter semaphore.');
        }

        return $this->semaphore = $semaphore;
    }
}
