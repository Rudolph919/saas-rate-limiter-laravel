<?php

namespace Tests\Unit\RateLimiting;

use App\Services\RateLimiting\RateLimitCounter;
use App\Services\RateLimiting\ResolvedLimit;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RateLimitCounterTest extends TestCase
{
    private RateLimitCounter $counter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->counter = new RateLimitCounter;
    }

    public function test_first_request_is_allowed(): void
    {
        $limit = $this->makeLimit(maxRequests: 100);

        $result = $this->counter->attempt($limit, now: 1_700_000_000);

        $this->assertTrue($result->allowed);
        $this->assertSame(1, $result->currentCount);
        $this->assertSame('per_client', $result->type);
        $this->assertSame('client:org_acme', $result->key);
    }

    public function test_request_at_max_limit_is_allowed(): void
    {
        $limit = $this->makeLimit(maxRequests: 3);
        $now = 1_700_000_000;

        for ($i = 0; $i < 2; $i++) {
            $this->counter->attempt($limit, now: $now);
        }

        $result = $this->counter->attempt($limit, now: $now);

        $this->assertTrue($result->allowed);
        $this->assertSame(3, $result->currentCount);
    }

    public function test_request_over_max_is_rejected(): void
    {
        $limit = $this->makeLimit(maxRequests: 3);
        $now = 1_700_000_000;

        for ($i = 0; $i < 3; $i++) {
            $this->counter->attempt($limit, now: $now);
        }

        $result = $this->counter->attempt($limit, now: $now);

        $this->assertFalse($result->allowed);
        $this->assertSame(4, $result->currentCount);
        $this->assertSame('standard', $result->name);
    }

    #[TestWith([1_700_000_039, 1])]
    #[TestWith([1_700_000_010, 30])]
    public function test_retry_after_is_seconds_until_window_ends(int $now, int $expectedRetryAfter): void
    {
        $limit = $this->makeLimit(maxRequests: 1, windowSeconds: 60);
        $windowStart = 1_699_999_980;

        $this->counter->attempt($limit, now: $windowStart);
        $result = $this->counter->attempt($limit, now: $now);

        $this->assertFalse($result->allowed);
        $this->assertSame($expectedRetryAfter, $result->retryAfterSeconds);
    }

    public function test_counter_resets_when_window_rolls_over(): void
    {
        $limit = $this->makeLimit(maxRequests: 2);
        $windowOne = 1_699_999_980;
        $windowTwo = 1_700_000_040;

        $this->counter->attempt($limit, now: $windowOne);
        $this->counter->attempt($limit, now: $windowOne);

        $rejected = $this->counter->attempt($limit, now: $windowOne);
        $this->assertFalse($rejected->allowed);

        $allowed = $this->counter->attempt($limit, now: $windowTwo);
        $this->assertTrue($allowed->allowed);
        $this->assertSame(1, $allowed->currentCount);
    }

    public function test_separate_keys_are_independent(): void
    {
        $clientLimit = $this->makeLimit(key: 'client:org_a', maxRequests: 1);
        $endpointLimit = $this->makeLimit(
            key: 'endpoint:org_a:read_items',
            type: 'per_endpoint',
            name: 'read_items',
            maxRequests: 1,
        );
        $now = 1_700_000_000;

        $this->counter->attempt($clientLimit, now: $now);
        $clientRejected = $this->counter->attempt($clientLimit, now: $now);

        $endpointAllowed = $this->counter->attempt($endpointLimit, now: $now);

        $this->assertFalse($clientRejected->allowed);
        $this->assertTrue($endpointAllowed->allowed);
        $this->assertSame(2, $this->counter->entryCount());
    }

    public function test_boundary_burst_allows_double_limit_across_windows(): void
    {
        $limit = $this->makeLimit(maxRequests: 2, windowSeconds: 60);
        $windowOneEnd = 1_700_000_039;
        $windowTwoStart = 1_700_000_040;

        $this->counter->attempt($limit, now: $windowOneEnd);
        $this->counter->attempt($limit, now: $windowOneEnd);

        $rejectedEndOfWindow = $this->counter->attempt($limit, now: $windowOneEnd);
        $this->assertFalse($rejectedEndOfWindow->allowed);

        $allowedNewWindow = $this->counter->attempt($limit, now: $windowTwoStart);
        $this->assertTrue($allowedNewWindow->allowed);
        $this->assertSame(1, $allowedNewWindow->currentCount);
    }

    public function test_reset_clears_store(): void
    {
        $limit = $this->makeLimit(maxRequests: 100);

        $this->counter->attempt($limit, now: 1_700_000_000);
        $this->assertSame(1, $this->counter->entryCount());

        $this->counter->reset();

        $this->assertSame(0, $this->counter->entryCount());
    }

    private function makeLimit(
        string $key = 'client:org_acme',
        string $type = 'per_client',
        string $name = 'standard',
        int $maxRequests = 100,
        int $windowSeconds = 60,
    ): ResolvedLimit {
        return new ResolvedLimit(
            key: $key,
            type: $type,
            name: $name,
            maxRequests: $maxRequests,
            windowSeconds: $windowSeconds,
        );
    }
}
