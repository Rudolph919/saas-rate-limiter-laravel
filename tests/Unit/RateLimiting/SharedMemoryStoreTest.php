<?php

namespace Tests\Unit\RateLimiting;

use App\Services\RateLimiting\Stores\ArrayStore;
use App\Services\RateLimiting\Stores\SharedMemoryStore;
use Tests\TestCase;

/**
 * The regression suite for the bug that made the original limiter inert.
 *
 * The original store kept counters in a property on a container-bound singleton. Every existing
 * test passed, because PHPUnit runs a whole test method in one process against one booted
 * container — so the object, and its counters, stayed alive between calls. Over real HTTP the
 * container is rebuilt per request, the object is new every time, and no limit was ever hit.
 *
 * The missing assertion was never about counting. It was about lifetime: does the state outlive
 * the object holding it? These tests answer that directly by throwing the store away and
 * building a new one, which is exactly what a second HTTP request does.
 */
class SharedMemoryStoreTest extends TestCase
{
    private const WINDOW = 60;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('sysvshm') || ! extension_loaded('sysvsem')) {
            $this->markTestSkipped('Requires the sysvshm and sysvsem extensions.');
        }

        $this->makeStore()->reset();
    }

    protected function tearDown(): void
    {
        if (extension_loaded('sysvshm') && extension_loaded('sysvsem')) {
            $this->makeStore()->reset();
        }

        parent::tearDown();
    }

    public function test_counts_survive_a_brand_new_store_instance(): void
    {
        $now = 1_700_000_000;
        $windowStart = intdiv($now, self::WINDOW) * self::WINDOW;

        // Request 1: a store instance is built, used, and thrown away.
        $first = $this->makeStore()->increment('client:org_acme', $windowStart, self::WINDOW, $now);

        // Request 2: a completely separate instance, as if the container had been rebuilt.
        $second = $this->makeStore()->increment('client:org_acme', $windowStart, self::WINDOW, $now);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second, 'Counters must outlive the object that wrote them.');
    }

    public function test_array_store_loses_counts_across_instances(): void
    {
        $now = 1_700_000_000;
        $windowStart = intdiv($now, self::WINDOW) * self::WINDOW;

        $first = (new ArrayStore)->increment('client:org_acme', $windowStart, self::WINDOW, $now);
        $second = (new ArrayStore)->increment('client:org_acme', $windowStart, self::WINDOW, $now);

        // This is the original bug, pinned as an executable fact: every request starts at 1,
        // so a limit of 100 is never reached no matter how much traffic arrives.
        $this->assertSame(1, $first);
        $this->assertSame(1, $second);
    }

    public function test_separate_keys_do_not_share_a_counter(): void
    {
        $now = 1_700_000_000;
        $windowStart = intdiv($now, self::WINDOW) * self::WINDOW;
        $store = $this->makeStore();

        $store->increment('client:org_a', $windowStart, self::WINDOW, $now);
        $store->increment('client:org_a', $windowStart, self::WINDOW, $now);
        $other = $store->increment('client:org_b', $windowStart, self::WINDOW, $now);

        $this->assertSame(1, $other);
        $this->assertSame(2, $store->entryCount());
    }

    public function test_counter_restarts_in_the_next_window(): void
    {
        $windowOne = 1_699_999_980;
        $windowTwo = 1_700_000_040;
        $store = $this->makeStore();

        $store->increment('client:org_acme', $windowOne, self::WINDOW, $windowOne);
        $store->increment('client:org_acme', $windowOne, self::WINDOW, $windowOne);

        $rolled = $store->increment('client:org_acme', $windowTwo, self::WINDOW, $windowTwo);

        $this->assertSame(1, $rolled);
    }

    public function test_expired_entries_are_pruned(): void
    {
        $windowOne = 1_699_999_980;
        $windowTwo = 1_700_000_040;
        $store = $this->makeStore();

        $store->increment('client:org_acme', $windowOne, self::WINDOW, $windowOne);
        $this->assertSame(1, $store->entryCount());

        // Writing in a later window evicts the stale entry rather than accumulating it —
        // the memory-growth mitigation, asserted rather than assumed.
        $store->increment('client:org_globex', $windowTwo, self::WINDOW, $windowTwo);

        $this->assertSame(1, $store->entryCount());
    }

    public function test_reset_clears_the_segment(): void
    {
        $now = 1_700_000_000;
        $windowStart = intdiv($now, self::WINDOW) * self::WINDOW;
        $store = $this->makeStore();

        $store->increment('client:org_acme', $windowStart, self::WINDOW, $now);
        $this->assertSame(1, $store->entryCount());

        $store->reset();

        $this->assertSame(0, $store->entryCount());
    }

    /**
     * A dedicated segment for tests, so a run never disturbs counters held by a locally
     * running dev server.
     */
    private function makeStore(): SharedMemoryStore
    {
        return new SharedMemoryStore(
            systemVKey: ftok(base_path('artisan'), 't'),
            segmentBytes: 65536,
        );
    }
}
