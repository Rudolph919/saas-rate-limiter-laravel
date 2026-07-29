<?php

namespace App\Services\RateLimiting;

use App\Services\RateLimiting\Stores\CounterStore;

/**
 * Fixed-window rate limit accounting.
 *
 * Window boundaries are aligned to the epoch (intdiv(timestamp, windowSeconds) * windowSeconds)
 * so every process agrees on where a window starts without coordinating. The known trade-off is
 * boundary burst: a client can spend its full quota at the end of one window and again at the
 * start of the next, so the real worst case is 2x the configured limit over a short span. A
 * sliding window counter is the fix; it is deliberately out of scope here.
 *
 * Where the counts actually live is the store's problem, not this class's — see CounterStore.
 */
class RateLimitCounter
{
    public function __construct(private readonly CounterStore $store) {}

    public function attempt(ResolvedLimit $limit, ?int $now = null): RateLimitResult
    {
        $now ??= time();
        $windowStart = $this->windowStart($now, $limit->windowSeconds);

        $count = $this->store->increment(
            key: $limit->key,
            windowStart: $windowStart,
            ttlSeconds: $limit->windowSeconds,
            now: $now,
        );

        $retryAfterSeconds = ($windowStart + $limit->windowSeconds) - $now;

        return new RateLimitResult(
            allowed: $count <= $limit->maxRequests,
            key: $limit->key,
            type: $limit->type,
            name: $limit->name,
            maxRequests: $limit->maxRequests,
            currentCount: $count,
            retryAfterSeconds: max(1, $retryAfterSeconds),
        );
    }

    public function reset(): void
    {
        $this->store->reset();
    }

    public function entryCount(): int
    {
        return $this->store->entryCount();
    }

    private function windowStart(int $timestamp, int $windowSeconds): int
    {
        return intdiv($timestamp, $windowSeconds) * $windowSeconds;
    }
}
