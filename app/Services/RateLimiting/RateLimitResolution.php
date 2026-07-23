<?php

namespace App\Services\RateLimiting;

readonly class RateLimitResolution
{
    /**
     * @param  ResolvedLimit[]  $limits
     */
    public function __construct(
        public bool $exempt,
        public bool $missingOrg,
        public ?string $orgId,
        public ?string $tier,
        public array $limits,
    ) {}
}
