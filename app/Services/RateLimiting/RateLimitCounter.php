<?php

namespace App\Services\RateLimiting;

class RateLimitCounter
{
    /**
     * @var array<string, array{window_start: int, count: int}>
     */
    private array $store = [];

    public function attempt(ResolvedLimit $limit, ?int $now = null): RateLimitResult
    {
        $now ??= time();
        $windowStart = $this->windowStart($now, $limit->windowSeconds);
        $state = $this->store[$limit->key] ?? null;

        if ($state === null || $state['window_start'] !== $windowStart) {
            $state = [
                'window_start' => $windowStart,
                'count' => 0,
            ];
        }

        $state['count']++;
        $this->store[$limit->key] = $state;

        $retryAfterSeconds = ($windowStart + $limit->windowSeconds) - $now;
        $allowed = $state['count'] <= $limit->maxRequests;

        return new RateLimitResult(
            allowed: $allowed,
            key: $limit->key,
            type: $limit->type,
            name: $limit->name,
            maxRequests: $limit->maxRequests,
            currentCount: $state['count'],
            retryAfterSeconds: max(1, $retryAfterSeconds),
        );
    }

    public function reset(): void
    {
        $this->store = [];
    }

    public function entryCount(): int
    {
        return count($this->store);
    }

    private function windowStart(int $timestamp, int $windowSeconds): int
    {
        return intdiv($timestamp, $windowSeconds) * $windowSeconds;
    }
}
