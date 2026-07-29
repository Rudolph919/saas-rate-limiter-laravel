<?php

namespace App\Services\RateLimiting\Stores;

/**
 * Backing store for fixed-window counters.
 *
 * The one operation that matters is increment(): "add 1 to the counter for this key in this
 * window, and tell me the new value" — as a single atomic step. Splitting it into a read and a
 * write would let two concurrent requests both read N and both write N+1, quietly handing out
 * one extra request per race.
 *
 * This interface exists because the original implementation stored counters in a plain PHP
 * array on a service-container singleton. That is invisible in tests — PHPUnit runs a whole
 * test method in one process with one booted container, so the array survives between calls —
 * but over real HTTP, Laravel rebuilds the container on every request, so the array was empty
 * every time and no limit was ever enforced. Moving the state behind this interface makes the
 * lifetime an explicit, testable choice rather than an accident of the runtime.
 *
 * Migration path: a RedisStore implements increment() as INCR + EXPIRE (or a small Lua script
 * to make the pair atomic), and nothing above this interface changes.
 */
interface CounterStore
{
    /**
     * Atomically increment the counter for $key within the window starting at $windowStart,
     * returning the new count.
     *
     * @param  string  $key           Limit key, e.g. "client:org_acme"
     * @param  int     $windowStart   Epoch second the current window began
     * @param  int     $ttlSeconds    Window length; the entry is dead after $windowStart + $ttlSeconds
     * @param  int     $now           Current epoch second (injected so expiry is testable)
     * @return int                    Count for this key in this window, after the increment
     */
    public function increment(string $key, int $windowStart, int $ttlSeconds, int $now): int;

    /**
     * Drop all counters. Used between tests and by the demo script.
     */
    public function reset(): void;

    /**
     * Number of live (key, window) entries — the signal to watch for unbounded memory growth.
     */
    public function entryCount(): int;
}
