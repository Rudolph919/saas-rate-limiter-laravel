<?php

namespace App\Services\RateLimiting;

readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public string $key,
        public string $type,
        public string $name,
        public int $maxRequests,
        public int $currentCount,
        public int $retryAfterSeconds,
    ) {}
}
