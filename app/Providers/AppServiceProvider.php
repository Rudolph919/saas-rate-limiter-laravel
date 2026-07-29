<?php

namespace App\Providers;

use App\Services\RateLimiting\RateLimitCounter;
use App\Services\RateLimiting\Stores\ArrayStore;
use App\Services\RateLimiting\Stores\CounterStore;
use App\Services\RateLimiting\Stores\SharedMemoryStore;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CounterStore::class, fn () => $this->makeCounterStore());

        $this->app->singleton(RateLimitCounter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Build the configured counter store.
     *
     * Note that "singleton" here means "one per container", and the container is rebuilt on
     * every request. That is precisely why the store itself has to hold state somewhere that
     * outlives the container — see CounterStore.
     */
    private function makeCounterStore(): CounterStore
    {
        $config = config('rate_limits.store');
        $driver = $config['driver'] ?? 'auto';

        if ($driver === 'auto') {
            $driver = $this->sharedMemoryAvailable() ? 'shared_memory' : 'array';
        }

        return match ($driver) {
            'shared_memory' => $this->makeSharedMemoryStore($config['shared_memory'] ?? []),
            'array' => new ArrayStore,
            default => throw new RuntimeException("Unknown rate limiter store driver [{$driver}]."),
        };
    }

    /**
     * @param  array{project_id?: string, segment_bytes?: int}  $config
     */
    private function makeSharedMemoryStore(array $config): SharedMemoryStore
    {
        if (! $this->sharedMemoryAvailable()) {
            throw new RuntimeException(
                'The shared_memory rate limiter store requires the sysvshm and sysvsem extensions.'
            );
        }

        // ftok() derives a stable System V key from a real path, so every worker for this
        // install attaches to the same segment, and a second install elsewhere on the host
        // gets its own.
        $systemVKey = ftok(base_path('artisan'), $config['project_id'] ?? 'r');

        if ($systemVKey === -1) {
            throw new RuntimeException('Unable to derive a System V key for the rate limiter store.');
        }

        return new SharedMemoryStore(
            systemVKey: $systemVKey,
            segmentBytes: $config['segment_bytes'] ?? 1048576,
        );
    }

    private function sharedMemoryAvailable(): bool
    {
        return extension_loaded('sysvshm') && extension_loaded('sysvsem');
    }
}
