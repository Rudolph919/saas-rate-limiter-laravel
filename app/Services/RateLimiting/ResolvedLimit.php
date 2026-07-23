<?php

namespace App\Services\RateLimiting;

readonly class ResolvedLimit
{
    public function __construct(
        public string $key,
        public string $type,
        public string $name,
        public int $maxRequests,
        public int $windowSeconds,
    ) {}
}
